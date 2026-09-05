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
 * Server for {@see \Mcp\Tests\Integration\CompletionTest}.
 */

use Mcp\Capability\Registry\Container;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Tests\Integration\Fixture\Completion\SeatCompletionProvider;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$container = new Container();
$container->set(SeatCompletionProvider::class, new SeatCompletionProvider(['12A', '12B', '14C']));

Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->setContainer($container)
    ->setDiscovery(__DIR__, ['Completion'])
    ->build()
    ->run(new StdioTransport());
