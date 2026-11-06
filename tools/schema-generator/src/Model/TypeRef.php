<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tools\SchemaGenerator\Model;

/**
 * The PHP-facing shape of one schema fragment.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TypeRef
{
    public const STRING = 'string';
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const SCALARS = 'scalars';
    public const MIXED = 'mixed';
    public const MAP = 'map';
    public const LIST = 'list';
    public const CONST = 'const';
    public const STRING_ENUM = 'string_enum';
    public const REF = 'ref';
    public const UNION = 'union';

    /**
     * @param list<string>               $scalars union of PHP scalar types, for SCALARS
     * @param list<string>               $values  allowed values, for STRING_ENUM
     * @param list<string>               $members spec type names, for UNION
     * @param string|int|float|bool|null $const   fixed value, for CONST
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $name = null,
        public readonly ?self $items = null,
        public readonly array $scalars = [],
        public readonly array $values = [],
        public readonly array $members = [],
        public readonly string|int|float|bool|null $const = null,
    ) {
    }

    public static function scalar(string $kind): self
    {
        return new self($kind);
    }

    /**
     * @param list<string> $scalars
     */
    public static function scalars(array $scalars): self
    {
        return new self(self::SCALARS, scalars: $scalars);
    }

    public static function mixed(): self
    {
        return new self(self::MIXED);
    }

    public static function map(): self
    {
        return new self(self::MAP);
    }

    public static function listOf(self $items): self
    {
        return new self(self::LIST, items: $items);
    }

    public static function constant(string|int|float|bool|null $value): self
    {
        return new self(self::CONST, const: $value);
    }

    /**
     * @param list<string> $values
     */
    public static function stringEnum(array $values): self
    {
        return new self(self::STRING_ENUM, values: $values);
    }

    public static function ref(string $name): self
    {
        return new self(self::REF, name: $name);
    }

    /**
     * @param list<string> $members
     */
    public static function union(array $members): self
    {
        return new self(self::UNION, members: $members);
    }

    /**
     * @return list<string> spec type names this reference points at, recursively
     */
    public function referencedTypes(): array
    {
        return match ($this->kind) {
            self::REF => [(string) $this->name],
            self::UNION => $this->members,
            self::LIST => $this->items?->referencedTypes() ?? [],
            default => [],
        };
    }
}
