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
 * STDIO Roots Example.
 *
 * This example demonstrates the "roots" client capability:
 * - The client advertises the `roots` capability during initialization.
 * - It answers server `roots/list` requests via a RootsCallbackInterface,
 *   exposing a couple of `file://` workspace folders.
 * - Calling the server's `inspect_workspace_roots` tool makes the server issue
 *   a `roots/list` request, so the handler below actually runs.
 * - It notifies the server when its list of roots changes.
 *
 * Usage: php examples/client/stdio_roots.php
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Mcp\Client\Client;
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;

$rootsRequestHandler = new ListRootsRequestHandler(new class implements RootsCallbackInterface {
    public function __invoke(ListRootsRequest $request): ListRootsResult
    {
        echo "[ROOTS] Server requested the client's list of roots\n";

        return new ListRootsResult([
            new Root('file:///home/user/projects/app', 'Application'),
            new Root('file:///home/user/projects/library', 'Library'),
        ]);
    }
});

$client = Client::builder()
    ->setClientInfo('STDIO Roots Test', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(120)
    ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true))
    ->addRequestHandler($rootsRequestHandler)
    ->build();

$transport = new StdioTransport(
    command: 'php',
    args: [__DIR__.'/../server/client-communication/server.php'],
);

echo "Connecting to MCP server...\n";
$client->connect($transport);

$serverInfo = $client->getServerInfo();
echo 'Connected to: '.($serverInfo->name ?? 'unknown')."\n\n";

// The server tool asks the client for its roots, which triggers the handler above.
echo "Calling 'inspect_workspace_roots'...\n";
$result = $client->callTool(name: 'inspect_workspace_roots');

echo "\nResult:\n";
foreach ($result->content as $content) {
    if ($content instanceof TextContent) {
        echo $content->text."\n";
    }
}

// Whenever the client's workspace folders change, notify the server so it can
// request an updated list via roots/list.
echo "\nNotifying the server that the roots list changed...\n";
$client->sendRootsListChanged();

$client->disconnect();
