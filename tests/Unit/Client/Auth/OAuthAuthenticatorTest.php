<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Auth;

use Mcp\Client\Auth\AuthorizationHandlerInterface;
use Mcp\Client\Auth\ClientRegistrar;
use Mcp\Client\Auth\InMemoryCredentialStorage;
use Mcp\Client\Auth\MetadataDiscovery;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\Pkce;
use Mcp\Client\Auth\TokenEndpoint;
use Mcp\Exception\AuthorizationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class OAuthAuthenticatorTest extends TestCase
{
    private const ENDPOINT = 'https://mcp.example.com/mcp';

    #[TestDox('a challenge drives discovery, registration and the code exchange in one pass')]
    public function testCompletesTheFlowFromASingleChallenge(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);

        $this->assertTrue($authenticator->handleChallenge($this->request(), $server->challenge()));

        $authenticated = $authenticator->authenticate($this->request());
        $this->assertSame('Bearer access-token-1', $authenticated->getHeaderLine('Authorization'));

        $this->assertSame([
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp',
            'https://auth.example.com/.well-known/oauth-authorization-server',
            'https://auth.example.com/register',
            'https://auth.example.com/token',
        ], $server->requested);
    }

    #[TestDox('the registration names the application type and the grants the client will use')]
    public function testRegistrationBody(): void
    {
        $server = new FakeOAuthServer();
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('native', $server->registration['application_type'] ?? null);
        $this->assertSame(['authorization_code', 'refresh_token'], $server->registration['grant_types'] ?? null);
        $this->assertSame(['http://127.0.0.1:8765/callback'], $server->registration['redirect_uris'] ?? null);
    }

    #[TestDox('the code exchange proves possession of the verifier behind the challenge')]
    public function testPkceVerifierMatchesTheChallenge(): void
    {
        $server = new FakeOAuthServer();
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('S256', $server->authorization['code_challenge_method'] ?? null);
        $this->assertSame(
            $server->authorization['code_challenge'] ?? null,
            Pkce::fromVerifier($server->token['code_verifier'] ?? '')->challenge,
        );
    }

    #[TestDox('the resource the token is for is named consistently in both requests')]
    public function testResourceParameter(): void
    {
        $server = new FakeOAuthServer();
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame(self::ENDPOINT, $server->authorization['resource'] ?? null);
        $this->assertSame(self::ENDPOINT, $server->token['resource'] ?? null);
    }

    #[TestDox('the scope named by the challenge is what gets requested')]
    public function testUsesTheChallengedScope(): void
    {
        $server = new FakeOAuthServer();
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge(['mcp:read']));

        $this->assertSame('mcp:read', $server->authorization['scope'] ?? null);
    }

    #[TestDox('with nothing challenged, the resource\'s own scopes are asked for in full')]
    public function testFallsBackToTheResourceScopes(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read', 'mcp:write']);
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
    }

    #[TestDox('with no scopes to be had, the parameter is left off entirely')]
    public function testOmitsTheScopeParameter(): void
    {
        $server = new FakeOAuthServer();
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertArrayNotHasKey('scope', $server->authorization);
    }

    #[TestDox('a step-up challenge keeps the scopes already granted alongside the new one')]
    public function testUnionsScopesOnReAuthorization(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);

        $authenticator->handleChallenge($this->request(), $server->challenge(['mcp:read']));
        $rejected = $authenticator->authenticate($this->request());
        $authenticator->handleChallenge($rejected, $server->challenge(['mcp:write'], 403));

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
    }

    #[TestDox('offline_access is requested only where the authorization server offers it')]
    public function testRequestsOfflineAccessWhenSupported(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['mcp:read', 'offline_access'], resourceScopes: ['mcp:read']);
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('mcp:read offline_access', $server->authorization['scope'] ?? null);
    }

    #[TestDox('offline_access is not invented when the authorization server never mentioned it')]
    public function testDoesNotRequestUnsupportedOfflineAccess(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['mcp:read'], resourceScopes: ['mcp:read']);
        $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('mcp:read', $server->authorization['scope'] ?? null);
    }

    #[TestDox('metadata naming an unrelated resource aborts the flow before any token is asked for')]
    public function testRejectsAResourceMismatch(): void
    {
        $server = new FakeOAuthServer(resource: 'https://evil.example.com/mcp');
        $authenticator = $this->authenticator($server);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('declares the unrelated resource');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('an authorization response naming the wrong issuer is refused')]
    public function testRejectsAMismatchedIssuer(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, new StubAuthorizationHandler($server, issuer: 'https://evil.example.com'));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('names the issuer');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('a missing issuer is refused when the authorization server said it would send one')]
    public function testRejectsAMissingIssuer(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, new StubAuthorizationHandler($server, issuer: null));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('did not carry one');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('a mismatched state is refused, however the code got back')]
    public function testRejectsAMismatchedState(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, new StubAuthorizationHandler($server, state: 'not-the-state'));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('state parameter');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('a stored token is reused instead of sending the user through the browser again')]
    public function testReusesAStoredToken(): void
    {
        $server = new FakeOAuthServer();
        $storage = new InMemoryCredentialStorage();

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());
        $requestsAfterFirstFlow = \count($server->requested);

        $second = $this->authenticator($server, storage: $storage);
        $second->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('Bearer access-token-1', $second->authenticate($this->request())->getHeaderLine('Authorization'));
        // Only the two discovery documents; no registration, no authorization, no token.
        $this->assertSame($requestsAfterFirstFlow + 2, \count($server->requested));
    }

    #[TestDox('a token the server has just rejected is not offered back to it')]
    public function testDoesNotReplayARejectedToken(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);

        $authenticator->handleChallenge($this->request(), $server->challenge());
        $rejected = $authenticator->authenticate($this->request());

        // Same scopes, unexpired, and still in storage -- but the server answered 401
        // to this very token, so reusing it would only earn the same answer.
        $authenticator->handleChallenge($rejected, $server->challenge());

        $this->assertSame('Bearer access-token-2', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('a client id configured up front is used instead of registering')]
    public function testSkipsRegistrationForAKnownClient(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client', 'known-secret'));

        $authenticator->handleChallenge($this->request(), $server->challenge());

        $this->assertNotContains('https://auth.example.com/register', $server->requested);
        $this->assertSame('known-client', $server->authorization['client_id'] ?? null);
    }

    private function request(): RequestInterface
    {
        return (new Psr17Factory())->createRequest('POST', self::ENDPOINT);
    }

    private function authenticator(
        FakeOAuthServer $server,
        ?AuthorizationHandlerInterface $handler = null,
        ?InMemoryCredentialStorage $storage = null,
        ?OAuth $configuration = null,
    ): OAuthAuthenticator {
        $factory = new Psr17Factory();

        return new OAuthAuthenticator(
            $this->configuration($configuration),
            $storage ?? new InMemoryCredentialStorage(),
            $handler ?? new StubAuthorizationHandler($server),
            new MetadataDiscovery($server, $factory),
            new ClientRegistrar($server, $factory, $factory),
            new TokenEndpoint($server, $factory, $factory),
        );
    }

    /**
     * Reach into the builder for the configuration it would hand the authenticator,
     * so the test and the public API cannot drift apart.
     */
    private function configuration(?OAuth $oauth): OAuthConfiguration
    {
        $oauth ??= OAuth::forApplication('test-client');
        $property = new \ReflectionProperty(OAuthAuthenticator::class, 'configuration');

        return $property->getValue($oauth->setAuthorizationHandler(new StubAuthorizationHandler())->build());
    }
}

