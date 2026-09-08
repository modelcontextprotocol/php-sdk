<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Client;
use Mcp\Client\Auth\FileCredentialStorage;
use Mcp\Client\Auth\HeadlessAuthorizationHandler;
use Mcp\Client\Auth\InMemoryCredentialStorage;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The whole authorization flow over real HTTP, against two real processes.
 *
 * Everything below the client is genuine: an authorization server that publishes its
 * metadata, registers the client, enforces PKCE and issues a token, and an MCP server
 * built with this SDK that refuses anything else. Nothing is stubbed, so what these
 * assert is that a caller who writes the four lines in the documentation ends up
 * authorized.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ClientOAuthTest extends TestCase
{
    private Process $authorizationServer;
    private Process $resourceServer;
    private string $authorizationServerUrl;
    private string $resourceServerUrl;
    private string $workingDirectory;

    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir().'/mcp-oauth-integration-'.bin2hex(random_bytes(6));

        if (!mkdir($this->workingDirectory, 0700, true) && !is_dir($this->workingDirectory)) {
            $this->markTestSkipped('Could not create a working directory for the fixture servers.');
        }

        $environment = [
            'MCP_OAUTH_STATE' => $this->workingDirectory.'/state.json',
            'MCP_OAUTH_SESSIONS' => $this->workingDirectory,
        ];

        [$this->authorizationServer, $this->authorizationServerUrl] = $this->serve('authorization-server.php', $environment);

        $environment['MCP_OAUTH_AUTHORIZATION_SERVER'] = $this->authorizationServerUrl;

        [$this->resourceServer, $this->resourceServerUrl] = $this->serve('resource-server.php', $environment);
    }

    protected function tearDown(): void
    {
        $this->authorizationServer->stop();
        $this->resourceServer->stop();

        foreach (glob($this->workingDirectory.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        @rmdir($this->workingDirectory);
    }

    #[TestDox('a client with no credentials at all ends up calling a protected tool')]
    public function testAuthorizesFromNothing(): void
    {
        $client = $this->connect();

        $this->assertSame(['whoami'], array_map(static fn ($tool) => $tool->name, $client->listTools()->tools));
        $this->assertStringContainsString('the token was accepted', json_encode($client->callTool('whoami', [])->content));

        $client->disconnect();
    }

    #[TestDox('the authorization request carries PKCE, the resource, and the challenged scope')]
    public function testAuthorizationRequestContents(): void
    {
        $this->connect()->disconnect();

        $query = $this->state()['authorization_query'] ?? [];

        $this->assertSame('code', $query['response_type'] ?? null);
        $this->assertSame('S256', $query['code_challenge_method'] ?? null);
        $this->assertNotEmpty($query['code_challenge'] ?? '');
        $this->assertNotEmpty($query['state'] ?? '');
        $this->assertSame($this->resourceServerUrl.'/mcp', $query['resource'] ?? null);
        // "mcp:read" is what the challenge asked for; "offline_access" is added because
        // the authorization server advertises it and a refresh token is worth having.
        $this->assertSame('mcp:read offline_access', $query['scope'] ?? null);
    }

    #[TestDox('the client registers itself, naming what it is and what it will do')]
    public function testDynamicRegistration(): void
    {
        $this->connect()->disconnect();

        $registration = $this->state()['registration'] ?? [];

        $this->assertSame('Integration Test Client', $registration['client_name'] ?? null);
        $this->assertSame('native', $registration['application_type'] ?? null);
        $this->assertContains('refresh_token', $registration['grant_types'] ?? []);
    }

    #[TestDox('the token request authenticates the client the way the server asked it to')]
    public function testTokenRequestUsesTheRegisteredAuthMethod(): void
    {
        $this->connect()->disconnect();
        $state = $this->state();

        $this->assertSame('authorization_code', $state['token_request']['grant_type'] ?? null);
        $this->assertSame($this->resourceServerUrl.'/mcp', $state['token_request']['resource'] ?? null);
        $this->assertSame(
            'Basic '.base64_encode('dynamic-client:dynamic-secret'),
            $state['token_authorization'] ?? null,
        );
    }

    #[TestDox('a second run reuses the stored credentials instead of registering again')]
    public function testStoredCredentialsAreReused(): void
    {
        $storage = new FileCredentialStorage($this->workingDirectory.'/credentials.json');

        $this->connect($storage)->disconnect();
        $firstToken = $this->state()['issued_token'] ?? null;

        $this->connect($storage)->disconnect();

        $this->assertNotNull($firstToken);
        $this->assertSame($firstToken, $this->state()['issued_token'] ?? null, 'The second run should not have gone back to the token endpoint.');
    }

    #[TestDox('a failed authorization is reported once, not retried until the user gives up')]
    public function testAFailedAuthorizationIsNotRetried(): void
    {
        file_put_contents($this->workingDirectory.'/state.json', json_encode(['fail_token' => true]));

        try {
            $this->connect();
            $this->fail('The client should not have connected.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
        }

        // Connecting retries on a connection failure, and an authorization the server
        // refused is not one: a second attempt would only send the user back to the
        // browser for the same answer.
        $this->assertSame(1, $this->state()['authorization_count'] ?? 0);
    }

    #[TestDox('a client that cannot authorize fails loudly rather than hanging')]
    public function testUnauthorizedClientFails(): void
    {
        $client = Client::builder()->setClientInfo('integration-client', '1.0.0')->setInitTimeout(5)->build();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('rejected the request with 401');

        $client->connect(new HttpTransport($this->resourceServerUrl.'/mcp'));
    }

    #[TestDox('the headless handler reads the code out of a redirect without following it')]
    public function testHeadlessHandlerAgainstARealEndpoint(): void
    {
        $url = $this->authorizationServerUrl.'/authorize?'.http_build_query([
            'redirect_uri' => 'http://127.0.0.1:8765/callback',
            'state' => 'a-state',
        ]);

        $parameters = (new HeadlessAuthorizationHandler())->authorize($url, 'http://127.0.0.1:8765/callback');

        $this->assertSame('integration-authorization-code', $parameters['code'] ?? null);
        $this->assertSame('a-state', $parameters['state'] ?? null);
        $this->assertSame($this->authorizationServerUrl, $parameters['iss'] ?? null);
    }

    #[TestDox('an endpoint that does not redirect is reported as needing a real user')]
    public function testHeadlessHandlerNeedsARedirect(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('interactive authorization handler is required');

        (new HeadlessAuthorizationHandler())->authorize($this->authorizationServerUrl.'/nothing-here', 'http://127.0.0.1:8765/callback');
    }

    private function connect(?FileCredentialStorage $storage = null): Client
    {
        $client = Client::builder()
            ->setClientInfo('integration-client', '1.0.0')
            ->setInitTimeout(10)
            ->setRequestTimeout(10)
            ->build();

        $auth = OAuth::forApplication('Integration Test Client')
            ->setAuthorizationHandler(new HeadlessAuthorizationHandler())
            ->setCredentialStorage($storage ?? new InMemoryCredentialStorage())
            ->build();

        $client->connect(new HttpTransport($this->resourceServerUrl.'/mcp', auth: $auth));

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $path = $this->workingDirectory.'/state.json';

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    /**
     * Start one of the fixture routers under the built-in web server.
     *
     * @param array<string, string> $environment
     *
     * @return array{Process, string}
     */
    private function serve(string $fixture, array $environment): array
    {
        $port = self::freePort();
        $process = new Process(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/Fixture/oauth/'.$fixture],
            __DIR__,
            $environment + ['MCP_OAUTH_PORT' => (string) $port],
        );
        $process->start();

        $url = 'http://127.0.0.1:'.$port;
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $probe = @fsockopen('127.0.0.1', $port, $code, $message, 0.2);

            if (false !== $probe) {
                fclose($probe);

                return [$process, $url];
            }

            usleep(50_000);
        }

        $this->fail(\sprintf('The fixture server "%s" did not start: %s', $fixture, $process->getErrorOutput()));
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) explode(':', (string) stream_socket_get_name($socket, false))[1];
        fclose($socket);

        return $port;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDirectory($file) : unlink($file);
        }

        @rmdir($directory);
    }
}
