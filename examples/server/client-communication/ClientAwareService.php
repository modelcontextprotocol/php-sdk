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

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
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
     * @return array{status: string, message: string, roots?: list<array{uri: string, name: string|null}>}
     */
    #[McpTool(name: 'inspect_workspace_roots', description: 'Ask the client for its workspace roots via a roots/list request.')]
    public function inspectWorkspaceRoots(RequestContext $context): array
    {
        $clientGateway = $context->getClientGateway();

        if (!$clientGateway->supportsRoots()) {
            return [
                'status' => 'unsupported',
                'message' => 'Client does not expose roots. Advertise the "roots" capability and register a ListRootsRequestHandler to let the server discover your workspace folders.',
            ];
        }

        $result = $clientGateway->listRoots();

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
     * @return array{incident: string, recommended_actions: string, model: string}
     */
    #[McpTool(name: 'coordinate_incident_response', description: 'Coordinate an incident response with logging, progress, and sampling.')]
    public function coordinateIncident(RequestContext $context, string $incidentTitle): array
    {
        $clientGateway = $context->getClientGateway();
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

        $result = $clientGateway->sample($prompt, 350, 90, ['temperature' => 0.5]);

        $recommendation = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';

        $clientGateway->log(LoggingLevel::Info, \sprintf('Incident triage completed for %s', $incidentTitle));

        return [
            'incident' => $incidentTitle,
            'recommended_actions' => $recommendation,
            'model' => $result->model,
        ];
    }
}
