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
 * Server for {@see \Mcp\Tests\Integration\RetryTest}.
 *
 * Dies before speaking a frame while the spawn counter is below the configured
 * number of failures, so the counter file records how many processes the client
 * actually started.
 */

use Mcp\Server\Server;
use Mcp\Server\Transport\StdioTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$counter = getenv('MCP_SPAWN_COUNTER');

if (!is_string($counter) || '' === $counter) {
    exit(1);
}

$spawns = 1 + (int) file_get_contents($counter);
file_put_contents($counter, (string) $spawns);

if ($spawns <= (int) getenv('MCP_FAIL_SPAWNS')) {
    exit(1);
}

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->addTool(
        static fn (): string => 'quick',
        name: 'fast',
        description: 'Returns at once.',
    )
    ->build()
    ->run(new StdioTransport());
