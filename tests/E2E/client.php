<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The end-to-end run: an MCP client that starts with nothing and ends up calling a
 * tool on a server guarded by Keycloak.
 *
 * Everything the client does between those two points -- reading the challenge, finding
 * the protected resource metadata, finding the authorization server, driving PKCE,
 * exchanging the code, presenting the token, and doing it all again from stored
 * credentials -- is the SDK's, not this script's. The script only supplies the browser
 * (see BrowserAuthorizationHandler) and then checks the outcome.
 *
 * Run it through run.sh, which brings the environment up first.
 */

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

use Mcp\Client;
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\AuthorizationException;
use Mcp\Tests\E2E\BrowserAuthorizationHandler;

$endpoint = getenv('MCP_E2E_ENDPOINT') ?: 'http://localhost:8001/mcp';
$username = getenv('MCP_E2E_USERNAME') ?: 'demo';
$password = getenv('MCP_E2E_PASSWORD') ?: 'demo123';
$credentialsFile = getenv('MCP_E2E_CREDENTIALS') ?: sys_get_temp_dir().'/mcp-e2e-credentials.json';

$failures = 0;
$check = static function (string $what, bool $passed, string $detail = '') use (&$failures): void {
    if (!$passed) {
        ++$failures;
    }

    fwrite(\STDOUT, sprintf("%s %s%s\n", $passed ? '  ok  ' : ' FAIL ', $what, '' === $detail ? '' : ' -- '.$detail));
};

$connect = static function (FileCredentialStorage $storage) use ($endpoint, $username, $password): Client {
    $client = Client::builder()
        ->setClientInfo('mcp-client-auth-e2e', '1.0.0')
        ->setInitTimeout(30)
        ->setRequestTimeout(30)
        ->build();

    $client->connect(new HttpTransport($endpoint, auth: OAuth::forApplication('MCP PHP SDK end-to-end client')
        // Keycloak's realm import registers this public client with loopback redirect
        // URIs; a server offering dynamic registration would need none of this.
        ->setClientCredentials('mcp-client')
        ->setAuthorizationHandler(new BrowserAuthorizationHandler($username, $password))
        ->setCredentialStorage($storage)
        ->build()));

    return $client;
};

@unlink($credentialsFile);

fwrite(\STDOUT, "\nUnauthorized client\n");

try {
    $bare = Client::builder()->setClientInfo('mcp-client-auth-e2e', '1.0.0')->setInitTimeout(15)->build();
    $bare->connect(new HttpTransport($endpoint));
    $check('a client with no credentials is refused', false, 'the server let it in');
} catch (AuthorizationException $e) {
    $check('a client with no credentials is refused', str_contains($e->getMessage(), '401'), $e->getMessage());
}

fwrite(\STDOUT, "\nFirst run, no stored credentials\n");

$storage = new FileCredentialStorage($credentialsFile);
$client = $connect($storage);

$tools = array_map(static fn ($tool) => $tool->name, $client->listTools()->tools);
$check('the protected tool list is readable', ['whoami'] === $tools, implode(', ', $tools));

$result = json_encode($client->callTool('whoami', [])->content);
$check('the protected tool answers', str_contains((string) $result, 'authorized'), (string) $result);

$client->disconnect();

$check('the credentials were written to disk', is_file($credentialsFile));

fwrite(\STDOUT, "\nSecond run, reusing what was stored\n");

$second = $connect(new FileCredentialStorage($credentialsFile));
$check('the stored token still opens the server', str_contains((string) json_encode($second->callTool('whoami', [])->content), 'authorized'));
$second->disconnect();

fwrite(\STDOUT, "\n");
fwrite(\STDOUT, 0 === $failures ? "End-to-end authorization succeeded.\n" : sprintf("%d end-to-end check(s) failed.\n", $failures));

exit($failures > 0 ? 1 : 0);
