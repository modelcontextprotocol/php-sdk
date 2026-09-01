<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Tests\Conformance\FileLogger;

$url = $argv[1] ?? null;
$scenario = getenv('MCP_CONFORMANCE_SCENARIO') ?: null;

if (!$url || !$scenario) {
    fwrite(\STDERR, "Usage: MCP_CONFORMANCE_SCENARIO=<scenario> php client.php <server-url>\n");
    exit(1);
}

// The runner names the revision it is testing; without honouring it the client
// would open every scenario with `initialize` and never reach the modern wire.
$version = ProtocolVersion::tryFrom(getenv('MCP_CONFORMANCE_PROTOCOL_VERSION') ?: '')
    ?? ProtocolVersion::V2025_11_25;

// Scenario-specific data (tool arguments, credentials) the runner passes in.
$context = json_decode(getenv('MCP_CONFORMANCE_CONTEXT') ?: '[]', true);
$context = is_array($context) ? $context : [];

@mkdir(__DIR__.'/logs', 0777, true);
$logger = new FileLogger(__DIR__.'/logs/client-conformance.log', true);
$logger->info(sprintf('Starting client conformance test: scenario=%s, url=%s, version=%s', $scenario, $url, $version->value));

$builder = Client::builder()
    ->setClientInfo('mcp-conformance-test-client', '1.0.0')
    ->setProtocolVersion($version)
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->setLogger($logger);

/**
 * Accepts every elicitation with an empty payload.
 *
 * Enough for the scenarios here, which check that the client asked and echoed
 * correctly rather than what a user would have typed.
 */
$acceptElicitation = new class($logger) implements RequestHandlerInterface {
    public function __construct(private readonly Psr\Log\LoggerInterface $logger)
    {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ElicitRequest;
    }

    public function handle(Request $request): Response
    {
        $this->logger->info('Received elicitation request, accepting with empty content');

        return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, []));
    }
};

if (in_array($scenario, ['elicitation-sep1034-client-defaults', 'sep-2322-client-request-state'], true)) {
    $builder->setCapabilities(new ClientCapabilities(elicitation: true));
    $builder->addRequestHandler($acceptElicitation);
}

$client = $builder->build();
$transport = new HttpTransport($url, logger: $logger);

try {
    $client->connect($transport);
    $logger->info('Connected to server');

    $toolsResult = $client->listTools();
    $logger->info(sprintf('Listed %d tools', count($toolsResult->tools)));

    switch ($scenario) {
        case 'initialize':
            break;

        case 'tools_call':
            // The scenario asserts both arguments arrive as numbers, so the
            // call has to be made by name with real values rather than
            // whatever tool happens to be listed first.
            $client->callTool('add_numbers', ['a' => 2, 'b' => 3]);
            $logger->info('Called tool: add_numbers');
            break;

        case 'elicitation-sep1034-client-defaults':
            $toolName = $toolsResult->tools[0]->name ?? 'test_client_elicitation_defaults';
            $client->callTool($toolName, []);
            $logger->info(sprintf('Called tool: %s', $toolName));
            break;

        case 'json-schema-2020-12-preservation':
            // Round-trips the focal tool's inputSchema back through the echo
            // tool so the harness can diff what survived the client's parsing
            // (SEP-1613 keywords, plus the SEP-2106 vocabulary).
            $focal = null;
            foreach ($toolsResult->tools as $tool) {
                if ('json_schema_2020_12_tool' === $tool->name) {
                    $focal = $tool;
                    break;
                }
            }

            if (null === $focal) {
                throw new RuntimeException('Mock server did not advertise json_schema_2020_12_tool.');
            }

            $client->callTool('json_schema_echo', ['schema' => $focal->inputSchema]);
            $logger->info('Echoed the observed inputSchema back via json_schema_echo');
            break;

        case 'http-standard-headers':
            // Exercises every method that carries an Mcp-Method or Mcp-Name
            // header, including the ones whose subject needs Base64 wrapping.
            foreach ($toolsResult->tools as $tool) {
                $client->callTool($tool->name, []);
            }

            $resources = $client->listResources();
            foreach ($resources->resources as $resource) {
                $client->readResource($resource->uri);
            }

            $prompts = $client->listPrompts();
            foreach ($prompts->prompts as $prompt) {
                $client->getPrompt($prompt->name, []);
            }

            $logger->info('Exercised every header-carrying method');
            break;

        case 'http-custom-headers':
            // The runner supplies the exact argument values, each chosen to hit
            // a different corner of the encoding rules.
            foreach ($context['toolCalls'] ?? [] as $call) {
                $client->callTool($call['name'], $call['arguments'] ?? []);
                $logger->info(sprintf('Called tool: %s', $call['name']));
            }
            break;

        case 'http-invalid-tool-headers':
            // Only the tools that survived the listing are callable; calling
            // any of the malformed ones is the failure this scenario looks for.
            foreach ($toolsResult->tools as $tool) {
                $client->callTool($tool->name, ['region' => 'us-west1']);
                $logger->info(sprintf('Called tool: %s', $tool->name));
            }
            break;

        case 'sep-2322-client-request-state':
            // Each tool drives one rule: echo the state back, omit it when none
            // was sent, keep an unrelated call clean, and treat a result with
            // no resultType as complete.
            foreach (['test_mrtr_echo_state', 'test_mrtr_unrelated', 'test_mrtr_no_state', 'test_mrtr_no_result_type'] as $tool) {
                $client->callTool($tool, []);
                $logger->info(sprintf('Called tool: %s', $tool));
            }
            break;

        default:
            $logger->warning(sprintf('Unknown scenario: %s', $scenario));
            break;
    }

    $client->disconnect();
    $logger->info('Disconnected');
    exit(0);
} catch (Throwable $e) {
    $logger->error(sprintf('Error: %s', $e->getMessage()), ['exception' => $e]);
    fwrite(\STDERR, sprintf("Error: %s\n%s\n", $e->getMessage(), $e->getTraceAsString()));

    try {
        $client->disconnect();
    } catch (Throwable $ignored) {
    }

    exit(1);
}
