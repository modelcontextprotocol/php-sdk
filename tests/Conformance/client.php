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

use Mcp\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
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

@mkdir(__DIR__.'/logs', 0777, true);
$logger = new FileLogger(__DIR__.'/logs/client-conformance.log', true);
$logger->info(sprintf('Starting client conformance test: scenario=%s, url=%s', $scenario, $url));

$builder = Client::builder()
    ->setClientInfo('mcp-conformance-test-client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->setLogger($logger);

if ('elicitation-sep1034-client-defaults' === $scenario) {
    $builder->setCapabilities(new ClientCapabilities(elicitation: true));
    $builder->addRequestHandler(new class($logger) implements RequestHandlerInterface {
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
    });
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
            $toolName = $toolsResult->tools[0]->name ?? 'test-tool';
            $client->callTool($toolName, []);
            $logger->info(sprintf('Called tool: %s', $toolName));
            break;

        case 'elicitation-sep1034-client-defaults':
            $toolName = $toolsResult->tools[0]->name ?? 'test_client_elicitation_defaults';
            $client->callTool($toolName, []);
            $logger->info(sprintf('Called tool: %s', $toolName));
            break;

        default:
            $logger->warning(sprintf('Unknown scenario: %s', $scenario));
            break;
    }

    $client->disconnect();
    $logger->info('Disconnected');
    exit(0);
} catch (Throwable $e) {
    $logger->error(sprintf('Error: %s', $e->getMessage()));
    fwrite(\STDERR, sprintf("Error: %s\n%s\n", $e->getMessage(), $e->getTraceAsString()));

    try {
        $client->disconnect();
    } catch (Throwable $ignored) {
    }

    exit(1);
}
