<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Generated;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Generated\Enum\Role;

/**
 * Optional annotations for the client. The client can use annotations to inform how objects are used or displayed.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class Annotations implements \JsonSerializable
{
    /**
     * @param list<Role>|null $audience
     * @param string|null     $lastModified since protocol revision 2025-06-18
     */
    public function __construct(
        public readonly ?array $audience = null,
        public readonly ?string $lastModified = null,
        public readonly ?float $priority = null,
    ) {
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
        $lastModified = null;
        if (isset($data['lastModified'])) {
            if (!\is_string($data['lastModified'])) {
                throw new InvalidArgumentException('Invalid "lastModified" in Annotations data.');
            }
            $lastModified = $data['lastModified'];
        }
        $priority = null;
        if (isset($data['priority'])) {
            if (!\is_int($data['priority']) && !\is_float($data['priority'])) {
                throw new InvalidArgumentException('Invalid "priority" in Annotations data.');
            }
            $priority = (float) $data['priority'];
        }

        return new self(
            audience: $audience,
            lastModified: $lastModified,
            priority: $priority,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [];

        if (null !== $this->audience) {
            $data['audience'] = array_map(static fn (Role $item): string => $item->value, $this->audience);
        }

        if (null !== $this->lastModified) {
            $data['lastModified'] = $this->lastModified;
        }

        if (null !== $this->priority) {
            $data['priority'] = $this->priority;
        }

        return $data;
    }
}
