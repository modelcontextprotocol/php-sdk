<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\ResourceTemplate;

/**
 * The server's response to a resources/templates/list request from the client.
 *
 * @phpstan-import-type ResourceTemplateData from ResourceTemplate
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListResourceTemplatesResult implements ResultInterface
{
    /**
     * @param array<ResourceTemplate> $resourceTemplates the list of resource template definitions
     * @param string|null             $nextCursor        An opaque token representing the pagination position after the last returned result.
     *
     * If present, there may be more results available.
     */
    public function __construct(
        public readonly array $resourceTemplates,
        public readonly ?string $nextCursor = null,
    ) {
    }

    /**
     * @param array{
     *     resourceTemplates: array<ResourceTemplateData>,
     *     nextCursor?: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['resourceTemplates']) || !\is_array($data['resourceTemplates'])) {
            throw new InvalidArgumentException('Missing or invalid "resourceTemplates" array in ListResourceTemplatesResult data.');
        }

        return new self(
            array_map(fn (array $resourceTemplate): ResourceTemplate => ResourceTemplate::fromArray($resourceTemplate), $data['resourceTemplates']),
            $data['nextCursor'] ?? null
        );
    }

    /**
     * @return array{
     *     resourceTemplates: array<ResourceTemplate>,
     *     nextCursor?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $result = [
            'resourceTemplates' => array_values($this->resourceTemplates),
        ];

        if ($this->nextCursor) {
            $result['nextCursor'] = $this->nextCursor;
        }

        return $result;
    }
}
