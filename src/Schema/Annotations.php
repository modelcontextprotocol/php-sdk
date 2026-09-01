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

use Mcp\Schema\Enum\Role;
use Mcp\Schema\Exception\InvalidArgumentException;

/**
 * Optional annotations for the client. The client can use annotations
 * to inform how objects are used or displayed.
 *
 * @phpstan-type AnnotationsData array{
 *     audience?: string[],
 *     priority?: float
 * }
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Annotations implements \JsonSerializable
{
    /**
     * @param Role[]|null $audience Describes who the intended customer of this object or data is.
     *
     *  It can include multiple entries to indicate content useful for multiple audiences (e.g., `[Role::User, Role::Assistant]`).
     * @param float|null $priority Describes how important this data is for operating the server.
     *
     * A value of 1 means "most important," and indicates that the data is
     * effectively required, while 0 means "least important," and indicates that
     * the data is entirely optional.
     */
    public function __construct(
        public readonly ?array $audience = null,
        public readonly ?float $priority = null,
    ) {
        if (null !== $this->priority && ($this->priority < 0 || $this->priority > 1)) {
            throw new InvalidArgumentException('Annotation priority must be between 0 and 1.');
        }
        if (null !== $this->audience) {
            foreach ($this->audience as $role) {
                if (!$role instanceof Role) {
                    throw new InvalidArgumentException('All audience members must be instances of Role enum.');
                }
            }
        }
    }

    /**
     * @param AnnotationsData $data
     */
    public static function fromArray(array $data): self
    {
        $audience = null;
        if (isset($data['audience']) && \is_array($data['audience'])) {
            $audience = array_map(
                static function (mixed $role): Role {
                    if (!\is_string($role) || null === $case = Role::tryFrom($role)) {
                        throw new InvalidArgumentException('Each entry in "audience" must be a valid role.');
                    }

                    return $case;
                },
                $data['audience'],
            );
        }

        if (isset($data['priority']) && !\is_float($data['priority']) && !\is_int($data['priority'])) {
            throw new InvalidArgumentException('Invalid "priority" in Annotations data; expected a number.');
        }

        return new self(
            $audience,
            isset($data['priority']) ? (float) $data['priority'] : null
        );
    }

    /**
     * Hydrates an optional "annotations" field, rejecting a value that is present but not an object.
     *
     * @param string $context the surrounding schema type, used for the error message
     */
    public static function tryFromArray(mixed $data, string $context): ?self
    {
        if (null === $data) {
            return null;
        }

        if (!\is_array($data)) {
            throw new InvalidArgumentException(\sprintf('Invalid "annotations" in %s data; expected an array.', $context));
        }

        return self::fromArray($data);
    }

    /**
     * @return AnnotationsData
     */
    public function jsonSerialize(): array
    {
        $data = [];
        if (null !== $this->audience) {
            $data['audience'] = array_map(static fn (Role $r) => $r->value, $this->audience);
        }
        if (null !== $this->priority) {
            $data['priority'] = $this->priority;
        }

        return $data;
    }
}
