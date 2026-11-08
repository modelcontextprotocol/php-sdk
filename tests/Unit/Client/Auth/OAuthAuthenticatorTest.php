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

use Mcp\Client\Auth\AccessToken;
use Mcp\Client\Auth\AuthorizationHandlerInterface;
use Mcp\Client\Auth\ClientRegistrar;
use Mcp\Client\Auth\ClientRegistration;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
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
        $authenticator->handleChallenge($rejected, $server->challenge(['mcp:write'], 403, 'insufficient_scope'));

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
    }

    #[TestDox('a step-up challenge adds its scope to the configured ones instead of refreshing the token')]
    public function testStepUpExtendsTheConfiguredScopes(): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')->setScopes('mcp:read'));

        $authenticator->handleChallenge($this->request(), $server->challenge(['mcp:admin']));
        $this->assertSame('mcp:read', $server->authorization['scope'] ?? null);

        $rejected = $authenticator->authenticate($this->request());
        $authenticator->handleChallenge($rejected, $server->challenge(['mcp:write'], 403, 'insufficient_scope'));

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
        $this->assertSame('authorization_code', $server->token['grant_type'] ?? null);
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

    #[TestDox('offline_access refused when the code is exchanged is given up, as when the authorization endpoint refuses it')]
    public function testGivesUpOfflineAccessRefusedAtTheTokenEndpoint(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read'], serverScopes: ['mcp:read', 'offline_access'], refuseOfflineAccess: true);
        $authenticator = $this->authenticator($server);

        $this->assertTrue($authenticator->handleChallenge($this->request(), $server->challenge()));

        $this->assertSame('mcp:read', $server->authorization['scope'] ?? null);
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('offline_access refused once is not asked for again on step-up')]
    public function testDoesNotAskAgainForARefusedOfflineAccess(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read'], serverScopes: ['mcp:read', 'mcp:write', 'offline_access'], refuseOfflineAccess: true);
        $authenticator = $this->authenticator($server);

        $authenticator->handleChallenge($this->request(), $server->challenge());
        $rejected = $authenticator->authenticate($this->request());
        $authenticator->handleChallenge($rejected, $server->challenge(['mcp:write'], 403, 'insufficient_scope'));

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
        $this->assertCount(3, array_keys($server->requested, 'https://auth.example.com/token', true));
    }

    #[TestDox('offline_access refused once is not asked for again, even when configured')]
    public function testDoesNotAskAgainForARefusedOfflineAccessThatIsConfigured(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['mcp:read', 'mcp:write', 'offline_access'], refuseOfflineAccess: true);
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')->setScopes('mcp:read', 'offline_access'));

        $authenticator->handleChallenge($this->request(), $server->challenge());
        $rejected = $authenticator->authenticate($this->request());
        $authenticator->handleChallenge($rejected, $server->challenge(['mcp:write'], 403, 'insufficient_scope'));

        $this->assertSame('mcp:read mcp:write', $server->authorization['scope'] ?? null);
        $this->assertCount(3, array_keys($server->requested, 'https://auth.example.com/token', true));
    }

    #[TestDox('a code refused for another reason than offline_access fails the flow instead of starting another one')]
    public function testKeepsOfflineAccessWhenTheCodeIsRefusedForAnotherReason(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read'], serverScopes: ['mcp:read', 'offline_access'], codeError: 'invalid_grant');
        $authenticator = $this->authenticator($server);

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The refused code should have failed the flow.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('invalid_grant', $e->getMessage());
        }

        $this->assertSame('mcp:read offline_access', $server->authorization['scope'] ?? null);
        $this->assertCount(1, array_keys($server->requested, 'https://auth.example.com/token', true));
    }

    #[TestDox('a stored token is reused even though offline_access was not granted with it')]
    public function testReusesAStoredTokenWithoutOfflineAccess(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read'], serverScopes: ['mcp:read', 'offline_access'], refreshTokens: true, refuseOfflineAccess: true);
        $storage = new InMemoryCredentialStorage();

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());
        $requestsAfterFirstFlow = \count($server->requested);

        $second = $this->authenticator($server, storage: $storage);
        $second->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('Bearer access-token-1', $second->authenticate($this->request())->getHeaderLine('Authorization'));
        $this->assertSame($requestsAfterFirstFlow + 2, \count($server->requested));
    }

    #[TestDox('an expired token is refreshed even though offline_access was not granted with it')]
    public function testRefreshesATokenWithoutOfflineAccess(): void
    {
        $server = new FakeOAuthServer(resourceScopes: ['mcp:read'], serverScopes: ['mcp:read', 'offline_access'], refreshTokens: true);
        $storage = new InMemoryCredentialStorage();
        $storage->saveToken('https://auth.example.com', self::ENDPOINT, new AccessToken('expired-token', 'Bearer', time() - 60, 'refresh-token', ['mcp:read']));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('refresh_token', $server->token['grant_type'] ?? null);
        $this->assertSame([], $server->authorization);
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

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('forbiddenChallengeProvider')]
    #[TestDox('a 403 that does not ask for more scopes leaves the user out of it')]
    public function testDeclinesAForbiddenResponseWithoutInsufficientScope(array $headers): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);
        $authenticator->handleChallenge($this->request(), $server->challenge());
        $requested = $server->requested;

        $sent = $authenticator->authenticate($this->request());

        $this->assertFalse($authenticator->handleChallenge($sent, new Response(403, $headers)));
        $this->assertSame($requested, $server->requested);
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function forbiddenChallengeProvider(): iterable
    {
        yield 'no challenge' => [[]];
        yield 'a challenge without an error' => [['WWW-Authenticate' => 'Bearer scope="admin"']];
        yield 'another error' => [['WWW-Authenticate' => 'Bearer error="invalid_token"']];
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

    // The transport builds one authenticator per endpoint, but an authenticator is an
    // ordinary object: sharing one across two servers must not carry the first server's
    // token to the second.
    #[TestDox('a token is not attached to a request aimed at a different server')]
    public function testTokenIsBoundToItsResource(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);

        $authenticator->handleChallenge($this->request(), $server->challenge());

        $elsewhere = (new Psr17Factory())->createRequest('POST', 'https://other.example.com/mcp');

        $this->assertSame('', $authenticator->authenticate($elsewhere)->getHeaderLine('Authorization'));
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('a failed authorization for another resource does not make the token available to it')]
    public function testFailedAuthorizationKeepsTheTokenBoundToItsResource(): void
    {
        $server = new FakeOAuthServer();
        $handler = new class(new StubAuthorizationHandler($server)) implements AuthorizationHandlerInterface {
            public bool $fails = false;

            public function __construct(private readonly AuthorizationHandlerInterface $handler)
            {
            }

            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                if ($this->fails) {
                    throw new AuthorizationException('The user canceled the authorization.');
                }

                return $this->handler->authorize($authorizationUrl, $redirectUri);
            }
        };
        $authenticator = $this->authenticator($server, $handler);
        $authenticator->handleChallenge($this->request(), $server->challenge());

        $elsewhere = (new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/other');
        $server->resource = 'https://mcp.example.com/other';
        $handler->fails = true;

        try {
            $authenticator->handleChallenge($elsewhere, $server->challenge());
            $this->fail('The authorization should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertSame('The user canceled the authorization.', $e->getMessage());
        }

        $this->assertSame('', $authenticator->authenticate($elsewhere)->getHeaderLine('Authorization'));
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('an error without the state of this request does not count as an answer')]
    public function testRejectsAForgedErrorResponse(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['offline_access']);
        $handler = new class implements AuthorizationHandlerInterface {
            public int $calls = 0;

            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                ++$this->calls;

                return ['error' => 'invalid_scope'];
            }
        };

        try {
            $this->authenticator($server, $handler)->handleChallenge($this->request(), $server->challenge());
            $this->fail('The authorization should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('state parameter', $e->getMessage());
        }

        $this->assertSame(1, $handler->calls);
    }

    #[TestDox('an error with the state of this request is reported')]
    public function testReportsAnAuthorizationError(): void
    {
        $server = new FakeOAuthServer();
        $handler = new class implements AuthorizationHandlerInterface {
            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

                return ['error' => 'access_denied', 'state' => \is_string($query['state'] ?? null) ? $query['state'] : ''];
            }
        };

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('denied the request: access_denied');

        $this->authenticator($server, $handler)->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('an authorization response with no state at all is refused')]
    public function testRejectsAMissingState(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, new StubAuthorizationHandler($server, state: ''));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('state parameter');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    #[TestDox('an authorization server offering plain HTTP endpoints is not followed')]
    public function testRejectsInsecureEndpoints(): void
    {
        $server = new FakeOAuthServer(insecureEndpoints: true);
        $authenticator = $this->authenticator($server);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('No usable metadata');

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    // A challenge pointing at somebody else's host is asking the client to fetch a URL
    // on the server's behalf, from wherever the client happens to run.
    #[TestDox('a metadata location on another host is ignored, not fetched')]
    public function testIgnoresACrossOriginMetadataUrl(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server);

        $challenge = new Response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="http://169.254.169.254/latest/meta-data/"']);
        $authenticator->handleChallenge($this->request(), $challenge);

        $this->assertNotContains('http://169.254.169.254/latest/meta-data/', $server->requested);
        // The well-known locations are probed instead, so the flow still completes.
        $this->assertContains('https://mcp.example.com/.well-known/oauth-protected-resource/mcp', $server->requested);
    }

    #[TestDox('a metadata location on the same server is followed')]
    public function testFollowsASameOriginMetadataUrl(): void
    {
        $server = new FakeOAuthServer();
        $challenge = new Response(401, ['WWW-Authenticate' => 'Bearer resource_metadata="https://mcp.example.com/custom/location.json"']);

        $this->authenticator($server)->handleChallenge($this->request(), $challenge);

        $this->assertContains('https://mcp.example.com/custom/location.json', $server->requested);
    }

    // Building the well-known URLs out of this identifier and requesting them is already
    // a request made on the server's behalf, so the check has to precede the fetch.
    #[TestDox('an authorization server on a plain-HTTP host is refused before it is contacted')]
    public function testRefusesAnInsecureIssuerWithoutFetchingIt(): void
    {
        $server = new FakeOAuthServer(authorizationServer: 'http://10.0.0.5:8080/internal');
        $authenticator = $this->authenticator($server);

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The insecure authorization server should have been refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('neither an HTTPS URL nor a loopback address', $e->getMessage());
        }

        foreach ($server->requested as $url) {
            $this->assertStringNotContainsString('10.0.0.5', $url, 'The issuer must not be contacted before it is validated.');
        }
    }

    // An application overriding the RFC 8707 resource parameter is choosing which token
    // to ask for, not where it may be spent -- the token still has to reach the server.
    #[TestDox('an overridden resource parameter does not stop the token reaching the server')]
    public function testOverriddenResourceStillAuthenticatesTheEndpoint(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')->setResource('https://api.example.com/v1'));

        $authenticator->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('https://api.example.com/v1', $server->authorization['resource'] ?? null);
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('a client id configured up front is used instead of registering')]
    public function testSkipsRegistrationForAKnownClient(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client', 'known-secret')
            ->setExpectedIssuer('https://auth.example.com'));

        $authenticator->handleChallenge($this->request(), $server->challenge());

        $this->assertNotContains('https://auth.example.com/register', $server->requested);
        $this->assertSame('known-client', $server->authorization['client_id'] ?? null);
    }

    #[TestDox('a server on plain http is refused before anything is fetched')]
    public function testRefusesAnInsecureServer(): void
    {
        $server = new FakeOAuthServer();
        $request = (new Psr17Factory())->createRequest('POST', 'http://mcp.example.com/mcp');

        try {
            $this->authenticator($server)->handleChallenge($request, $server->challenge());
            $this->fail('The insecure server was not refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('http://mcp.example.com/mcp', $e->getMessage());
        }

        $this->assertSame([], $server->requested);
    }

    #[TestDox('legacy discovery does not make a server on plain http acceptable')]
    public function testLegacyDiscoveryRefusesAnInsecureServer(): void
    {
        $server = new FakeOAuthServer();
        $request = (new Psr17Factory())->createRequest('POST', 'http://mcp.example.com/mcp');
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')->setLegacyDiscovery(true));

        $this->expectException(AuthorizationException::class);

        $authenticator->handleChallenge($request, $server->challenge());
    }

    #[TestDox('a server without an authorization endpoint is refused before the browser opens')]
    public function testRefusesAMissingAuthorizationEndpoint(): void
    {
        $server = new FakeOAuthServer(hasAuthorizationEndpoint: false);

        try {
            $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());
            $this->fail('The missing authorization endpoint was not refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('no authorization endpoint', $e->getMessage());
        }

        $this->assertSame([], $server->authorization);
        $this->assertNotContains('https://auth.example.com/token', $server->requested);
    }

    #[TestDox('a client secret never reaches an authorization server other than the expected one')]
    public function testRefusesAnUnexpectedIssuer(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client', 'known-secret')
            ->setExpectedIssuer('https://trusted.example.com'));

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The unexpected issuer was not refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('https://trusted.example.com', $e->getMessage());
        }

        $this->assertSame([], $server->authorization);
        $this->assertSame([], $server->token);
        $this->assertNotContains('https://auth.example.com/token', $server->requested);
    }

    #[TestDox('a step-up challenge authorizes again, even if the token reports no scopes to compare')]
    public function testStepUpDoesNotRefreshATokenWithoutReportedScopes(): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $storage = new InMemoryCredentialStorage();
        $authenticator = $this->authenticator($server, storage: $storage);

        $authenticator->handleChallenge($this->request(), $server->challenge());
        $rejected = $authenticator->authenticate($this->request());
        $authenticator->handleChallenge($rejected, $server->challenge(['admin'], 403, 'insufficient_scope'));

        $this->assertSame('authorization_code', $server->token['grant_type'] ?? null);
        $this->assertSame('admin', $server->authorization['scope'] ?? null);
    }

    #[TestDox('a refreshed token keeps the stored scopes when the response names none')]
    public function testRefreshKeepsTheStoredScopes(): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $storage = new InMemoryCredentialStorage();
        $storage->saveToken('https://auth.example.com', self::ENDPOINT, new AccessToken('expired', 'Bearer', time() - 60, 'a-refresh-token', ['mcp:read', 'mcp:write']));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge(['mcp:read']));

        $this->assertSame('refresh_token', $server->token['grant_type'] ?? null);
        $this->assertSame(['mcp:read', 'mcp:write'], $storage->getToken('https://auth.example.com', self::ENDPOINT)?->scopes);
    }

    #[TestDox('a refresh does not record scopes the authorization server did not grant')]
    public function testRefreshDoesNotInventScopes(): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $storage = new InMemoryCredentialStorage();
        $storage->saveToken('https://auth.example.com', self::ENDPOINT, new AccessToken('expired', 'Bearer', time() - 60, 'a-refresh-token'));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge(['admin']));

        $this->assertSame('refresh_token', $server->token['grant_type'] ?? null);
        $this->assertSame([], $storage->getToken('https://auth.example.com', self::ENDPOINT)?->scopes);
    }

    #[TestDox('a new redirect URI gets a new registration, as the old one would be refused')]
    public function testRegistersAgainForAnotherRedirectUri(): void
    {
        $server = new FakeOAuthServer();
        $storage = new InMemoryCredentialStorage();
        $storage->saveClientRegistration('https://auth.example.com', new ClientRegistration('old-client', dynamic: true, redirectUris: ['http://127.0.0.1:9999/callback']));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('registered-client', $server->authorization['client_id'] ?? null);
        $this->assertSame([OAuth::DEFAULT_REDIRECT_URI], $storage->getClientRegistration('https://auth.example.com')?->redirectUris);
    }

    #[TestDox('a registration for the same redirect URI is reused')]
    public function testReusesARegistrationForTheSameRedirectUri(): void
    {
        $server = new FakeOAuthServer();
        $storage = new InMemoryCredentialStorage();
        $storage->saveClientRegistration('https://auth.example.com', new ClientRegistration('stored-client', dynamic: true, redirectUris: [OAuth::DEFAULT_REDIRECT_URI]));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());

        $this->assertNotContains('https://auth.example.com/register', $server->requested);
        $this->assertSame('stored-client', $server->authorization['client_id'] ?? null);
    }

    #[TestDox('a registered client the authorization server no longer knows registers again')]
    public function testRegistersAgainWhenTheClientIsUnknown(): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $server->unknownClient = 'forgotten-client';
        $storage = new InMemoryCredentialStorage();
        $storage->saveClientRegistration('https://auth.example.com', new ClientRegistration('forgotten-client', dynamic: true));
        $storage->saveToken('https://auth.example.com', self::ENDPOINT, new AccessToken('expired', 'Bearer', time() - 60, 'a-refresh-token'));

        $authenticator = $this->authenticator($server, storage: $storage);
        $authenticator->handleChallenge($this->request(), $server->challenge());

        $this->assertContains('https://auth.example.com/register', $server->requested);
        $this->assertSame('registered-client', $server->token['client_id'] ?? null);
        $this->assertSame('authorization_code', $server->token['grant_type'] ?? null);
        $this->assertSame('registered-client', $storage->getClientRegistration('https://auth.example.com')?->clientId);
        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
    }

    #[TestDox('a code the unknown client cannot exchange fails once, and the next attempt registers again')]
    public function testDropsAnUnknownClientAfterTheCodeExchange(): void
    {
        $server = new FakeOAuthServer();
        $server->unknownClient = 'forgotten-client';
        $storage = new InMemoryCredentialStorage();
        $storage->saveClientRegistration('https://auth.example.com', new ClientRegistration('forgotten-client', dynamic: true));
        $authenticator = $this->authenticator($server, storage: $storage);

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The unknown client should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
        }

        $this->assertNotContains('https://auth.example.com/register', $server->requested);
        $this->assertNull($storage->getClientRegistration('https://auth.example.com'));

        $authenticator->handleChallenge($this->request(), $server->challenge());

        $this->assertSame('registered-client', $server->token['client_id'] ?? null);
    }

    #[TestDox('a client that was not registered dynamically is not replaced when it is unknown')]
    public function testDoesNotReplaceAConfiguredClient(): void
    {
        $server = new FakeOAuthServer();
        $server->unknownClient = 'known-client';
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')->setClientCredentials('known-client'));

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The unknown client should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('invalid_client', $e->getMessage());
        }

        $this->assertNotContains('https://auth.example.com/register', $server->requested);
    }

    #[TestDox('an error that only mentions a refused scope in its text does not send the user back')]
    public function testErrorDescriptionDoesNotTriggerTheOfflineAccessFallback(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['offline_access']);
        $handler = new class implements AuthorizationHandlerInterface {
            public int $calls = 0;

            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                ++$this->calls;
                parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

                return ['error' => 'access_denied', 'error_description' => 'not_allowed', 'state' => \is_string($query['state'] ?? null) ? $query['state'] : ''];
            }
        };

        try {
            $this->authenticator($server, $handler)->handleChallenge($this->request(), $server->challenge());
            $this->fail('The denied authorization should have failed.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('access_denied', $e->getMessage());
        }

        $this->assertSame(1, $handler->calls);
    }

    #[TestDox('an offline_access refused at the authorization endpoint is given up')]
    public function testGivesUpOfflineAccessRefusedAtTheAuthorizationEndpoint(): void
    {
        $server = new FakeOAuthServer(serverScopes: ['offline_access']);
        $handler = new class(new StubAuthorizationHandler($server)) implements AuthorizationHandlerInterface {
            public int $calls = 0;

            public function __construct(private readonly AuthorizationHandlerInterface $handler)
            {
            }

            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                if (1 === ++$this->calls) {
                    parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

                    return ['error' => 'invalid_scope', 'state' => \is_string($query['state'] ?? null) ? $query['state'] : ''];
                }

                return $this->handler->authorize($authorizationUrl, $redirectUri);
            }
        };

        $this->authenticator($server, $handler)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame(2, $handler->calls);
        $this->assertArrayNotHasKey('scope', $server->authorization);
    }

    #[TestDox('a scope and a code of "0" reach the authorization server')]
    public function testKeepsAScopeAndACodeOfZero(): void
    {
        $server = new FakeOAuthServer();
        $handler = new class($server) implements AuthorizationHandlerInterface {
            public function __construct(private readonly FakeOAuthServer $server)
            {
            }

            public function authorize(string $authorizationUrl, string $redirectUri): array
            {
                $this->server->recordAuthorization($authorizationUrl);
                parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);

                return ['code' => '0', 'state' => \is_string($query['state'] ?? null) ? $query['state'] : '', 'iss' => 'https://auth.example.com'];
            }
        };

        $this->authenticator($server, $handler)->handleChallenge($this->request(), $server->challenge(['0']));

        $this->assertSame('0', $server->authorization['scope'] ?? null);
        $this->assertSame('0', $server->token['code'] ?? null);
    }

    /**
     * @param ?string[] $grantTypes
     */
    #[TestWith([null, 'refresh_token'])]
    #[TestWith([['authorization_code', 'refresh_token'], 'refresh_token'])]
    #[TestWith([['authorization_code'], 'authorization_code'])]
    #[TestDox('a refresh token is only left unused if the server lists its grants without refresh_token')]
    public function testUsesARefreshTokenUnlessTheGrantIsNotListed(?array $grantTypes, string $expectedGrant): void
    {
        $server = new FakeOAuthServer(refreshTokens: true);
        $server->grantTypes = $grantTypes;
        $storage = new InMemoryCredentialStorage();
        $storage->saveToken('https://auth.example.com', self::ENDPOINT, new AccessToken('expired', 'Bearer', time() - 60, 'a-refresh-token'));

        $this->authenticator($server, storage: $storage)->handleChallenge($this->request(), $server->challenge());

        $this->assertSame($expectedGrant, $server->token['grant_type'] ?? null);
    }

    #[TestDox('the expected issuer is chosen wherever the resource lists it')]
    public function testChoosesTheExpectedIssuerFromTheList(): void
    {
        $server = new FakeOAuthServer();
        $server->otherAuthorizationServers = ['https://other.example.com'];
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client')
            ->setExpectedIssuer('https://auth.example.com'));

        $this->assertTrue($authenticator->handleChallenge($this->request(), $server->challenge()));

        $this->assertSame('Bearer access-token-1', $authenticator->authenticate($this->request())->getHeaderLine('Authorization'));
        $this->assertSame([], array_filter($server->requested, static fn (string $url): bool => str_contains($url, 'other.example.com')));
    }

    #[TestDox('an authorization server that is not expected is not even contacted')]
    public function testDoesNotContactAnUnexpectedIssuer(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client')
            ->setExpectedIssuer('https://trusted.example.com'));

        try {
            $authenticator->handleChallenge($this->request(), $server->challenge());
            $this->fail('The unexpected issuer was not refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('https://trusted.example.com', $e->getMessage());
        }

        $this->assertSame(['https://mcp.example.com/.well-known/oauth-protected-resource/mcp'], $server->requested);
    }

    #[TestDox('an expected issuer also binds a client without a secret')]
    public function testExpectedIssuerBindsAPublicClient(): void
    {
        $server = new FakeOAuthServer();
        $authenticator = $this->authenticator($server, configuration: OAuth::forApplication('test')
            ->setClientCredentials('known-client')
            ->setExpectedIssuer('https://trusted.example.com'));

        $this->expectException(AuthorizationException::class);

        $authenticator->handleChallenge($this->request(), $server->challenge());
    }

    /**
     * @return iterable<string, array{?string[]}>
     */
    public static function serverWithoutPkceProvider(): iterable
    {
        yield 'no list' => [null];
        yield 'an empty list' => [[]];
        yield 'only plain' => [['plain']];
    }

    /**
     * @param ?string[] $methods
     */
    #[DataProvider('serverWithoutPkceProvider')]
    #[TestDox('a server without S256 PKCE is refused before the browser opens: $_dataName')]
    public function testRefusesAServerWithoutPkce(?array $methods): void
    {
        $server = new FakeOAuthServer(codeChallengeMethods: $methods);

        try {
            $this->authenticator($server)->handleChallenge($this->request(), $server->challenge());
            $this->fail('The server without PKCE was not refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('code_challenge_methods_supported', $e->getMessage());
        }

        $this->assertSame([], $server->authorization);
        $this->assertNotContains('https://auth.example.com/register', $server->requested);
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

        $echoed = \is_string($query['state'] ?? null) ? $query['state'] : null;

        return array_filter([
            'code' => 'test-authorization-code',
            // An empty configured state stands for a response that carries none at all.
            'state' => '' === $this->state ? null : ($this->state ?? $echoed),
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

    /** @var string[] */
    public array $otherAuthorizationServers = [];

    public ?string $unknownClient = null;

    /** @var ?string[] */
    public ?array $grantTypes = ['authorization_code', 'refresh_token'];

    private int $issued = 0;

    /**
     * @param ?string[] $resourceScopes
     * @param string[]  $serverScopes
     * @param ?string[] $codeChallengeMethods
     */
    public function __construct(
        public string $resource = 'https://mcp.example.com/mcp',
        private readonly ?array $resourceScopes = null,
        private readonly array $serverScopes = [],
        private readonly bool $insecureEndpoints = false,
        private readonly string $authorizationServer = 'https://auth.example.com',
        private readonly bool $refreshTokens = false,
        private readonly bool $refuseOfflineAccess = false,
        private readonly ?string $codeError = null,
        private readonly bool $hasAuthorizationEndpoint = true,
        private readonly ?array $codeChallengeMethods = ['S256'],
    ) {
    }

    /**
     * @param string[] $scopes
     */
    public function challenge(array $scopes = [], int $status = 401, ?string $error = null): ResponseInterface
    {
        $parameters = 'resource_metadata="https://mcp.example.com/.well-known/oauth-protected-resource/mcp"';

        if ([] !== $scopes) {
            $parameters = \sprintf('scope="%s", ', implode(' ', $scopes)).$parameters;
        }

        if (null !== $error) {
            $parameters = \sprintf('error="%s", ', $error).$parameters;
        }

        return new Response($status, ['WWW-Authenticate' => 'Bearer '.$parameters]);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();
        $this->requested[] = $url;
        parse_str((string) $request->getBody(), $parsed);
        $form = [];

        foreach ($parsed as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $form[$name] = (string) $value;
            }
        }

        return match ($url) {
            'https://mcp.example.com/custom/location.json',
            'https://mcp.example.com/.well-known/oauth-protected-resource/mcp' => self::json(array_filter([
                'resource' => $this->resource,
                'authorization_servers' => [...$this->otherAuthorizationServers, $this->authorizationServer],
                'scopes_supported' => $this->resourceScopes,
            ], static fn (mixed $value): bool => null !== $value)),

            'https://auth.example.com/.well-known/oauth-authorization-server' => self::json([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => $this->hasAuthorizationEndpoint ? ($this->insecureEndpoints ? 'http' : 'https').'://auth.example.com/authorize' : null,
                'token_endpoint' => ($this->insecureEndpoints ? 'http' : 'https').'://auth.example.com/token',
                'registration_endpoint' => ($this->insecureEndpoints ? 'http' : 'https').'://auth.example.com/register',
                'scopes_supported' => $this->serverScopes,
                'grant_types_supported' => $this->hasAuthorizationEndpoint ? $this->grantTypes : ['client_credentials'],
                'code_challenge_methods_supported' => $this->codeChallengeMethods,
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
        $flat = [];

        foreach ($query as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $flat[$name] = (string) $value;
            }
        }
        $this->authorization = $flat;
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
        $flat = [];

        foreach ($form as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $flat[$name] = (string) $value;
            }
        }
        $this->token = $flat;
        $scope = $this->authorization['scope'] ?? '';

        if (null !== $this->unknownClient && $this->unknownClient === ($form['client_id'] ?? null)) {
            return self::json(['error' => 'invalid_client'], 401);
        }

        // Keycloak accepts offline_access at the authorization endpoint, and only refuses it
        // here, for a user who may not have offline tokens.
        if ($this->refuseOfflineAccess && 'authorization_code' === $form['grant_type'] && \in_array('offline_access', explode(' ', $scope), true)) {
            return self::json(['error' => 'not_allowed', 'error_description' => 'Offline tokens not allowed for the user or client'], 400);
        }

        if (null !== $this->codeError && 'authorization_code' === $form['grant_type']) {
            return self::json(['error' => $this->codeError], 400);
        }

        if (!$this->refreshTokens) {
            return self::json(['access_token' => 'access-token-'.++$this->issued, 'token_type' => 'Bearer', 'expires_in' => 3600]);
        }

        return self::json(['access_token' => 'access-token-'.++$this->issued, 'token_type' => 'Bearer', 'expires_in' => 3600, 'refresh_token' => 'refresh-token-'.$this->issued, 'scope' => $scope]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function json(array $payload, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($payload));
    }
}
