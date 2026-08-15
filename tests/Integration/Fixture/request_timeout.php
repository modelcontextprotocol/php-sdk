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
 * Server for {@see \Mcp\Tests\Integration\RequestTimeoutTest}.
 *
 * `slow` outlives the client's request timeout and then goes idle again, which
 * is what lets the test call `fast` on a connection the client has already
 * given up on once.
 */

use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static function (): string {
            sleep(2);

            return 'late';
        },
        name: 'slow',
        description: 'Answers well after the client stopped waiting.',
    )
    ->addTool(
        static fn (): string => 'quick',
        name: 'fast',
        description: 'Returns at once.',
    )
    ->build()
    ->run(new StdioTransport());
