<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Resource as ResourceSchema;

/**
 * The server's response to a resources/list request from the client.
 *
 * @phpstan-import-type ResourceData from ResourceSchema
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListResourcesResult implements ResultInterface
{
    /**
     * @param array<ResourceSchema> $resources  the list of resource definitions
     * @param string|null           $nextCursor An opaque token representing the pagination position after the last returned result.
     *
     * If present, there may be more results available.
     */
    public function __construct(
        public readonly array $resources,
        public readonly ?string $nextCursor = null,
    ) {
    }

    /**
     * @param array{
     *     resources: array<ResourceData>,
     *     nextCursor?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['resources']) || !\is_array($data['resources'])) {
            throw new InvalidArgumentException('Missing or invalid "resources" array in ListResourcesResult data.');
        }

        return new self(
            array_map(fn (array $resource) => ResourceSchema::fromArray($resource), $data['resources']),
            $data['nextCursor'] ?? null
        );
    }

    /**
     * @return array{
     *     resources: array<ResourceSchema>,
     *     nextCursor?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'resources' => array_values($this->resources),
        ];

        if (null !== $this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
