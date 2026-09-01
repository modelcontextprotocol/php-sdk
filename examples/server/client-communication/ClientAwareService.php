<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\ClientCommunication;

use Mcp\Schema\Content\SamplingMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\InputRequiredResult;
use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Psr\Log\LoggerInterface;

final class ClientAwareService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('SamplingTool instantiated for sampling example.');
    }

    /**
     * Ask the client which workspace folders the server is allowed to operate on.
     *
     * Demonstrates the server side of the "roots" client capability: the tool
     * issues a roots/list request that the client answers from its own handler.
     *
     * Written the 2026-07-28 way: the ask is returned and the answer read off
     * the retry. A handshake-era client reaches the same tool — the SDK sends
     * the `roots/list` request over that connection and re-enters this method.
     *
     * @return array{status: string, message: string, roots?: list<array{uri: string, name: string|null}>}|InputRequiredResult
     */
    #[McpTool(name: 'inspect_workspace_roots', description: 'Ask the client for its workspace roots via a roots/list request.')]
    public function inspectWorkspaceRoots(RequestContext $context): array|InputRequiredResult
    {
        $clientGateway = $context->getClientGateway();

        if (!$clientGateway->supportsRoots()) {
            return [
                'status' => 'unsupported',
                'message' => 'Client does not expose roots. Advertise the "roots" capability and register a ListRootsRequestHandler to let the server discover your workspace folders.',
            ];
        }

        $result = $context->getInputContext()?->rootsResult('roots');

        if (null === $result) {
            return new InputRequiredResult(['roots' => new ListRootsRequest()]);
        }

        $roots = [];
        foreach ($result->roots as $root) {
            $roots[] = ['uri' => $root->uri, 'name' => $root->name];
        }

        $clientGateway->log(LoggingLevel::Info, \sprintf('Client exposed %d root(s).', \count($roots)));

        return [
            'status' => 'ok',
            'message' => \sprintf('Client exposed %d root(s).', \count($roots)),
            'roots' => $roots,
        ];
    }

    /**
     * @return array{incident: string, recommended_actions: string, model: string}|InputRequiredResult
     */
    #[McpTool(name: 'coordinate_incident_response', description: 'Coordinate an incident response with logging, progress, and sampling.')]
    public function coordinateIncident(RequestContext $context, string $incidentTitle): array|InputRequiredResult
    {
        $clientGateway = $context->getClientGateway();

        // A retry re-enters this method from the top, so the triage work below
        // must run only once: check for the answer first, before repeating logs,
        // progress notifications and simulated work the client already saw.
        $result = $context->getInputContext()?->samplingResult('recommendation');

        if (null === $result) {
            $clientGateway->log(LoggingLevel::Warning, \sprintf('Incident triage started: %s', $incidentTitle));

            $steps = [
                'Collecting telemetry',
                'Assessing scope',
                'Coordinating responders',
            ];

            foreach ($steps as $index => $step) {
                $progress = ($index + 1) / \count($steps);

                $clientGateway->progress($progress, 1, $step);

                usleep(180_000); // Simulate work being done
            }

            $prompt = \sprintf(
                'Provide a concise response strategy for incident "%s" based on the steps completed: %s.',
                $incidentTitle,
                implode(', ', $steps)
            );

            return new InputRequiredResult(['recommendation' => new CreateSamplingMessageRequest(
                messages: [new SamplingMessage(Role::User, new TextContent($prompt))],
                maxTokens: 350,
                temperature: 0.5,
            )]);
        }

        $recommendation = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';

        $clientGateway->log(LoggingLevel::Info, \sprintf('Incident triage completed for %s', $incidentTitle));

        return [
            'incident' => $incidentTitle,
            'recommended_actions' => $recommendation,
            'model' => $result->model,
        ];
    }
}
