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

use Mcp\Schema\Exception\InvalidArgumentException;

/**
 * The notification types a client opts in to on a `subscriptions/listen` stream, or the subset
 * a server agreed to honor.
 *
 * @phpstan-type SubscriptionFilterData array{
 *     toolsListChanged?: bool,
 *     promptsListChanged?: bool,
 *     resourcesListChanged?: bool,
 *     resourceSubscriptions?: list<string>,
 * }
 *
 * @author Diego D'amico
 */
final class SubscriptionFilter implements \JsonSerializable
{
    /**
     * @param list<string> $resourceSubscriptions URIs to receive `notifications/resources/updated` for
     */
    public function __construct(
        public readonly bool $toolsListChanged = false,
        public readonly bool $promptsListChanged = false,
        public readonly bool $resourcesListChanged = false,
        public readonly array $resourceSubscriptions = [],
    ) {
        foreach ($resourceSubscriptions as $uri) {
            if (!\is_string($uri) || '' === $uri) {
                throw new InvalidArgumentException('Resource subscriptions must be non-empty URI strings.');
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['toolsListChanged', 'promptsListChanged', 'resourcesListChanged'] as $flag) {
            if (isset($data[$flag]) && !\is_bool($data[$flag])) {
                throw new InvalidArgumentException(\sprintf('Invalid "%s" in subscription filter; expected a boolean.', $flag));
            }
        }

        $uris = $data['resourceSubscriptions'] ?? [];
        if (!\is_array($uris) || !array_is_list($uris)) {
            throw new InvalidArgumentException('Invalid "resourceSubscriptions" in subscription filter; expected a list of URIs.');
        }

        return new self(
            true === ($data['toolsListChanged'] ?? false),
            true === ($data['promptsListChanged'] ?? false),
            true === ($data['resourcesListChanged'] ?? false),
            $uris,
        );
    }

    public function isEmpty(): bool
    {
        return !$this->toolsListChanged
            && !$this->promptsListChanged
            && !$this->resourcesListChanged
            && [] === $this->resourceSubscriptions;
    }

    /**
     * @return SubscriptionFilterData|\stdClass
     */
    public function jsonSerialize(): array|\stdClass
    {
        $data = [];
        if ($this->toolsListChanged) {
            $data['toolsListChanged'] = true;
        }
        if ($this->promptsListChanged) {
            $data['promptsListChanged'] = true;
        }
        if ($this->resourcesListChanged) {
            $data['resourcesListChanged'] = true;
        }
        if ([] !== $this->resourceSubscriptions) {
            $data['resourceSubscriptions'] = $this->resourceSubscriptions;
        }

        return [] === $data ? new \stdClass() : $data;
    }
}
