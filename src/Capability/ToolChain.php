<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability;

use Mcp\Capability\Tool\CollectionInterface;
use Mcp\Capability\Tool\IdentifierInterface;
use Mcp\Capability\Tool\MetadataInterface;
use Mcp\Capability\Tool\ToolCallerInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Exception\ToolCallException;
use Mcp\Exception\ToolExecutionExceptionInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;

/**
 * A collection of tools. All tools need to implement IdentifierInterface.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ToolChain implements ToolCallerInterface, CollectionInterface
{
    public function __construct(
        /**
         * @var IdentifierInterface[] $items
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
                $found = $item->getName() === $lastIdentifier;
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

    public function call(CallToolRequest $request): CallToolResult
    {
        foreach ($this->items as $item) {
            if ($item instanceof ToolCallerInterface && $request->name === $item->getName()) {
                try {
                    return $item->call($request);
                } catch (\Throwable $e) {
                    if ($e instanceof ToolExecutionExceptionInterface) {
                        throw $e;
                    }

                    throw new ToolCallException($request, $e);
                }
            }
        }

        throw new ToolNotFoundException($request);
    }
}
