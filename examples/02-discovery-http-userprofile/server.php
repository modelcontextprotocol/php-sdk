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

use Mcp\Capability\Registry\Container;
use Mcp\Server;
use Psr\Log\LoggerInterface;

logger()->info('Starting MCP HTTP User Profile Server...');

// --- Setup DI Container for DI in McpElements class ---
$container = new Container();
$container->set(LoggerInterface::class, logger());

Server::make()
    ->setServerInfo('HTTP User Profiles', '1.0.0')
    ->setLogger(logger())
    ->setContainer($container)
    ->setDiscovery(__DIR__, ['.'])
    ->addTool(
        function (float $a, float $b, string $operation = 'add'): array {
            $result = match ($operation) {
                'add' => $a + $b,
                'subtract' => $a - $b,
                'multiply' => $a * $b,
                'divide' => 0 != $b ? $a / $b : throw new InvalidArgumentException('Cannot divide by zero'),
                default => throw new InvalidArgumentException("Unknown operation: {$operation}"),
            };

            return [
                'operation' => $operation,
                'operands' => [$a, $b],
                'result' => $result,
            ];
        },
        name: 'calculator',
        description: 'Perform basic math operations (add, subtract, multiply, divide)'
    )
    ->addResource(
        function (): array {
            $memoryUsage = memory_get_usage(true);
            $memoryPeak = memory_get_peak_usage(true);
            $uptime = time() - $_SERVER['REQUEST_TIME_FLOAT'] ?? time();
            $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'CLI';

            return [
                'server_time' => date('Y-m-d H:i:s'),
                'uptime_seconds' => $uptime,
                'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
                'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
                'php_version' => \PHP_VERSION,
                'server_software' => $serverSoftware,
                'operating_system' => \PHP_OS_FAMILY,
                'status' => 'healthy',
            ];
        },
        uri: 'system://status',
        name: 'system_status',
        description: 'Current system status and runtime information',
        mimeType: 'application/json'
    )
    ->build()
    ->connect(new StreamableHttpServerTransport('127.0.0.1', 8080, 'mcp'));

logger()->info('Server listener stopped gracefully.');
