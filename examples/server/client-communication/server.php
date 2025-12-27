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

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\FileSessionStore;

$server = Server::builder()
    ->setServerInfo('Client Communication Demo', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setSession(new FileSessionStore(__DIR__.'/sessions'))
    ->setCapabilities(new ServerCapabilities(logging: true, tools: true))
    ->setDiscovery(__DIR__)
    ->addTool(
        function (RequestContext $context, string $dataset): array {
            $client = $context->getClientGateway();
            $client->log(LoggingLevel::Info, sprintf('Running quality checks on dataset "%s"', $dataset));

            $tasks = [
                'Validating schema',
                'Scanning for anomalies',
                'Reviewing statistical summary',
            ];

            foreach ($tasks as $index => $task) {
                $progress = ($index + 1) / count($tasks);

                $client->progress(progress: $progress, total: 1, message: $task);

                usleep(140_000); // Simulate work being done
            }

            $client->log(LoggingLevel::Info, sprintf('Dataset "%s" passed automated checks.', $dataset));

            return [
                'dataset' => $dataset,
                'status' => 'passed',
                'notes' => 'No significant integrity issues detected during automated checks.',
            ];
        },
        name: 'run_dataset_quality_checks',
        description: 'Perform dataset quality checks with progress updates and logging.'
    )
    ->build();

$result = $server->run(transport());

shutdown($result);
