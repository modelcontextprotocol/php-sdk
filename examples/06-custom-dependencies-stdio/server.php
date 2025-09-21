#!/usr/bin/env php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__).'/bootstrap.php';
chdir(__DIR__);

use Mcp\DependenciesStdioExample\Services;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Custom Dependencies (Stdio) Server...');

$container = container();

$taskRepo = new Services\InMemoryTaskRepository(logger());
$container->set(Services\TaskRepositoryInterface::class, $taskRepo);

$statsService = new Services\SystemStatsService($taskRepo);
$container->set(Services\StatsServiceInterface::class, $statsService);

$server = Server::make()
    ->setServerInfo('Task Manager Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer($container)
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: logger());

$server->connect($transport);

$transport->listen();

logger()->info('Server listener stopped gracefully.');
