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
 * Server for {@see \Mcp\Tests\Integration\HttpNegotiationTest}, run under `php -S`.
 *
 * MCP_INTEGRATION_HANDSHAKE_ONLY makes it a server from before the modern era,
 * MCP_INTEGRATION_MODERN_ONLY one that serves nothing else.
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StatelessHttpTransport;
use Mcp\Server\Transport\StreamableHttpTransport;

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

$builder = Server::builder()
    ->setServerInfo('integration-server', '1.0.0')
    ->setInstructions('Be brief.')
    // Nothing survives between requests under `php -S`, so the handshake era
    // keeps its sessions on disk.
    ->setSession(new FileSessionStore((string) getenv('MCP_INTEGRATION_SESSIONS')))
    ->addTool(static fn (string $text): string => $text, name: 'echo', description: 'Echoes the text back.');

if ('' !== (string) getenv('MCP_INTEGRATION_HANDSHAKE_ONLY')) {
    $builder->withoutModernEra();
}

$request = (new Psr17Factory())->createServerRequestFromGlobals();

$response = '' !== (string) getenv('MCP_INTEGRATION_MODERN_ONLY')
    ? (new StatelessHttpTransport($builder->buildStateless()))->handle($request)
    : $builder->build()->run(new StreamableHttpTransport($request));

(new SapiEmitter())->emit($response);
