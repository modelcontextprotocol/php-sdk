<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Mcp\Capability\Tool\MetadataInterface;
use Mcp\Capability\Tool\ToolCallerInterface;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ExampleTool implements MetadataInterface, ToolCallerInterface
{
    public function call(CallToolRequest $request): CallToolResult
    {
        $format = $request->arguments['format'] ?? 'Y-m-d H:i:s';

        return new CallToolResult([
            new TextContent(
                (new \DateTime('now', new \DateTimeZone('UTC')))->format($format),
            ),
        ]);
    }

    public function getName(): string
    {
        return 'Current time';
    }

    public function getDescription(): string
    {
        return 'Returns the current time in UTC';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => [
                    'type' => 'string',
                    'description' => 'The format of the time, e.g. "Y-m-d H:i:s"',
                    'default' => 'Y-m-d H:i:s',
                ],
            ],
            'required' => [],
        ];
    }
}
