<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Modern-era client (2026-07-28).
 *
 * The counterpart to examples/server/stateless-lifecycle. What is worth noticing
 * is how little of it is about the lifecycle: one call to setProtocolVersion()
 * and everything else is the same API as any other client.
 *
 * Underneath, that one call changes the wire completely:
 *   - no `initialize` handshake — the connection is usable immediately, and
 *     `server/discover` is asked only for the server's identity
 *   - every request carries its own `_meta` envelope naming the revision and
 *     what this client can be asked to do (SEP-2575)
 *   - every POST carries `Mcp-Method`, and `Mcp-Name` where the method
 *     addresses a subject, so an intermediary can route without reading the
 *     body (SEP-2243)
 *   - a tool that needs input is answered and retried by the client, so the
 *     caller sees one call and one result (SEP-2322)
 *
 * Usage:
 *   1. Start the server: php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php
 *   2. Run this script:  php examples/client/stateless_lifecycle_client.php
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Result\ElicitResult;

/**
 * Answers the server's request for input.
 *
 * In this revision the server cannot interrupt a call to ask — it returns the
 * question as the result. The handler is the same shape either way, which is
 * the point: a client written for the handshake era keeps working.
 */
$answerWithAName = new class implements RequestHandlerInterface {
    public function supports(Request $request): bool
    {
        return $request instanceof ElicitRequest;
    }

    public function handle(Request $request): Response
    {
        assert($request instanceof ElicitRequest);

        echo "  server asked: {$request->message}\n";

        return new Response($request->getId(), new ElicitResult(ElicitAction::Accept, ['name' => 'Ada']));
    }
};

$client = Client::builder()
    ->setClientInfo('stateless-example-client', '1.0.0')
    // The only line that selects the modern lifecycle.
    ->setProtocolVersion(ProtocolVersion::V2026_07_28)
    // Declared in the envelope of every request, so the server knows what it
    // may ask for before it decides how to answer.
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($answerWithAName)
    ->build();

$client->connect(new HttpTransport('http://127.0.0.1:8000/'));

printf("Connected to %s (revision %s)\n\n", $client->getServerInfo()?->name, $client->getProtocolVersion()?->value);

echo "Tools:\n";
foreach ($client->listTools()->tools as $tool) {
    printf("  %-12s %s\n", $tool->name, $tool->description ?? '');
}

echo "\nA plain call:\n";
echo '  '.text($client->callTool('get_weather', ['city' => 'Munich']))."\n";

echo "\nA call the server cannot finish in one round:\n";
// One call from here. Two on the wire: the server returns its question, the
// handler above answers it, and the client retries carrying both the answer and
// the server's sealed `requestState`.
echo '  '.text($client->callTool('greet', []))."\n";

$client->disconnect();

/** The first block of text in a tool result. */
function text(CallToolResult $result): string
{
    $first = $result->content[0] ?? null;

    return $first instanceof TextContent ? $first->text : '(no text)';
}
