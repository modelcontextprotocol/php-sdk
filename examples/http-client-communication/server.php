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

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

$request = (new Psr17Factory())->createServerRequestFromGlobals();

$sessionDir = __DIR__.'/sessions';
$capabilities = new ServerCapabilities(logging: true, tools: true);
$logger = logger();

$server = Server::builder()
    ->setServerInfo('HTTP Client Communication Demo', '1.0.0')
    ->setLogger($logger)
    ->setContainer(container())
    ->setSession(new FileSessionStore($sessionDir))
    ->setCapabilities($capabilities)
    ->addTool(
        function (string $projectName, array $milestones, ClientGateway $client): array {
            $client->log(LoggingLevel::Info, sprintf('Preparing project briefing for "%s"', $projectName));

            $totalSteps = max(1, count($milestones));

            foreach ($milestones as $index => $milestone) {
                $progress = ($index + 1) / $totalSteps;
                $message = sprintf('Analyzing milestone "%s"', $milestone);

                $client->progress(progress: $progress, total: 1, message: $message);

                usleep(150_000); // Simulate work being done
            }

            $prompt = sprintf(
                'Draft a concise stakeholder briefing for the project "%s". Highlight key milestones: %s. Focus on risks and next steps.',
                $projectName,
                implode(', ', $milestones)
            );

            $response = $client->sample(
                prompt: $prompt,
                maxTokens: 400,
                timeout: 90,
                options: ['temperature' => 0.4]
            );

            if ($response instanceof JsonRpcError) {
                throw new ToolCallException(sprintf('Sampling request failed (%d): %s', $response->code, $response->message));
            }

            $result = $response->result;
            $content = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';

            $client->log(LoggingLevel::Info, 'Briefing ready, returning to caller.');

            return [
                'project' => $projectName,
                'milestones_reviewed' => $milestones,
                'briefing' => $content,
                'model' => $result->model,
                'stop_reason' => $result->stopReason,
            ];
        },
        name: 'prepare_project_briefing',
        description: 'Compile a stakeholder briefing with live logging, progress updates, and LLM sampling.'
    )
    ->addTool(
        function (string $serviceName, ClientGateway $client): array {
            $client->log(LoggingLevel::Info, sprintf('Starting maintenance checks for "%s"', $serviceName));

            $steps = [
                'Verifying health metrics',
                'Checking recent deployments',
                'Reviewing alert stream',
                'Summarizing findings',
            ];

            foreach ($steps as $index => $step) {
                $progress = ($index + 1) / count($steps);

                $client->progress(progress: $progress, total: 1, message: $step);

                usleep(120_000); // Simulate work being done
            }

            $client->log(LoggingLevel::Info, sprintf('Maintenance checks complete for "%s"', $serviceName));

            return [
                'service' => $serviceName,
                'status' => 'operational',
                'notes' => 'No critical issues detected during automated sweep.',
            ];
        },
        name: 'run_service_maintenance',
        description: 'Simulate service maintenance with logging and progress notifications.'
    )
    ->build();

$transport = new StreamableHttpTransport($request, logger: $logger);

$response = $server->run($transport);

(new SapiEmitter())->emit($response);
