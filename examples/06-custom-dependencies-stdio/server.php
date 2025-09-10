#!/usr/bin/env php
<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
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

Server::make()
    ->withServerInfo('Task Manager Server', '1.0.0')
    ->withLogger(logger())
    ->withContainer($container)
    ->withDiscovery(__DIR__, ['.'])
    ->build()
    ->connect(new StdioTransport(logger: logger()));

logger()->info('Server listener stopped gracefully.');
