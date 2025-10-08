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

use Mcp\Example\DependenciesStdioExample\Service\InMemoryTaskRepository;
use Mcp\Example\DependenciesStdioExample\Service\StatsServiceInterface;
use Mcp\Example\DependenciesStdioExample\Service\SystemStatsService;
use Mcp\Example\DependenciesStdioExample\Service\TaskRepositoryInterface;
use Mcp\Server;
use Mcp\Server\Transport\StdioTransport;

logger()->info('Starting MCP Custom Dependencies (Stdio) Server...');

$container = container();

$taskRepo = new InMemoryTaskRepository(logger());
$container->set(TaskRepositoryInterface::class, $taskRepo);

$statsService = new SystemStatsService($taskRepo);
$container->set(StatsServiceInterface::class, $statsService);

$server = Server::builder()
    ->setServerInfo('Task Manager Server', '1.0.0')
    ->setLogger(logger())
    ->setContainer($container)
    ->setDiscovery(__DIR__, ['.'])
    ->build();

$transport = new StdioTransport(logger: logger());

$result = $server->run($transport);

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

exit((int) $result);
