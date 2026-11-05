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
 * OAuth Client Example.
 *
 * Connects to an MCP server that requires authorization. The client starts with no
 * credentials at all: the server's 401 is what sets everything in motion, and the SDK
 * takes it from there -- metadata discovery, client registration, PKCE, the browser,
 * the token, and the retry.
 *
 * Usage: php examples/client/oauth_client.php
 *
 * Before running, start the Keycloak-protected server example:
 *   cd examples/server/oauth-keycloak && docker compose up -d
 *
 * Sign in as demo / demo123 when the browser opens. Tokens are cached in
 * examples/client/.oauth-credentials.json, so the second run needs no browser at all --
 * delete the file to start over.
 */

require_once __DIR__.'/../../vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\AuthorizationException;

$endpoint = 'http://localhost:8000/mcp';

$client = Client::builder()
    ->setClientInfo('OAuth Example Client', '1.0.0')
    ->setInitTimeout(120)
    ->setRequestTimeout(60)
    ->build();

$auth = OAuth::forApplication('MCP PHP SDK Example')
    // The realm this example ships pre-registers a public client, so there is nothing
    // to register. Drop this line against a server that offers dynamic registration.
    ->setClientCredentials('mcp-client')
    ->setCredentialStorage(new FileCredentialStorage(__DIR__.'/.oauth-credentials.json'))
    ->build();

try {
    echo "Connecting to {$endpoint}...\n";
    echo "A browser window will open for you to sign in, unless a stored token is still good.\n\n";

    $client->connect(new HttpTransport($endpoint, auth: $auth));

    echo "Connected as an authorized client.\n\n";

    echo "Available tools:\n";
    foreach ($client->listTools()->tools as $tool) {
        echo "  - {$tool->name}: {$tool->description}\n";
    }

    $client->disconnect();
} catch (AuthorizationException $e) {
    echo "Could not authorize: {$e->getMessage()}\n";

    exit(1);
}
