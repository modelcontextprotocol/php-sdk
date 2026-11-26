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
// MCP_FIXTURE_LIST_DELAY and MCP_FIXTURE_TOOL_DELAY slow down each tools/list page and the tool, in seconds;
// MCP_FIXTURE_LOG gets a line per tools/list request.

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Server;
use Mcp\Server\Transport\StatelessHttpTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$request = (new Psr17Factory())->createServerRequestFromGlobals();

if ('tools/list' === (json_decode((string) file_get_contents('php://input'), true)['method'] ?? null)) {
    if ('' !== $log = (string) getenv('MCP_FIXTURE_LOG')) {
        file_put_contents($log, "tools/list\n", \FILE_APPEND);
    }
    usleep((int) ((float) getenv('MCP_FIXTURE_LIST_DELAY') * 1_000_000));
}

$server = Server::builder()
    ->setServerInfo('tool-headers', '1.0.0')
    // One tool per page, so listing takes more than one request.
    ->setPaginationLimit(1)
    ->addTool(static function (string $owner, string $repo): string {
        usleep((int) ((float) getenv('MCP_FIXTURE_TOOL_DELAY') * 1_000_000));

        return $owner.'/'.$repo;
    }, name: 'list_issues', inputSchema: [
        'type' => 'object',
        'properties' => [
            'owner' => ['type' => 'string', 'x-mcp-header' => 'Owner'],
            'repo' => ['type' => 'string', 'x-mcp-header' => 'Repo'],
        ],
        'required' => ['owner', 'repo'],
    ])
    ->addTool(static fn (): string => 'pong', name: 'ping')
    ->buildStateless();

(new SapiEmitter())->emit((new StatelessHttpTransport($server))->handle($request));
