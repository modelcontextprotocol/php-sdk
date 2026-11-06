<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Concern\AnnotationsFactories;
use Mcp\Schema\Enum\Role;

/**
 * Optional annotations for the client. The client can use annotations to inform how objects are used or displayed.
 *
 * @phpstan-type AnnotationsData array{
 *     audience?: list<'assistant'|'user'>,
 *     priority?: float|int,
 *     lastModified?: string,
 * }
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class Annotations implements \JsonSerializable
{
    use AnnotationsFactories;

    /**
     * @param array<Role>|null $audience
     * @param string|null      $lastModified since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly ?array $audience = null,
        public readonly ?float $priority = null,
        public readonly ?string $lastModified = null,
    ) {
        if (null !== $this->audience) {
            foreach ($this->audience as $item) {
                if (!$item instanceof Role) {
                    throw new InvalidArgumentException('Invalid "audience" item in Annotations.');
                }
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $audience = null;
        if (isset($data['audience'])) {
            if (!\is_array($data['audience']) || !array_is_list($data['audience'])) {
                throw new InvalidArgumentException('Invalid "audience" in Annotations data.');
            }
            $audience = [];
            foreach ($data['audience'] as $item) {
                $value = \is_string($item) ? Role::tryFrom($item) : null;
                if (null === $value) {
                    throw new InvalidArgumentException('Invalid "audience" in Annotations data.');
                }
                $audience[] = $value;
            }
        }
        $priority = null;
        if (isset($data['priority'])) {
            if (!\is_int($data['priority']) && !\is_float($data['priority'])) {
                throw new InvalidArgumentException('Invalid "priority" in Annotations data.');
            }
            $priority = (float) $data['priority'];
        }
        $lastModified = null;
        if (isset($data['lastModified'])) {
            if (!\is_string($data['lastModified'])) {
                throw new InvalidArgumentException('Invalid "lastModified" in Annotations data.');
            }
            $lastModified = $data['lastModified'];
        }

        return new self(
            audience: $audience,
            priority: $priority,
            lastModified: $lastModified,
        );
    }

    /**
     * @return array<string, mixed>|\stdClass
     */
    public function jsonSerialize(): array|\stdClass
    {
        $data = [];

        if (null !== $this->audience) {
            $data['audience'] = array_map(static fn (Role $item): string => $item->value, array_values($this->audience));
        }

        if (null !== $this->priority) {
            $data['priority'] = $this->priority;
        }

        if (null !== $this->lastModified) {
            $data['lastModified'] = $this->lastModified;
        }

        return [] === $data ? new \stdClass() : $data;
    }
}
