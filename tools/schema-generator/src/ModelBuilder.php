<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tools\SchemaGenerator;

use Mcp\Exception\LogicException;
use Mcp\Tools\SchemaGenerator\Model\PropertyDefinition;
use Mcp\Tools\SchemaGenerator\Model\TypeDefinition;
use Mcp\Tools\SchemaGenerator\Model\TypeRef;

/**
 * Merges the configured definitions of all supported revisions into one set of PHP types.
 *
 * @phpstan-type Fragment array<string, mixed>
 * @phpstan-type GeneratorConfig array{
 *     revisions: list<string>,
 *     spec_dir: string,
 *     namespace: string,
 *     directory: string,
 *     types: array<string, string>,
 *     inline_enums: array<string, string>,
 *     maps: list<string>,
 *     any: list<string>,
 *     omit: list<string>,
 *     order: array<string, list<string>>,
 *     traits: array<string, list<class-string>>,
 *     transforms: array<string, string|array{string, string}>,
 *     implements: array<string, list<class-string>>,
 *     validators: array<string, list<string>>,
 *     phpstan_types: array<string, array<string, string>>,
 *     defaults: array<string, string|int|float|bool>,
 *     always_emit: list<string>,
 *     discriminator_property: bool,
 * }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ModelBuilder
{
    private const SCALAR_TYPES = ['string' => TypeRef::STRING, 'integer' => TypeRef::INT, 'number' => TypeRef::FLOAT, 'boolean' => TypeRef::BOOL];

    /** @var array<string, TypeDefinition> */
    private array $types = [];

    /** @var list<string> */
    private array $methods = [];

    /**
     * @param GeneratorConfig                        $config
     * @param array<string, array<string, Fragment>> $definitions  definitions keyed by revision, then by name
     * @param array<string, string>                  $deprecations notices keyed by "Type" or "Type.property"
     */
    public function __construct(
        private readonly array $config,
        private readonly array $definitions,
        private readonly array $deprecations,
    ) {
    }

    /**
     * @return array<string, TypeDefinition> keyed by spec name
     */
    public function build(): array
    {
        $this->types = [];
        $this->methods = $this->collectMethods();

        foreach ($this->config['types'] as $specName => $class) {
            $this->types[$specName] = $this->buildType($specName, $class);
        }

        $this->assertReferencesResolve();
        ksort($this->types);

        return $this->types;
    }

    private function buildType(string $specName, string $class): TypeDefinition
    {
        $revisions = $this->revisionsDefining($specName);
        if ([] === $revisions) {
            throw new LogicException(\sprintf('Type "%s" is not defined in any configured revision.', $specName));
        }

        $latest = $this->definitions[$revisions[\count($revisions) - 1]][$specName];

        if (isset($latest['enum'])) {
            $values = $this->mergedEnumValues(array_map(
                fn (string $revision): array => $this->definitions[$revision][$specName],
                $revisions,
            ));

            return $this->typeDefinition($specName, $class, TypeDefinition::ENUM, $latest, $revisions, [], $values, []);
        }

        if (isset($latest['anyOf'])) {
            $members = [];
            foreach ($revisions as $revision) {
                foreach ($this->refNames($this->definitions[$revision][$specName]['anyOf'], $specName) as $member) {
                    $members[$member] = true;
                }
            }

            return $this->typeDefinition($specName, $class, TypeDefinition::UNION, $latest, $revisions, [], [], array_keys($members));
        }

        if ('object' !== ($latest['type'] ?? null)) {
            throw new LogicException(\sprintf('Type "%s" is neither an object, an enum nor a union of references.', $specName));
        }

        return $this->typeDefinition($specName, $class, TypeDefinition::OBJECT, $latest, $revisions, $this->mergedProperties($specName, $revisions), [], []);
    }

    /**
     * @param Fragment                 $latest
     * @param list<string>             $revisions
     * @param list<PropertyDefinition> $properties
     * @param list<string>             $values
     * @param list<string>             $members
     */
    private function typeDefinition(string $specName, string $class, string $kind, array $latest, array $revisions, array $properties, array $values, array $members): TypeDefinition
    {
        return new TypeDefinition(
            specName: $specName,
            class: $class,
            kind: $kind,
            description: $this->description($latest),
            deprecated: $this->deprecations[$specName] ?? null,
            revisions: $revisions === $this->config['revisions'] ? [] : $revisions,
            properties: $properties,
            values: $values,
            members: $members,
            traits: $this->config['traits'][$specName] ?? [],
            implements: $this->config['implements'][$specName] ?? [],
            validators: $this->config['validators'][$specName] ?? [],
            phpstanTypes: $this->config['phpstan_types'][$specName] ?? [],
        );
    }

    /**
     * @param list<string> $revisions
     *
     * @return list<PropertyDefinition>
     */
    private function mergedProperties(string $specName, array $revisions): array
    {
        $names = [];
        foreach (array_reverse($revisions) as $revision) {
            foreach (array_keys($this->definitions[$revision][$specName]['properties'] ?? []) as $name) {
                $names[$name] = true;
            }
        }

        $properties = [];
        foreach (array_map('strval', array_keys($names)) as $wireName) {
            $path = $specName.'.'.$wireName;
            if (\in_array($path, $this->config['omit'], true)) {
                continue;
            }

            $fragments = [];
            $required = true;
            foreach ($revisions as $revision) {
                $definition = $this->definitions[$revision][$specName];
                if (!isset($definition['properties'][$wireName])) {
                    $required = false;
                    continue;
                }
                $fragments[$revision] = $definition['properties'][$wireName];
                $required = $required && \in_array($wireName, $definition['required'] ?? [], true);
            }

            $known = array_keys($fragments);
            $transform = $this->config['transforms'][$path] ?? null;
            $properties[] = new PropertyDefinition(
                wireName: $wireName,
                phpName: $this->phpName($wireName, $path),
                type: $this->typeRef(array_values($fragments), $path),
                required: $required,
                deprecated: $this->deprecations[$path] ?? null,
                revisions: $known === $revisions ? [] : $known,
                transform: \is_array($transform) ? $transform[0] : $transform,
                transformParamType: \is_array($transform) ? $transform[1] : null,
                hasDefault: \array_key_exists($path, $this->config['defaults']),
                default: $this->config['defaults'][$path] ?? null,
                alwaysEmit: \in_array($path, $this->config['always_emit'], true),
            );
        }

        return $this->ordered($specName, $properties);
    }

    /**
     * Constructor parameter order is public API (positional calls), so it is pinned in "order" once released;
     * otherwise required properties come first and "_meta" last. Optional properties ordered before a required
     * one become nullable parameters without default.
     *
     * @param list<PropertyDefinition> $properties
     *
     * @return list<PropertyDefinition>
     */
    private function ordered(string $specName, array $properties): array
    {
        $byName = [];
        foreach ($properties as $property) {
            $byName[$property->wireName] = $property;
        }

        $ordered = [];
        foreach ($this->config['order'][$specName] ?? [] as $wireName) {
            if (!isset($byName[$wireName])) {
                throw new LogicException(\sprintf('"order" of "%s" lists unknown property "%s".', $specName, $wireName));
            }
            $ordered[] = $byName[$wireName];
            unset($byName[$wireName]);
        }

        $rest = array_values($byName);
        $rank = static fn (PropertyDefinition $p): int => $p->required ? 0 : ('_meta' === $p->wireName ? 2 : 1);
        usort($rest, static fn (PropertyDefinition $a, PropertyDefinition $b): int => $rank($a) <=> $rank($b));
        $ordered = [...$ordered, ...$rest];

        return $ordered;
    }

    /**
     * @param list<Fragment> $fragments the same fragment across revisions, oldest first
     */
    private function typeRef(array $fragments, string $path): TypeRef
    {
        $fragment = $fragments[\count($fragments) - 1];

        if (isset($this->config['inline_enums'][$path])) {
            if (!isset($fragment['enum'])) {
                throw new LogicException(\sprintf('"%s" is configured as inline enum but has no "enum".', $path));
            }
            $this->types[$path] = new TypeDefinition(
                specName: $path,
                class: $this->config['inline_enums'][$path],
                kind: TypeDefinition::ENUM,
                values: $this->mergedEnumValues($fragments),
            );

            return TypeRef::ref($path);
        }

        if (isset($fragment['$ref'])) {
            return $this->resolveRef($this->refName($fragment['$ref']), $path);
        }

        if (isset($fragment['anyOf'])) {
            $listOf = null;
            $anyOf = [];
            foreach ($fragment['anyOf'] as $alternative) {
                if (null === $listOf && 'array' === ($alternative['type'] ?? null) && isset($alternative['items']['$ref'])) {
                    $listOf = $this->typeRef([$alternative['items']], $path.'[]');
                    continue;
                }
                $anyOf[] = $alternative;
            }
            $members = $this->refNames($anyOf, $path, false);
            if (null === $members) {
                return TypeRef::mixed();
            }
            if (null !== $listOf) {
                return TypeRef::union($members, $listOf);
            }
            foreach ($members as $member) {
                if (\in_array($member, $this->config['any'], true)) {
                    return TypeRef::mixed();
                }
            }

            return TypeRef::union($members);
        }

        if (\array_key_exists('const', $fragment)) {
            return TypeRef::constant($fragment['const']);
        }

        if (isset($fragment['enum'])) {
            return TypeRef::stringEnum($this->mergedEnumValues($fragments));
        }

        $type = $fragment['type'] ?? null;
        if (null === $type) {
            return TypeRef::mixed();
        }

        if (\is_array($type)) {
            $scalars = [];
            foreach ($type as $member) {
                if (!isset(self::SCALAR_TYPES[$member])) {
                    return TypeRef::mixed();
                }
                $scalars[] = self::SCALAR_TYPES[$member];
            }

            return TypeRef::scalars($scalars);
        }

        if (isset(self::SCALAR_TYPES[$type])) {
            return TypeRef::scalar(self::SCALAR_TYPES[$type]);
        }

        return match ($type) {
            'object' => TypeRef::map(),
            'array' => isset($fragment['items'])
                ? TypeRef::listOf($this->typeRef(array_values(array_filter(array_map(
                    static fn (array $f): mixed => $f['items'] ?? null,
                    $fragments,
                ))), $path.'[]'))
                : TypeRef::listOf(TypeRef::mixed()),
            default => throw new LogicException(\sprintf('Unsupported type "%s" at "%s".', $type, $path)),
        };
    }

    private function resolveRef(string $name, string $path): TypeRef
    {
        if (\in_array($name, $this->config['maps'], true)) {
            return TypeRef::map();
        }
        if (\in_array($name, $this->config['any'], true)) {
            return TypeRef::mixed();
        }
        if (isset($this->config['types'][$name])) {
            return TypeRef::ref($name);
        }

        $revisions = $this->revisionsDefining($name);
        if ([] !== $revisions) {
            $definition = $this->definitions[$revisions[\count($revisions) - 1]][$name];
            $type = $definition['type'] ?? null;
            $isScalar = \is_string($type) ? isset(self::SCALAR_TYPES[$type]) : \is_array($type);
            if ($isScalar && !isset($definition['enum'])) {
                return $this->typeRef([$definition], $path);
            }
        }

        throw new LogicException(\sprintf('"%s" references "%s", which is not configured in "types", "maps" or "any".', $path, $name));
    }

    private function assertReferencesResolve(): void
    {
        foreach ($this->types as $type) {
            $references = $type->members;
            foreach ($type->properties as $property) {
                $references = [...$references, ...$property->type->referencedTypes()];
            }
            foreach ($references as $reference) {
                if (!isset($this->types[$reference])) {
                    throw new LogicException(\sprintf('"%s" references "%s", which is not configured in "types".', $type->specName, $reference));
                }
            }
            if (TypeDefinition::UNION === $type->kind) {
                foreach ($type->members as $member) {
                    if (TypeDefinition::OBJECT !== $this->types[$member]->kind) {
                        throw new LogicException(\sprintf('Union "%s" has non-object member "%s".', $type->specName, $member));
                    }
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function revisionsDefining(string $name): array
    {
        return array_values(array_filter(
            $this->config['revisions'],
            fn (string $revision): bool => isset($this->definitions[$revision][$name]),
        ));
    }

    /**
     * @param list<Fragment> $fragments
     *
     * @return list<string>
     */
    private function mergedEnumValues(array $fragments): array
    {
        $values = [];
        foreach (array_reverse($fragments) as $fragment) {
            foreach ($fragment['enum'] ?? [] as $value) {
                $values[(string) $value] = true;
            }
        }

        return array_map('strval', array_keys($values));
    }

    /**
     * @param list<Fragment> $anyOf
     *
     * @return ($strict is true ? list<string> : list<string>|null)
     */
    private function refNames(array $anyOf, string $path, bool $strict = true): ?array
    {
        $names = [];
        foreach ($anyOf as $member) {
            if (!isset($member['$ref'])) {
                if ($strict) {
                    throw new LogicException(\sprintf('Union "%s" has a member that is not a reference.', $path));
                }

                return null;
            }
            $names[] = $this->refName($member['$ref']);
        }

        return $names;
    }

    private function refName(mixed $ref): string
    {
        if (!\is_string($ref)) {
            throw new LogicException('Invalid "$ref".');
        }
        $position = strrpos($ref, '/');

        return false === $position ? $ref : substr($ref, $position + 1);
    }

    private function phpName(string $wireName, string $path): string
    {
        $name = lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^A-Za-z0-9]+/', ' ', $wireName) ?? '')));
        if ('' === $name || !preg_match('/^[a-zA-Z_]\w*$/', $name)) {
            throw new LogicException(\sprintf('Cannot derive a PHP property name for "%s".', $path));
        }

        return $name;
    }

    /**
     * @param Fragment $definition
     */
    private function description(array $definition): string
    {
        $description = \is_string($definition['description'] ?? null) ? $definition['description'] : '';
        $paragraph = trim(explode("\n\n", $description)[0]);

        $paragraph = preg_replace_callback('/\{@link\s+([^}]+)\}/', function (array $match): string {
            foreach ($this->methods as $method) {
                if (str_ends_with($match[1], $method)) {
                    return '`'.$method.'`';
                }
            }

            return trim(explode('|', $match[1])[0]);
        }, $paragraph) ?? $paragraph;

        return str_replace('*/', '*\/', $paragraph);
    }

    /**
     * @return list<string>
     */
    private function collectMethods(): array
    {
        $methods = [];
        foreach ($this->definitions as $definitions) {
            foreach ($definitions as $definition) {
                $method = $definition['properties']['method']['const'] ?? null;
                if (\is_string($method)) {
                    $methods[$method] = true;
                }
            }
        }

        $methods = array_keys($methods);
        usort($methods, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $methods;
    }
}