/**
 * The authorization endpoint's answer, as a well-behaved user agent would report it.
 */
final class StubAuthorizationHandler implements AuthorizationHandlerInterface
{
    public function __construct(
        private readonly ?FakeOAuthServer $server = null,
        private readonly ?string $issuer = 'https://auth.example.com',
        private readonly ?string $state = null,
    ) {
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        $this->server?->recordAuthorization($authorizationUrl);
        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

        return array_filter([
            'code' => 'test-authorization-code',
            'state' => $this->state ?? ($query['state'] ?? null),
            'iss' => $this->issuer,
        ], static fn (?string $value): bool => null !== $value);
    }
}

/**
 * A protected resource and its authorization server, answering over PSR-18.
 */
final class FakeOAuthServer implements ClientInterface
{
    /** @var string[] */
    public array $requested = [];

    /** @var array<string, mixed> */
    public array $registration = [];

    /** @var array<string, string> */
    public array $authorization = [];

    /** @var array<string, string> */
    public array $token = [];

    private int $issued = 0;

    /**
     * @param ?string[] $resourceScopes
     * @param string[]  $serverScopes
     */
    public function __construct(
        private readonly string $resource = 'https://mcp.example.com/mcp',
        private readonly ?array $resourceScopes = null,
        private readonly array $serverScopes = [],
    ) {
    }

    /**
     * @param string[] $scopes
     */
    public function challenge(array $scopes = [], int $status = 401): ResponseInterface
    {
        $parameters = 'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"';

        if ([] !== $scopes) {
            $parameters = \sprintf('scope="%s", ', implode(' ', $scopes)).$parameters;
        }

        return new Response($status, ['WWW-Authenticate' => 'Bearer '.$parameters]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        parse_str((string) $request->getBody(), $form);

        return match ($url) {
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => self::json(array_filter([
                'resource' => $this->resource,
                'authorization_servers' => ['https://auth.example.com'],
                'scopes_supported' => $this->resourceScopes,
            ], static fn (mixed $value): bool => null !== $value)),

            'https://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'registration_endpoint' => 'https://auth.example.com/register',
                'scopes_supported' => $this->serverScopes,
                'grant_types_supported' => ['authorization_code', 'refresh_token'],
                'code_challenge_methods_supported' => ['S256'],
                'authorization_response_iss_parameter_supported' => true,
            ]),

            'https://auth.example.com/register' => $this->register((string) $request->getBody()),
            'https://auth.example.com/token' => $this->issueToken($form),

            default => new Response(404),
        };
    }

    /**
     * Called by the stub handler's caller: the authorization request never leaves the
     * SDK as an HTTP call, so its parameters are recorded from the URL instead.
     */
    public function recordAuthorization(string $url): void
    {
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->authorization = array_map(strval(...), $query);
    }

    private function register(string $body): ResponseInterface
    {
        $this->registration = json_decode($body, true) ?: [];

        return self::json(['client_id' => 'registered-client', 'client_secret' => 'registered-secret'], 201);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function issueToken(array $form): ResponseInterface
    {
        $this->token = array_map(strval(...), $form);

        return self::json(['access_token' => 'access-token-'.++$this->issued, 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }
}
