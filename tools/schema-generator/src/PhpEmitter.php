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

    private string $currentNamespace = '';

    /** @var array<string, list<string>> object spec name => unions it belongs to */
    private array $unionsOf = [];

    /**
     * @param array<string, TypeDefinition> $types     keyed by spec name
     * @param list<string>                  $revisions all supported revisions, oldest first
     */
    public function __construct(
        private readonly array $types,
        private readonly string $namespace,
        private readonly array $revisions,
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
        $this->currentNamespace = rtrim($this->namespace.'\\'.$type->namespaceSuffix(), '\\');
        $code = $body();

        $uses = array_keys($this->uses);
        sort($uses);
        $imports = '' === implode('', $uses) ? '' : implode("\n", array_map(static fn (string $use): string => 'use '.$use.';', $uses))."\n\n";

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

            return $this->classDoc($type)."enum {$type->shortName()}: string\n{\n{$cases}}\n";
        };
    }

    /**
     * @return \Closure(): string
     */
    private function interfaceBody(TypeDefinition $type): \Closure
    {
        return fn (): string => $this->classDoc($type)."interface {$type->shortName()} extends \\JsonSerializable\n{\n}\n";
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
                 * Generated from the MCP specification by tools/schema-generator, do not edit.
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
            $implements = array_map(fn (string $union): string => $this->import($union), $this->unionsOf[$type->specName] ?? []);
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
            $assignments = [];
            foreach ($type->properties as $index => $property) {
                if (TypeRef::CONST === $property->type->kind) {
                    $constants[] = \sprintf('    public const %s = %s;', $this->constantName($property), var_export($property->type->const, true));
                    continue;
                }
                $parameters[] = $this->parameter($property, $index > $lastRequired);
                if (null !== $property->transform) {
                    $native = $this->nativeType($property->type);
                    $docType = $this->docType($property->type);
                    $declaration = $property->required ? "public readonly {$native} \${$property->phpName};" : "public readonly {$this->nullable($native)} \${$property->phpName};";
                    $declarations[] = ($docType !== $native ? "    /** @var {$docType}".($property->required ? '' : '|null')." */\n" : '').'    '.$declaration;
                    $call = "self::{$property->transform}(\${$property->phpName})";
                    $assignments[] = $property->required
                        ? "        \$this->{$property->phpName} = {$call};"
                        : "        \$this->{$property->phpName} = null === \${$property->phpName} ? null : {$call};";
                }
                $doc = $this->paramDoc($property, $type);
                if (null !== $doc) {
                    $paramDocs[] = $doc;
                }
            }

            if ([] !== $constants) {
                $members[] = implode("\n", $constants);
            }
            if ([] !== $declarations) {
                $members[] = implode("\n\n", $declarations);
            }
            $body = [] === $assignments ? '' : implode("\n", $assignments)."\n";

            $constructorDoc = [] === $paramDocs ? '' : "    /**\n".implode('', array_map(static fn (string $doc): string => "     * {$doc}\n", $paramDocs))."     */\n";
            $members[] = $constructorDoc."    public function __construct(\n".implode('', array_map(static fn (string $p): string => "        {$p},\n", $parameters))."    ) {\n{$body}    }";
            $members[] = $this->fromArrayMethod($type);
            $members[] = $this->jsonSerializeMethod($type);

            return $this->classDoc($type)."final class {$type->shortName()} implements {$implements}\n{\n".implode("\n\n", $members)."\n}\n";
        };
    }

    private function parameter(PropertyDefinition $property, bool $withDefault): string
    {
        $native = $this->nativeType($property->type);
        $modifiers = null === $property->transform ? 'public readonly ' : '';
        if ($property->required) {
            return "{$modifiers}{$native} \${$property->phpName}";
        }

        return \sprintf('%s%s $%s%s', $modifiers, $this->nullable($native), $property->phpName, $withDefault ? ' = null' : '');
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

        $needsType = \in_array($property->type->kind, [TypeRef::MAP, TypeRef::LIST, TypeRef::STRING_ENUM], true);
        if (!$needsType && [] === $notes) {
            return null;
        }

        $docType = $this->docType($property->type);
        if (!$property->required && 'mixed' !== $docType) {
            $docType .= '|null';
        }

        return rtrim(\sprintf('@param %s $%s %s', $docType, $property->phpName, implode('; ', $notes)));
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

            if (TypeRef::MIXED === $property->type->kind) {
                $lines[] = $property->required
                    ? "{$variable} = \\array_key_exists({$key}, \$data) ? {$source} : throw new {$exception}('Missing \"{$property->wireName}\" in {$owner} data.');"
                    : "{$variable} = {$source} ?? null;";
                continue;
            }

            $fail = "throw new {$exception}('Invalid \"{$property->wireName}\" in {$owner} data.');";
            $decode = $this->decode($property->type, $source, $variable, $fail, 0);

            if ($property->required) {
                $lines[] = "if (!isset({$source})) {";
                $lines[] = "    throw new {$exception}('Missing \"{$property->wireName}\" in {$owner} data.');";
                $lines[] = '}';
                array_push($lines, ...$decode);
            } else {
                $lines[] = "{$variable} = null;";
                $lines[] = "if (isset({$source})) {";
                array_push($lines, ...array_map(static fn (string $line): string => '    '.$line, $decode));
                $lines[] = '}';
            }
        }

        $lines[] = '';
        $lines[] = [] === $arguments ? 'return new self();' : "return new self(\n".implode("\n", array_map(static fn (string $a): string => '    '.$a, $arguments))."\n);";

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
                    ...array_map(static fn (string $line): string => '    '.$line, $inner),
                    "    {$target}[] = {$value};",
                    '}',
                ];
            case TypeRef::UNION:
                return [
                    "if (!\\is_array({$source})) {",
                    "    {$fail}",
                    '}',
                    ...explode("\n", $this->dispatch($type->members, $source, "{$target} = %s;", $fail)),
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
        $required = [];
        $optional = [];
        foreach ($type->properties as $property) {
            $key = var_export($property->wireName, true);
            if (TypeRef::CONST === $property->type->kind) {
                $required[] = "    {$key} => self::{$this->constantName($property)},";
                continue;
            }
            $value = $this->encode($property->type, '$this->'.$property->phpName);
            if ($property->required) {
                $required[] = "    {$key} => {$value},";
            } else {
                $optional[] = "if (null !== \$this->{$property->phpName}) {\n    \$data[{$key}] = {$value};\n}";
            }
        }

        $body = [] === $required ? '$data = [];' : "\$data = [\n".implode("\n", $required)."\n];";
        $body .= [] === $optional ? '' : "\n\n".implode("\n\n", $optional);
        $body .= "\n\nreturn \$data;";

        return <<<PHP
                /**
                 * @return array<string, mixed>
                 */
                public function jsonSerialize(): array
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

            return "array_map(static fn ({$class} \$item): string => \$item->value, {$value})";
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
            TypeRef::UNION => implode('|', array_map(fn (string $member): string => $this->import($member), $type->members)),
            default => 'mixed',
        };
    }

    private function docType(TypeRef $type): string
    {
        return match ($type->kind) {
            TypeRef::MAP => 'array<string, mixed>',
            TypeRef::LIST => 'list<'.$this->docType($type->items ?? TypeRef::mixed()).'>',
            TypeRef::STRING_ENUM => implode('|', array_map(static fn (string $v): string => var_export($v, true), $type->values)),
            default => $this->nativeType($type),
        };
    }

    private function nullable(string $native): string
    {
        if ('mixed' === $native) {
            return $native;
        }

        return str_contains($native, '|') ? $native.'|null' : '?'.$native;
    }

    private function classDoc(TypeDefinition $type): string
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
        $lines[] = 'Generated from the MCP specification by tools/schema-generator, do not edit.';

        return "/**\n".implode("\n", array_map(static fn (string $line): string => rtrim(' * '.$line), $lines))."\n */\n";
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

    private function indent(string $code, int $levels): string
    {
        $prefix = str_repeat('    ', $levels);

        return implode("\n", array_map(static fn (string $line): string => '' === $line ? '' : $prefix.$line, explode("\n", $code)));
    }
}
