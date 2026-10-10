<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Server for {@see \Mcp\Tests\Integration\DeadServerTest}.
 *
 * `exit` ends the server process in the middle of the call.
 */

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (): string {
            exit(1);
        },
        name: 'exit',
        description: 'Ends the server process without answering.',
    )
    ->addTool(
        static fn (): string => 'quick',
        name: 'fast',
        description: 'Returns at once.',
    )
    ->build()
    ->run(new StdioTransport());
