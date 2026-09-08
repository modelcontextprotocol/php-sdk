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
 * A real MCP server behind a bearer-token gate, run under `php -S`.
 *
 * The gate is deliberately hand-rolled rather than the SDK's own authorization
 * middleware: the point of the test is the client half, so the server side stays a
 * plain RFC 9728 resource -- metadata at the path-based location, a challenge naming
 * it, and a token it recognises.
 */

require_once dirname(__DIR__, 4).'/vendor/autoload.php';

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;

$authorizationServer = getenv('MCP_OAUTH_AUTHORIZATION_SERVER') ?: '';
$statePath = getenv('MCP_OAUTH_STATE') ?: '';
$sessionPath = getenv('MCP_OAUTH_SESSIONS') ?: sys_get_temp_dir();

$self = 'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);

if ('/.well-known/oauth-protected-resource/mcp' === $path) {
    header('Content-Type: application/json');
    echo json_encode([
        'resource' => $self.'/mcp',
        'authorization_servers' => [$authorizationServer],
        'scopes_supported' => ['mcp:read'],
    ]);

    return;
}

$state = is_file($statePath) ? (json_decode((string) file_get_contents($statePath), true) ?: []) : [];
$presented = preg_match('/^Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $matches) ? trim($matches[1]) : null;

if (null === $presented || !isset($state['issued_token']) || !hash_equals((string) $state['issued_token'], $presented)) {
    http_response_code(401);
    header(sprintf('WWW-Authenticate: Bearer scope="mcp:read", resource_metadata="%s/.well-known/oauth-protected-resource/mcp"', $self));
    header('Content-Type: application/json');
    echo json_encode(['error' => 'invalid_token']);

    return;
}

$server = Server::builder()
    ->setServerInfo('oauth-integration-server', '1.0.0')
    ->setSession(new FileSessionStore($sessionPath))
    ->addTool(
        static fn (): string => 'the token was accepted',
        name: 'whoami',
        description: 'Reports that the request was authorized.',
    )
    ->build();

$response = $server->run(new StreamableHttpTransport(
    (new Psr17Factory())->createServerRequestFromGlobals(),
));

(new SapiEmitter())->emit($response);
