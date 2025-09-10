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
use Mcp\Capability\Prompt\CollectionInterface;
use Mcp\Capability\Prompt\IdentifierInterface;
use Mcp\Capability\Prompt\MetadataInterface;
use Mcp\Capability\Prompt\PromptGetterInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Exception\PromptGetException;
use Mcp\Exception\PromptNotFoundException;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;

/**
 * A collection of prompts. All prompts need to implement IdentifierInterface.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class PromptChain implements PromptGetterInterface, CollectionInterface
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

    public function get(GetPromptRequest $request): GetPromptResult
    {
        foreach ($this->items as $item) {
            if ($item instanceof PromptGetterInterface && $request->name === $item->getName()) {
                try {
                    return $item->get($request);
                } catch (Throwable $e) {
                    throw new PromptGetException($request, $e);
                }
            }
        }

        throw new PromptNotFoundException($request);
    }
}
