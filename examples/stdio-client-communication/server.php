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

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Transport\StdioTransport;

$capabilities = new ServerCapabilities(logging: true, tools: true);

$server = Server::builder()
    ->setServerInfo('STDIO Client Communication Demo', '1.0.0')
    ->setLogger(logger())
    ->setContainer(container())
    ->setCapabilities($capabilities)
    ->addTool(
        function (string $incidentTitle, ClientGateway $client): array {
            $client->log(LoggingLevel::Warning, sprintf('Incident triage started: %s', $incidentTitle));

            $steps = [
                'Collecting telemetry',
                'Assessing scope',
                'Coordinating responders',
            ];

            foreach ($steps as $index => $step) {
                $progress = ($index + 1) / count($steps);

                $client->progress(progress: $progress, total: 1, message: $step);

                usleep(180_000); // Simulate work being done
            }

            $prompt = sprintf(
                'Provide a concise response strategy for incident "%s" based on the steps completed: %s.',
                $incidentTitle,
                implode(', ', $steps)
            );

            $sampling = $client->sample(
                prompt: $prompt,
                maxTokens: 350,
                timeout: 90,
                options: ['temperature' => 0.5]
            );

            if ($sampling instanceof JsonRpcError) {
                throw new RuntimeException(sprintf('Sampling request failed (%d): %s', $sampling->code, $sampling->message));
            }

            $result = $sampling->result;
            $recommendation = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';

            $client->log(LoggingLevel::Info, sprintf('Incident triage completed for %s', $incidentTitle));

            return [
                'incident' => $incidentTitle,
                'recommended_actions' => $recommendation,
                'model' => $result->model,
            ];
        },
        name: 'coordinate_incident_response',
        description: 'Coordinate an incident response with logging, progress, and sampling.'
    )
    ->addTool(
        function (string $dataset, ClientGateway $client): array {
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

$transport = new StdioTransport();

$status = $server->run($transport);

exit($status);
