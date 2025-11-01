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

use Mcp\Example\CustomDependencies\Service\InMemoryTaskRepository;
use Mcp\Example\CustomDependencies\Service\StatsServiceInterface;
use Mcp\Example\CustomDependencies\Service\SystemStatsService;
use Mcp\Example\CustomDependencies\Service\TaskRepositoryInterface;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;

logger()->info('Starting MCP Custom Dependencies Server...');

$container = container();

$taskRepo = new InMemoryTaskRepository(logger());
$container->set(TaskRepositoryInterface::class, $taskRepo);

$statsService = new SystemStatsService($taskRepo);
$container->set(StatsServiceInterface::class, $statsService);

$server = Server::builder()
    ->setServerInfo('Task Manager Server', '1.0.0')
    ->setContainer($container)
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setLogger(logger())
    ->setDiscovery(__DIR__)
    ->build();

$result = $server->run(transport());

logger()->info('Server listener stopped gracefully.', ['result' => $result]);

shutdown($result);
