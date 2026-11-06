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
 * A generated PHP type: a value object, an enum, or a union interface.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TypeDefinition
{
    public const OBJECT = 'object';
    public const ENUM = 'enum';
    public const UNION = 'union';

    /**
     * @param list<string>             $revisions    revisions that define this type, empty if all do
     * @param list<PropertyDefinition> $properties   for OBJECT
     * @param list<string>             $values       for ENUM
     * @param list<string>             $members      spec type names, for UNION
     * @param list<class-string>       $traits       for OBJECT
     * @param list<class-string>       $implements   extra interfaces implemented (OBJECT) or extended (UNION)
     * @param list<string>             $validators   instance methods the constructor calls last, for OBJECT
     * @param array<string, string>    $phpstanTypes extra `@phpstan-type` aliases, name => type
     */
    public function __construct(
        public readonly string $specName,
        public readonly string $class,
        public readonly string $kind,
        public readonly string $description = '',
        public readonly ?string $deprecated = null,
        public readonly array $revisions = [],
        public readonly array $properties = [],
        public readonly array $values = [],
        public readonly array $members = [],
        public readonly array $traits = [],
        public readonly array $implements = [],
        public readonly array $validators = [],
        public readonly array $phpstanTypes = [],
    ) {
    }

    public function shortName(): string
    {
        $position = strrpos($this->class, '\\');

        return false === $position ? $this->class : substr($this->class, $position + 1);
    }

    public function namespaceSuffix(): string
    {
        $position = strrpos($this->class, '\\');

        return false === $position ? '' : substr($this->class, 0, $position);
    }
}
