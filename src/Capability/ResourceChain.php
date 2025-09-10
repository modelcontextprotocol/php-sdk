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

namespace Mcp\Capability;

use Throwable;
use Mcp\Capability\Resource\CollectionInterface;
use Mcp\Capability\Resource\IdentifierInterface;
use Mcp\Capability\Resource\MetadataInterface;
use Mcp\Capability\Resource\ResourceReaderInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ResourceReadException;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;

/**
 * A collection of resources. All resources need to implement IdentifierInterface.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ResourceChain implements CollectionInterface, ResourceReaderInterface
{
    public function __construct(
        /**
         * @var IdentifierInterface[]
         */
        private readonly array $items,
    ) {
    }

    public function getMetadata(int $count, ?string $lastIdentifier = null): iterable
    {
        $found = null === $lastIdentifier;
        foreach ($this->items as $item) {
            if (!$item instanceof MetadataInterface) {
                continue;
            }

            if (false === $found) {
                $found = $item->getUri() === $lastIdentifier;
                continue;
            }

            yield $item;
            if (--$count <= 0) {
                break;
            }
        }

        if (!$found) {
            throw new InvalidCursorException($lastIdentifier);
        }
    }

    public function read(ReadResourceRequest $request): ReadResourceResult
    {
        foreach ($this->items as $item) {
            if ($item instanceof ResourceReaderInterface && $request->uri === $item->getUri()) {
                try {
                    return $item->read($request);
                } catch (Throwable $e) {
                    throw new ResourceReadException($request, $e);
                }
            }
        }

        throw new ResourceNotFoundException($request);
    }
}
