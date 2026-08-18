<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Schema\ServerCapabilities;

/**
 * Which notification types a `subscriptions/listen` stream will carry.
 *
 * The filter is an allow-list, not a hint: "The server MUST NOT send
 * notification types the client has not explicitly requested." Omitting a
 * field is the same as declining that type, so absence and `false` mean the
 * same thing here and the type never needs to distinguish them.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class NotificationFilter
{
    /**
     * @param list<string> $resourceSubscriptions resource URIs to report updates for
     */
    public function __construct(
        public readonly bool $toolsListChanged = false,
        public readonly bool $promptsListChanged = false,
        public readonly bool $resourcesListChanged = false,
        public readonly array $resourceSubscriptions = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $notifications the request's `params.notifications` member
     */
    public static function fromParams(?array $notifications): self
    {
        $notifications ??= [];

        $uris = $notifications['resourceSubscriptions'] ?? [];

        return new self(
            true === ($notifications['toolsListChanged'] ?? false),
            true === ($notifications['promptsListChanged'] ?? false),
            true === ($notifications['resourcesListChanged'] ?? false),
            \is_array($uris) ? array_values(array_filter($uris, \is_string(...))) : [],
        );
    }

    /**
     * Narrows the filter to what this server can actually deliver.
     *
     * The acknowledgment must reflect "the subset the server agreed to honor",
     * so a type the server does not support is dropped here rather than
     * acknowledged and then silently never sent — a client comparing the
     * acknowledgment against its request would otherwise wait forever for
     * notifications that were never coming.
     */
    public function intersect(ServerCapabilities $capabilities): self
    {
        return new self(
            $this->toolsListChanged && true === $capabilities->toolsListChanged,
            $this->promptsListChanged && true === $capabilities->promptsListChanged,
            $this->resourcesListChanged && true === $capabilities->resourcesListChanged,
            true === $capabilities->resourcesSubscribe ? $this->resourceSubscriptions : [],
        );
    }

    /**
     * The acknowledgment's `notifications` member: agreed types only, with
     * declined ones omitted rather than reported as `false`.
     *
     * @return array<string, mixed>
     */
    public function toAcknowledgedArray(): array
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

        return $data;
    }
}
