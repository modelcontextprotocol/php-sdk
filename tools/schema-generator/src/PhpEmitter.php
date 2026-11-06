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
 * Renders the merged model as PHP source; formatting is left to php-cs-fixer.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PhpEmitter
{
    public const MARKER = 'Generated from the MCP specification by tools/schema-generator, do not edit.';

    private const HEADER = <<<'PHP'
        <?php

        /*
         * This file is part of the official PHP MCP SDK.
         *
         * A collaboration between Symfony and the PHP Foundation.
         *
         * For the full copyright and license information, please view the LICENSE
         * file that was distributed with this source code.
         */
        PHP;

    private const EXCEPTION = 'Mcp\\Exception\\InvalidArgumentException';

    /** @var array<string, string> FQCN => alias, for the file being rendered */
    private array $uses = [];

    /** @var array<string, string> phpstan type alias => class it is imported from, for the file being rendered */
    private array $typeImports = [];

    private string $currentNamespace = '';

    private string $currentSpecName = '';

    /** @var array<string, list<string>> object spec name => unions it belongs to */
    private array $unionsOf = [];

    /**
     * @param array<string, TypeDefinition> $types                   keyed by spec name
     * @param list<string>                  $revisions               all supported revisions, oldest first
     * @param bool                          $discriminatorProperties expose constant discriminators as readonly properties too
     */
    public function __construct(
        private readonly array $types,
        private readonly string $namespace,
        private readonly array $revisions,
        private readonly bool $discriminatorProperties = false,
    ) {
        foreach ($types as $type) {
            foreach ($type->members as $member) {
                $this->unionsOf[$member][] = $type->specName;
            }
        }
    }

    /**
     * @return array<string, string> source keyed by path relative to the output directory
     */
    public function emit(): array
    {
        $files = [];
        foreach ($this->types as $type) {
            $path = str_replace('\\', '/', $type->class);
            $files[$path.'.php'] = $this->render($type, match ($type->kind) {
                TypeDefinition::ENUM => $this->enumBody($type),
                TypeDefinition::UNION => $this->interfaceBody($type),
                default => $this->classBody($type),
            });
            if (TypeDefinition::UNION === $type->kind) {
                $factory = new TypeDefinition($type->specName, $type->class.'Factory', TypeDefinition::OBJECT);
                $files[$path.'Factory.php'] = $this->render($factory, $this->factoryBody($type));
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param \Closure(): string $body
     */
    private function render(TypeDefinition $type, \Closure $body): string
    {
        $this->uses = [];
        $this->typeImports = [];
        $this->currentSpecName = $type->specName;
        $this->currentNamespace = rtrim($this->namespace.'\\'.$type->namespaceSuffix(), '\\');
        $code = $body();

        $uses = array_keys($this->uses);
        sort($uses);
        $imports = [] === $uses ? '' : implode("\n", array_map(static fn (string $use): string => 'use '.$use.';', $uses))."\n\n";

        return self::HEADER."\n\nnamespace {$this->currentNamespace};\n\n{$imports}{$code}";
    }

    /**
     * @return \Closure(): string
     */
    private function enumBody(TypeDefinition $type): \Closure
    {
        return function () use ($type): string {
            $cases = '';
            foreach ($type->values as $value) {
                $cases .= \sprintf("    case %s = %s;\n", $this->caseName($value), var_export($value, true));
            }

            return $this->classDoc($type, [])."enum {$type->shortName()}: string\n{\n{$cases}}\n";
        };
    }

    /**
     * @return \Closure(): string
     */
    private function interfaceBody(TypeDefinition $type): \Closure
    {
        return function () use ($type): string {
            $extends = ['\\JsonSerializable', ...array_map(fn (string $interface): string => $this->importClass($interface), $type->implements)];
            if ([] !== $type->implements) {
                array_shift($extends);
            }
            $data = implode('|', array_map(fn (string $member): string => $this->dataAlias($member), $type->members));
            $docs = ["@phpstan-type {$type->shortName()}Data {$data}"];

            return $this->classDoc($type, $docs)."interface {$type->shortName()} extends ".implode(', ', $extends)."\n{\n}\n";
        };
    }

    /**
     * @return \Closure(): string
     */
    private function factoryBody(TypeDefinition $union): \Closure
    {
        return function () use ($union): string {
            $interface = $this->import($union->specName);
            $exception = $this->importClass(self::EXCEPTION);
            $body = $this->dispatch($union->members, '$data', 'return %s;', "throw new {$exception}('Unsupported {$union->specName} data.');");

            return <<<PHP
                /**
                 * Decodes {$interface} data into the matching implementation.
                 *
                 * {$this->marker()}
                 */
                final class {$union->shortName()}Factory
                {
                    /**
                     * @param array<mixed> \$data
                     */
                    public static function fromArray(array \$data): {$interface}
                    {
                {$this->indent($body, 2)}
                    }
                }

                PHP;
        };
    }

    /**
     * @return \Closure(): string
     */
    private function classBody(TypeDefinition $type): \Closure
    {
        return function () use ($type): string {
            $implements = [
                ...array_map(fn (string $union): string => $this->import($union), $this->unionsOf[$type->specName] ?? []),
                ...array_map(fn (string $interface): string => $this->importClass($interface), $type->implements),
            ];
            $implements = [] === $implements ? '\\JsonSerializable' : implode(', ', $implements);

            $members = [];
            foreach ($type->traits as $trait) {
                $members[] = '    use '.$this->importClass($trait).';';
            }

            $lastRequired = -1;
            foreach ($type->properties as $index => $property) {
                if ($property->required && TypeRef::CONST !== $property->type->kind) {
                    $lastRequired = $index;
                }
            }

            $constants = [];
            $parameters = [];
            $paramDocs = [];
            $declarations = [];
            $body = [];
            foreach ($type->properties as $index => $property) {
                if (TypeRef::CONST === $property->type->kind) {
                    $constant = $this->constantName($property);
                    $constants[] = \sprintf('    public const %s = %s;', $constant, var_export($property->type->const, true));
                    if ($this->discriminatorProperties) {
                        $declarations[] = \sprintf('    public readonly %s $%s;', get_debug_type($property->type->const), $property->phpName);
                        $body[] = "\$this->{$property->phpName} = self::{$constant};";
                    }
                    continue;
                }

                $parameters[] = $this->parameter($property, $index > $lastRequired);
                if (null !== $property->transform) {
                    $native = $this->propertyNativeType($property);
                    $docType = $this->docType($property->type).($this->isNullable($property) ? '|null' : '');
                    $declarations[] = ($docType !== $native ? "    /** @var {$docType} */\n" : '')."    public readonly {$native} \${$property->phpName};";
                    $call = "self::{$property->transform}(\${$property->phpName})";
                    $body[] = $this->isNullable($property)
                        ? "\$this->{$property->phpName} = null === \${$property->phpName} ? null : {$call};"
                        : "\$this->{$property->phpName} = {$call};";
                } else {
                    $body = [...$body, ...$this->validation($property, $type)];
                }

                $doc = $this->paramDoc($property, $type);
                if (null !== $doc) {
                    $paramDocs[] = $doc;
                }
            }
            foreach ($type->validators as $validator) {
                $body[] = "\$this->{$validator}();";
            }

            if ([] !== $constants) {
                $members[] = implode("\n", $constants);
            }
            if ([] !== $declarations) {
                $members[] = implode("\n\n", $declarations);
            }

            $constructorDoc = [] === $paramDocs ? '' : "    /**\n".implode('', array_map(static fn (string $doc): string => "     * {$doc}\n", $paramDocs))."     */\n";
            $constructorBody = [] === $body ? '' : $this->indent(implode("\n", $body), 2)."\n";
            $members[] = $constructorDoc."    public function __construct(\n".implode('', array_map(static fn (string $p): string => "        {$p},\n", $parameters))."    ) {\n{$constructorBody}    }";
            $members[] = $this->fromArrayMethod($type);
            $members[] = $this->jsonSerializeMethod($type);

            $docs = [];
            foreach ($type->phpstanTypes as $alias => $definition) {
                $docs[] = "@phpstan-type {$alias} {$definition}";
            }
            $docs[] = $this->dataShape($type);

            return $this->classDoc($type, $docs)."final class {$type->shortName()} implements {$implements}\n{\n".implode("\n\n", $members)."\n}\n";
        };
    }

    private function parameter(PropertyDefinition $property, bool $isTrailing): string
    {
        $modifiers = null === $property->transform ? 'public readonly ' : '';
        $native = $property->transformParamType ?? $this->propertyNativeType($property);

        $default = '';
        if ($property->hasDefault) {
            $default = ' = '.var_export($property->default, true);
        } elseif (!$property->required && $isTrailing) {
            $default = ' = null';
        }

        return "{$modifiers}{$native} \${$property->phpName}{$default}";
    }

    private function isNullable(PropertyDefinition $property): bool
    {
        return !$property->required && !$property->hasDefault;
    }

    private function propertyNativeType(PropertyDefinition $property): string
    {
        $native = $this->nativeType($property->type);

        return $this->isNullable($property) ? $this->nullable($native) : $native;
    }

    private function paramDoc(PropertyDefinition $property, TypeDefinition $owner): ?string
    {
        $notes = [];
        if ([] !== $property->revisions) {
            $notes[] = $this->revisionNote($property->revisions, [] === $owner->revisions ? $this->revisions : $owner->revisions);
        }
        if (null !== $property->deprecated) {
            $notes[] = 'deprecated: '.$this->deprecationText($property->deprecated);
        }

        $docType = $this->docType($property->type);
        $needsType = $docType !== $this->nativeType($property->type);
        if (!$needsType && [] === $notes) {
            return null;
        }

        if ($this->isNullable($property) && 'mixed' !== $docType) {
            $docType .= '|null';
        }
        if (null !== $property->transformParamType) {
            $docType = $property->transformParamType;
        }

        return rtrim(\sprintf('@param %s $%s %s', $docType, $property->phpName, implode('; ', $notes)));
    }

    /**
     * Constructor checks for what the native type cannot express: the items of lists, and maps given as lists.
     *
     * @return list<string>
     */
    private function validation(PropertyDefinition $property, TypeDefinition $owner): array
    {
        $type = $property->type;
        $value = '$this->'.$property->phpName;
        if (TypeRef::MAP === $type->kind) {
            $exception = $this->importClass(self::EXCEPTION);
            $guard = $this->isNullable($property) ? "null !== {$value} && " : '';

            return [
                "if ({$guard}[] !== {$value} && array_is_list({$value})) {",
                "    throw new {$exception}('{$owner->shortName()} \"{$property->wireName}\" must be a map, not a list.');",
                '}',
            ];
        }
        $items = match ($type->kind) {
            TypeRef::LIST, TypeRef::UNION => $type->items,
            default => null,
        };
        $condition = null === $items ? null : $this->instanceCheck($items, '$item');
        if (null === $condition) {
            return [];
        }

        $exception = $this->importClass(self::EXCEPTION);
        $lines = [
            "foreach ({$value} as \$item) {",
            "    if (!({$condition})) {",
            "        throw new {$exception}('Invalid \"{$property->wireName}\" item in {$owner->shortName()}.');",
            '    }',
            '}',
        ];

        if (TypeRef::UNION === $type->kind) {
            return ["if (\\is_array({$value})) {", ...$this->indentLines($lines), '}'];
        }
        if ($this->isNullable($property)) {
            return ["if (null !== {$value}) {", ...$this->indentLines($lines), '}'];
        }

        return $lines;
    }

    private function instanceCheck(TypeRef $type, string $variable): ?string
    {
        return match ($type->kind) {
            TypeRef::STRING, TypeRef::INT, TypeRef::BOOL => "\\is_{$type->kind}({$variable})",
            TypeRef::FLOAT => "\\is_float({$variable}) || \\is_int({$variable})",
            TypeRef::REF => "{$variable} instanceof {$this->import((string) $type->name)}",
            TypeRef::UNION => implode(' || ', array_map(fn (string $member): string => "{$variable} instanceof {$this->import($member)}", $type->members)),
            default => null,
        };
    }

    private function fromArrayMethod(TypeDefinition $type): string
    {
        $exception = $this->importClass(self::EXCEPTION);
        $owner = $type->shortName();
        $lines = [];
        $arguments = [];

        foreach ($type->properties as $property) {
            $key = var_export($property->wireName, true);
            $source = "\$data[{$key}]";

            if (TypeRef::CONST === $property->type->kind) {
                $constant = 'self::'.$this->constantName($property);
                $lines[] = "if (isset({$source}) && {$constant} !== {$source}) {";
                $lines[] = "    throw new {$exception}('Invalid \"{$property->wireName}\" in {$owner} data.');";
                $lines[] = '}';
                continue;
            }

            $variable = '$'.('data' === $property->phpName ? 'dataValue' : $property->phpName);
            $arguments[] = "{$property->phpName}: {$variable},";
            $fallback = $property->hasDefault ? var_export($property->default, true) : 'null';

            if (TypeRef::MIXED === $property->type->kind) {
                $lines[] = $property->required
                    ? "{$variable} = \\array_key_exists({$key}, \$data) ? {$source} : throw new {$exception}('Missing \"{$property->wireName}\" in {$owner} data.');"
                    : "{$variable} = {$source} ?? {$fallback};";
                continue;
            }

            $fail = "throw new {$exception}('Invalid \"{$property->wireName}\" in {$owner} data.');";
            $decode = $this->decode($property->type, $source, $variable, $fail, 0);

            if ($property->required) {
                $lines[] = "if (!isset({$source})) {";
                $lines[] = "    throw new {$exception}('Missing \"{$property->wireName}\" in {$owner} data.');";
                $lines[] = '}';
                $lines = [...$lines, ...$decode];
            } else {
                $lines[] = "{$variable} = {$fallback};";
                $lines[] = "if (isset({$source})) {";
                $lines = [...$lines, ...$this->indentLines($decode)];
                $lines[] = '}';
            }
        }

        $lines[] = '';
        $lines[] = [] === $arguments ? 'return new self();' : "return new self(\n".implode("\n", $this->indentLines($arguments))."\n);";

        return <<<PHP
                /**
                 * @param array<mixed> \$data
                 */
                public static function fromArray(array \$data): self
                {
            {$this->indent(implode("\n", $lines), 2)}
                }
            PHP;
    }

    /**
     * Statements that validate $source and assign its decoded value to $target.
     *
     * @return list<string>
     */
    private function decode(TypeRef $type, string $source, string $target, string $fail, int $depth): array
    {
        $check = static fn (string $condition, string $value): array => ["if ({$condition}) {", "    {$fail}", '}', "{$target} = {$value};"];

        switch ($type->kind) {
            case TypeRef::STRING:
            case TypeRef::INT:
            case TypeRef::BOOL:
                return $check("!\\is_{$type->kind}({$source})", $source);
            case TypeRef::FLOAT:
                return $check("!\\is_int({$source}) && !\\is_float({$source})", "(float) {$source}");
            case TypeRef::SCALARS:
                return $check(implode(' && ', array_map(static fn (string $s): string => "!\\is_{$s}({$source})", $type->scalars)), $source);
            case TypeRef::MIXED:
                return ["{$target} = {$source};"];
            case TypeRef::MAP:
                return $check("!\\is_array({$source})", $source);
            case TypeRef::STRING_ENUM:
                $values = implode(', ', array_map(static fn (string $v): string => var_export($v, true), $type->values));

                return $check("!\\is_string({$source}) || !\\in_array({$source}, [{$values}], true)", $source);
            case TypeRef::LIST:
                $item = '$item'.($depth > 0 ? $depth : '');
                $value = '$value'.($depth > 0 ? $depth : '');
                $inner = $this->decode($type->items ?? TypeRef::mixed(), $item, $value, $fail, $depth + 1);

                return [
                    "if (!\\is_array({$source}) || !array_is_list({$source})) {",
                    "    {$fail}",
                    '}',
                    "{$target} = [];",
                    "foreach ({$source} as {$item}) {",
                    ...$this->indentLines($inner),
                    "    {$target}[] = {$value};",
                    '}',
                ];
            case TypeRef::UNION:
                $dispatch = explode("\n", $this->dispatch($type->members, $source, "{$target} = %s;", $fail));
                $lines = ["if (!\\is_array({$source})) {", "    {$fail}", '}'];
                if (null === $type->items) {
                    return [...$lines, ...$dispatch];
                }

                return [
                    ...$lines,
                    "if (array_is_list({$source})) {",
                    ...$this->indentLines(\array_slice($this->decode(TypeRef::listOf($type->items), $source, $target, $fail, $depth), 3)),
                    '} else {',
                    ...$this->indentLines($dispatch),
                    '}',
                ];
            case TypeRef::REF:
                $definition = $this->types[(string) $type->name];
                $class = $this->import($definition->specName);

                return match ($definition->kind) {
                    TypeDefinition::ENUM => [
                        "{$target} = \\is_string({$source}) ? {$class}::tryFrom({$source}) : null;",
                        "if (null === {$target}) {",
                        "    {$fail}",
                        '}',
                    ],
                    TypeDefinition::UNION => $check("!\\is_array({$source})", $this->importClass($this->namespace.'\\'.$definition->class.'Factory')."::fromArray({$source})"),
                    default => $check("!\\is_array({$source})", "{$class}::fromArray({$source})"),
                };
        }

        throw new LogicException(\sprintf('Cannot decode "%s".', $type->kind));
    }

    /**
     * Picks the union member for $source, by a constant discriminator or else by a key only that member requires.
     *
     * @param list<string> $members
     * @param string       $assign  sprintf pattern receiving the decoding expression
     */
    private function dispatch(array $members, string $source, string $assign, string $fail): string
    {
        $discriminator = $this->discriminator($members);
        if (null !== $discriminator) {
            $arms = '';
            foreach ($members as $member) {
                $class = $this->import($member);
                $arms .= "    {$class}::{$this->constantName($discriminator)} => {$class}::fromArray({$source}),\n";
            }
            $failExpression = rtrim($fail, ';');
            $match = "match ({$source}[".var_export($discriminator->wireName, true)."] ?? null) {\n{$arms}    default => {$failExpression},\n}";

            return \sprintf($assign, $match);
        }

        $branches = [];
        foreach ($members as $member) {
            $key = $this->uniqueRequiredKey($member, $members);
            $class = $this->import($member);
            $branches[] = 'if (\\array_key_exists('.var_export($key, true).", {$source})) {\n    ".\sprintf($assign, "{$class}::fromArray({$source})")."\n}";
        }

        return implode(' else', $branches)." else {\n    {$fail}\n}";
    }

    /**
     * @param list<string> $members
     */
    private function discriminator(array $members): ?PropertyDefinition
    {
        $first = $this->types[$members[0]];
        foreach ($first->properties as $candidate) {
            if (TypeRef::CONST !== $candidate->type->kind) {
                continue;
            }
            $values = [];
            foreach ($members as $member) {
                $property = $this->property($member, $candidate->wireName);
                if (null === $property || TypeRef::CONST !== $property->type->kind) {
                    continue 2;
                }
                $values[] = $property->type->const;
            }
            if (\count(array_unique($values)) === \count($values)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $members
     */
    private function uniqueRequiredKey(string $member, array $members): string
    {
        foreach ($this->types[$member]->properties as $property) {
            if (!$property->required) {
                continue;
            }
            foreach ($members as $other) {
                if ($other !== $member && null !== $this->property($other, $property->wireName)) {
                    continue 2;
                }
            }

            return $property->wireName;
        }

        throw new LogicException(\sprintf('Cannot tell union member "%s" apart from "%s".', $member, implode('", "', $members)));
    }

    private function property(string $specName, string $wireName): ?PropertyDefinition
    {
        foreach ($this->types[$specName]->properties as $property) {
            if ($property->wireName === $wireName) {
                return $property;
            }
        }

        return null;
    }

    private function jsonSerializeMethod(TypeDefinition $type): string
    {
        // Discriminators first, then constructor order: the key order of the existing wire output.
        $properties = [
            ...array_filter($type->properties, static fn (PropertyDefinition $p): bool => TypeRef::CONST === $p->type->kind),
            ...array_filter($type->properties, static fn (PropertyDefinition $p): bool => TypeRef::CONST !== $p->type->kind),
        ];

        $literal = [];
        $statements = [];
        foreach ($properties as $property) {
            $key = var_export($property->wireName, true);
            $value = TypeRef::CONST === $property->type->kind
                ? 'self::'.$this->constantName($property)
                : $this->encode($property->type, '$this->'.$property->phpName);
            $isAlwaysSent = TypeRef::CONST === $property->type->kind || $property->required || $property->alwaysEmit;

            if ($isAlwaysSent && [] === $statements) {
                $literal[] = "    {$key} => {$value},";
                continue;
            }

            $assignment = "\$data[{$key}] = {$value};";
            if ($isAlwaysSent) {
                $statements[] = $assignment;
            } elseif ($property->hasDefault) {
                $statements[] = 'if ('.var_export($property->default, true)." !== \$this->{$property->phpName}) {\n    {$assignment}\n}";
            } else {
                $statements[] = "if (null !== \$this->{$property->phpName}) {\n    {$assignment}\n}";
            }
        }

        $body = [] === $literal ? '$data = [];' : "\$data = [\n".implode("\n", $literal)."\n];";
        $body .= [] === $statements ? '' : "\n\n".implode("\n\n", $statements);

        // An object without any property set must still encode as `{}`.
        $canBeEmpty = [] === $literal;
        $body .= $canBeEmpty ? "\n\nreturn [] === \$data ? new \\stdClass() : \$data;" : "\n\nreturn \$data;";
        $returnType = $canBeEmpty ? 'array|\\stdClass' : 'array';
        $returnDoc = $canBeEmpty ? 'array<string, mixed>|\\stdClass' : 'array<string, mixed>';

        return <<<PHP
                /**
                 * @return {$returnDoc}
                 */
                public function jsonSerialize(): {$returnType}
                {
            {$this->indent($body, 2)}
                }
            PHP;
    }

    private function encode(TypeRef $type, string $value): string
    {
        if (TypeRef::MAP === $type->kind) {
            return "[] === {$value} ? new \\stdClass() : {$value}";
        }
        if (TypeRef::REF === $type->kind && TypeDefinition::ENUM === $this->types[(string) $type->name]->kind) {
            return "{$value}->value";
        }
        if (TypeRef::LIST === $type->kind && null !== $type->items && TypeRef::REF === $type->items->kind
            && TypeDefinition::ENUM === $this->types[(string) $type->items->name]->kind) {
            $class = $this->import((string) $type->items->name);

            return "array_map(static fn ({$class} \$item): string => \$item->value, array_values({$value}))";
        }
        // Keyed arrays would serialize as JSON objects.
        if (TypeRef::LIST === $type->kind) {
            return "array_values({$value})";
        }
        if (TypeRef::UNION === $type->kind && null !== $type->items) {
            return "\\is_array({$value}) ? array_values({$value}) : {$value}";
        }

        return $value;
    }

    private function nativeType(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeRef::STRING, TypeRef::STRING_ENUM => 'string',
            TypeRef::INT => 'int',
            TypeRef::FLOAT => 'float',
            TypeRef::BOOL => 'bool',
            TypeRef::SCALARS => implode('|', $type->scalars),
            TypeRef::MAP, TypeRef::LIST => 'array',
            TypeRef::REF => $this->import((string) $type->name),
            TypeRef::UNION => implode('|', [
                ...array_map(fn (string $member): string => $this->import($member), $type->members),
                ...(null === $type->items ? [] : ['array']),
            ]),
            default => 'mixed',
        };
    }

    private function docType(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeRef::MAP => 'array<string, mixed>',
            // Keys are dropped on serialization, so any array is accepted.
            TypeRef::LIST => 'array<'.$this->docType($type->items ?? TypeRef::mixed()).'>',
            TypeRef::STRING_ENUM => implode('|', array_map(static fn (string $v): string => var_export($v, true), $type->values)),
            TypeRef::UNION => implode('|', [
                ...array_map(fn (string $member): string => $this->import($member), $type->members),
                ...(null === $type->items ? [] : ['array<'.$this->docType($type->items).'>']),
            ]),
            default => $this->nativeType($type),
        };
    }

    /**
     * The wire shape `fromArray()` accepts and `jsonSerialize()` produces, as `@phpstan-type <Name>Data`.
     */
    private function dataShape(TypeDefinition $type): string
    {
        $entries = [];
        foreach ($type->properties as $property) {
            $key = preg_match('/^[a-zA-Z_]\w*$/', $property->wireName) ? $property->wireName : var_export($property->wireName, true);
            $optional = $property->required || TypeRef::CONST === $property->type->kind ? '' : '?';
            $entries[] = " *     {$key}{$optional}: {$this->dataType($property->type)},";
        }

        return "@phpstan-type {$type->shortName()}Data array{\n".implode("\n", $entries)."\n * }";
    }

    private function dataType(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeRef::STRING => 'string',
            TypeRef::INT => 'int',
            TypeRef::FLOAT => 'float|int',
            TypeRef::BOOL => 'bool',
            TypeRef::SCALARS => implode('|', $type->scalars),
            TypeRef::MAP => 'array<string, mixed>',
            TypeRef::LIST => 'list<'.$this->dataType($type->items ?? TypeRef::mixed()).'>',
            TypeRef::CONST => var_export($type->const, true),
            TypeRef::STRING_ENUM => implode('|', array_map(static fn (string $v): string => var_export($v, true), $type->values)),
            TypeRef::REF => TypeDefinition::ENUM === $this->types[(string) $type->name]->kind
                ? implode('|', array_map(static fn (string $v): string => var_export($v, true), $this->types[(string) $type->name]->values))
                : $this->dataAlias((string) $type->name),
            TypeRef::UNION => implode('|', [
                ...array_map(fn (string $member): string => $this->dataAlias($member), $type->members),
                ...(null === $type->items ? [] : ['list<'.$this->dataType($type->items).'>']),
            ]),
            default => 'mixed',
        };
    }

    private function dataAlias(string $specName): string
    {
        $definition = $this->types[$specName];
        $alias = $definition->shortName().'Data';
        if ($specName !== $this->currentSpecName) {
            $this->typeImports[$alias] = $this->import($specName);
        }

        return $alias;
    }

    private function nullable(string $native): string
    {
        if ('mixed' === $native) {
            return $native;
        }

        return str_contains($native, '|') ? $native.'|null' : '?'.$native;
    }

    /**
     * @param list<string> $docs tags rendered after the description; may span lines prefixed with " * "
     */
    private function classDoc(TypeDefinition $type, array $docs): string
    {
        $lines = [];
        if ('' !== $type->description) {
            $lines = [...$lines, ...explode("\n", $type->description), ''];
        }
        $latest = $this->revisions[\count($this->revisions) - 1];
        if ([] !== $type->revisions && !\in_array($latest, $type->revisions, true)) {
            $lines[] = ucfirst($this->revisionNote($type->revisions, $this->revisions)).'.';
            $lines[] = '';
        }
        if (null !== $type->deprecated) {
            $lines[] = '@deprecated '.$this->deprecationText($type->deprecated);
            $lines[] = '';
        }

        ksort($this->typeImports);
        foreach ($this->typeImports as $alias => $class) {
            $lines[] = "@phpstan-import-type {$alias} from {$class}";
        }
        if ([] !== $this->typeImports) {
            $lines[] = '';
        }
        foreach ($docs as $doc) {
            $lines[] = $doc;
        }
        if ([] !== $docs) {
            $lines[] = '';
        }
        $lines[] = $this->marker();

        return "/**\n".implode("\n", array_map(static fn (string $line): string => str_starts_with($line, ' *') ? $line : rtrim(' * '.$line), $lines))."\n */\n";
    }

    private function marker(): string
    {
        return self::MARKER;
    }

    /**
     * @param list<string> $present
     * @param list<string> $all
     */
    private function revisionNote(array $present, array $all): string
    {
        $first = array_search($present[0], $all, true);
        $isContiguous = \array_slice($all, (int) $first, \count($present)) === $present;

        if (1 === \count($present)) {
            return 'only on protocol revision '.$present[0];
        }
        if ($isContiguous && $present[\count($present) - 1] === $all[\count($all) - 1]) {
            return 'since protocol revision '.$present[0];
        }
        if ($isContiguous && 0 === $first) {
            return 'up to protocol revision '.$present[\count($present) - 1];
        }

        return 'on protocol revisions '.implode(', ', $present);
    }

    private function deprecationText(string $notice): string
    {
        if (preg_match('/as of protocol (?:version|revision) (\d{4})-(\d{2}-\d{2})(?:\s*\((SEP-\d+)\))?/i', $notice, $match)) {
            $sep = isset($match[3]) ? " ({$match[3]})" : '';

            return \sprintf('since protocol revision %s-%s%s, earliest removal %d-%s.', $match[1], $match[2], $sep, (int) $match[1] + 1, $match[2]);
        }

        return $notice;
    }

    private function import(string $specName): string
    {
        return $this->importClass($this->namespace.'\\'.$this->types[$specName]->class);
    }

    private function importClass(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');
        $short = false === $position ? $fqcn : substr($fqcn, $position + 1);
        $namespace = false === $position ? '' : substr($fqcn, 0, $position);

        if ($namespace === $this->currentNamespace) {
            return $short;
        }
        foreach ($this->uses as $used => $alias) {
            if ($alias === $short && $used !== $fqcn) {
                throw new LogicException(\sprintf('Import collision on "%s" between "%s" and "%s".', $short, $used, $fqcn));
            }
        }
        $this->uses[$fqcn] = $short;

        return $short;
    }

    private function constantName(PropertyDefinition $property): string
    {
        return strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property->phpName));
    }

    private function caseName(string $value): string
    {
        $name = str_replace(' ', '', ucwords((string) preg_replace('/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', ' ', $value)));

        return preg_match('/^\d/', $name) ? 'V'.$name : $name;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function indentLines(array $lines): array
    {
        return array_map(static fn (string $line): string => '' === $line ? '' : '    '.$line, $lines);
    }

    private function indent(string $code, int $levels): string
    {
        $prefix = str_repeat('    ', $levels);

        return implode("\n", array_map(static fn (string $line): string => '' === $line ? '' : $prefix.$line, explode("\n", $code)));
    }
}
