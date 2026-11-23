<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Server for ToolHeaderRetryTest under `php -S`: a tool that mirrors its arguments into Mcp-Param-* headers.

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Server;
use Mcp\Server\Transport\StatelessHttpTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$server = Server::builder()
    ->setServerInfo('tool-headers', '1.0.0')
    ->addTool(static fn (string $owner, string $repo): string => $owner.'/'.$repo, name: 'list_issues', inputSchema: [
        'type' => 'object',
        'properties' => [
            'owner' => ['type' => 'string', 'x-mcp-header' => 'Owner'],
            'repo' => ['type' => 'string', 'x-mcp-header' => 'Repo'],
        ],
        'required' => ['owner', 'repo'],
    ])
    ->buildStateless();

(new SapiEmitter())->emit((new StatelessHttpTransport($server))->handle((new Psr17Factory())->createServerRequestFromGlobals()));
