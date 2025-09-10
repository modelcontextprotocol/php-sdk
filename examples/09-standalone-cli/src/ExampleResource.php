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

namespace App;

use Mcp\Capability\Resource\MetadataInterface;
use Mcp\Capability\Resource\ResourceReaderInterface;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Result\ReadResourceResult;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ExampleResource implements MetadataInterface, ResourceReaderInterface
{
    public function read(ReadResourceRequest $request): ReadResourceResult
    {
        return new ReadResourceResult([
            new TextResourceContents($this->getUri(), null, 'Content of My Resource'),
        ]);
    }

    public function getUri(): string
    {
        return 'file:///project/src/main.rs';
    }

    public function getName(): string
    {
        return 'my-resource';
    }

    public function getDescription(): ?string
    {
        return 'This is just an example';
    }

    public function getMimeType(): ?string
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }
}
