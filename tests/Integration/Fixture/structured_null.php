<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('structured-null', '1.0.0')
    ->addTool(static fn () => null, name: 'nullable', outputSchema: ['type' => ['object', 'null']])
    ->addTool(static fn () => new class implements JsonSerializable {
        public function jsonSerialize(): mixed
        {
            return null;
        }
    }, name: 'serialized_null', outputSchema: ['type' => 'null'])
    ->addTool(static fn () => null, name: 'plain')
    ->addTool(static fn () => new CallToolResult([new TextContent('null')], structuredContent: null), name: 'explicit_null', outputSchema: ['type' => 'null'])
    ->addTool(static fn () => null, name: 'invalid_null', outputSchema: ['type' => 'string'])
    ->addTool(static fn () => new CallToolResult([new TextContent('null')], structuredContent: null), name: 'invalid_explicit_null', outputSchema: ['type' => 'string'])
    ->build()
    ->run(new StdioTransport());
