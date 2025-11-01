<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioClientCommunication;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientAwareTrait;
use Psr\Log\LoggerInterface;

final class ClientAwareService implements ClientAwareInterface
{
    use ClientAwareTrait;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->logger->info('SamplingTool instantiated for sampling example.');
    }

    /**
     * @return array{incident: string, recommended_actions: string, model: string}
     */
    #[McpTool('coordinate_incident_response', 'Coordinate an incident response with logging, progress, and sampling.')]
    public function coordinateIncident(string $incidentTitle): array
    {
        $this->log(LoggingLevel::Warning, \sprintf('Incident triage started: %s', $incidentTitle));

        $steps = [
            'Collecting telemetry',
            'Assessing scope',
            'Coordinating responders',
        ];

        foreach ($steps as $index => $step) {
            $progress = ($index + 1) / \count($steps);

            $this->progress($progress, 1, $step);

            usleep(180_000); // Simulate work being done
        }

        $prompt = \sprintf(
            'Provide a concise response strategy for incident "%s" based on the steps completed: %s.',
            $incidentTitle,
            implode(', ', $steps)
        );

        $result = $this->sample($prompt, 350, 90, ['temperature' => 0.5]);

        $recommendation = $result->content instanceof TextContent ? trim((string) $result->content->text) : '';

        $this->log(LoggingLevel::Info, \sprintf('Incident triage completed for %s', $incidentTitle));

        return [
            'incident' => $incidentTitle,
            'recommended_actions' => $recommendation,
            'model' => $result->model,
        ];
    }
}
