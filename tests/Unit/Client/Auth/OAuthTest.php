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

use Http\Discovery\Psr18ClientDiscovery;
use Http\Discovery\Strategy\DiscoveryStrategy;
use Mcp\Client\Auth\ClientMetadata;
use Mcp\Client\Auth\CrossAppAccess;
use Mcp\Client\Auth\Grant;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\RedirectAuthorizationHandler;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Mcp\Exception\InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OAuthTest extends TestCase
{
    #[TestDox('an application client defaults to a loopback redirect and the code grant')]
    public function testApplicationDefaults(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App'));
        $this->assertSame(OAuth::DEFAULT_REDIRECT_URI, $configuration->redirectUri);
        $this->assertSame(Grant::AuthorizationCode, $configuration->grant);
        $this->assertSame('My App', $configuration->clientMetadata->clientName);
        $this->assertSame(['http://127.0.0.1:8765/callback'], $configuration->clientMetadata->redirectUris);
        $this->assertSame('native', $configuration->clientMetadata->applicationType);
        $this->assertTrue($configuration->offlineAccess);
        $this->assertNull($configuration->clientId);
    }

    #[TestDox('a Symfony client passed in stops following redirects, so one cannot carry a client secret elsewhere')]
    public function testPassedClientStopsFollowingRedirects(): void
    {
        $seen = [];

        $this->challenge(OAuth::forApplication('My App')->setHttpClient(new Psr18Client($this->recordingRedirects($seen))));

        $this->assertNotSame([], $seen);
        $this->assertSame([0], array_values(array_unique($seen)));
    }

    #[TestDox('a discovered Symfony client stops following redirects, so one cannot carry a client secret elsewhere')]
    public function testDiscoveredClientStopsFollowingRedirects(): void
    {
        $seen = [];

        $strategy = new class implements DiscoveryStrategy {
            public static ClientInterface $client;

            public static function getCandidates($type): array
            {
                return ClientInterface::class === $type ? [['class' => static fn (): ClientInterface => self::$client]] : [];
            }
        };
        $strategy::$client = new Psr18Client($this->recordingRedirects($seen));

        $strategies = [...Psr18ClientDiscovery::getStrategies()];
        Psr18ClientDiscovery::prependStrategy($strategy::class);

        try {
            $this->challenge(OAuth::forApplication('My App'));
        } finally {
            Psr18ClientDiscovery::setStrategies($strategies);
        }

        $this->assertNotSame([], $seen);
        $this->assertSame([0], array_values(array_unique($seen)));
    }

    #[TestWith(['client', 'secret'])]
    #[TestWith(['client', null])]
    #[TestDox('a configured client needs an expected issuer')]
    public function testConfiguredClientNeedsAnExpectedIssuer(string $clientId, ?string $clientSecret): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected issuer');

        OAuth::forApplication('My App')->setClientCredentials($clientId, $clientSecret)->build();
    }

    #[TestDox('the redirect handler needs a storage that outlives the request')]
    public function testRedirectHandlerNeedsAStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('setCredentialStorage()');

        OAuth::forApplication('My App')->setAuthorizationHandler(new RedirectAuthorizationHandler())->build();
    }

    #[TestDox('a client that registers itself needs no expected issuer')]
    public function testDynamicRegistrationNeedsNoExpectedIssuer(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App'));

        $this->assertNull($configuration->expectedIssuer);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidClientMetadataUrlProvider(): iterable
    {
        yield 'plain http' => ['http://app.example.com/client.json'];
        yield 'no path' => ['https://app.example.com'];
        yield 'the root path' => ['https://app.example.com/'];
        yield 'not a URL' => ['client.json'];
        yield 'a space in the host' => ['https://app example.com/client.json'];
        yield 'a fragment' => ['https://app.example.com/client.json#main'];
        yield 'a username' => ['https://user@app.example.com/client.json'];
        yield 'a password' => ['https://user:secret@app.example.com/client.json'];
        yield 'a single-dot segment' => ['https://app.example.com/./client.json'];
        yield 'a double-dot segment' => ['https://app.example.com/a/../client.json'];
        yield 'an encoded dot segment' => ['https://app.example.com/a/%2e%2e/client.json'];
        yield 'a backslash dot segment' => ['https://app.example.com/a\\..\\client.json'];
        yield 'plain http on loopback' => ['http://localhost/client.json'];
    }

    #[DataProvider('invalidClientMetadataUrlProvider')]
    #[TestDox('an invalid client metadata URL is refused: $_dataName')]
    public function testRejectsAnInvalidClientMetadataUrl(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        OAuth::forApplication('My App')->setClientMetadataUrl($url);
    }

    #[TestDox('a client metadata URL may contain dots and a query')]
    public function testAcceptsAValidClientMetadataUrl(): void
    {
        $url = 'https://app.example.com/v1.2/client.json?tenant=acme';

        $this->assertSame($url, $this->configurationOf(OAuth::forApplication('My App')->setClientMetadataUrl($url))->clientMetadataUrl);
    }

    #[TestWith(['http://app.example.com/callback'])]
    #[TestWith(['http://0.0.0.0:8765/callback'])]
    #[TestWith(['http://192.168.1.5:8765/callback'])]
    #[TestWith(['com.example.app:/callback'])]
    #[TestWith(['http://127.0.0.1:8765/callback#fragment'])]
    #[TestWith(['http://user:secret@127.0.0.1:8765/callback'])]
    #[TestDox('a redirect URI must use https or a loopback host')]
    public function testRefusesAnInsecureRedirectUri(string $redirectUri): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('redirect URI');

        OAuth::forApplication('My App')->setRedirectUri($redirectUri)->build();
    }

    #[TestWith(['https://app.example.com/callback'])]
    #[TestWith(['http://localhost:3000/callback'])]
    #[TestWith(['http://[::1]:3000/callback'])]
    #[TestDox('a redirect URI on https or a loopback host is accepted')]
    public function testAcceptsASecureRedirectUri(string $redirectUri): void
    {
        $this->assertSame($redirectUri, $this->configurationOf(OAuth::forApplication('My App')->setRedirectUri($redirectUri))->redirectUri);
    }

    #[TestDox('the redirect URI the handler listens on is the one registered')]
    public function testRedirectUriIsUsedForRegistration(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App')->setRedirectUri('http://127.0.0.1:9999/cb'));

        $this->assertSame('http://127.0.0.1:9999/cb', $configuration->redirectUri);
        $this->assertSame(['http://127.0.0.1:9999/cb'], $configuration->clientMetadata->redirectUris);
    }

    #[TestDox('a client that wants no refresh token does not register for the grant either')]
    public function testOfflineAccessDrivesTheRegisteredGrants(): void
    {
        $with = $this->configurationOf(OAuth::forApplication('My App'));
        $without = $this->configurationOf(OAuth::forApplication('My App')->setOfflineAccess(false));

        $this->assertSame(['authorization_code', 'refresh_token'], $with->clientMetadata->grantTypes);
        $this->assertSame(['authorization_code'], $without->clientMetadata->grantTypes);
    }

    #[TestDox('a nameless client is refused, because the user would see the name')]
    public function testClientNameIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OAuth::forApplication('  ');
    }

    #[TestDox('an application type outside the two OIDC values is refused')]
    public function testApplicationTypeIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OAuth::forApplication('My App')->setApplicationType('mobile');
    }

    #[TestDox('registration metadata carries the software identity when given')]
    public function testSoftwareInfo(): void
    {
        $metadata = $this->configurationOf(OAuth::forApplication('My App')
            ->setSoftwareInfo('https://my-app.example.com', 'my-app', '2.1.0'))->clientMetadata;

        $body = $metadata->toArray(TokenEndpointAuthMethod::None);

        $this->assertSame('https://my-app.example.com', $body['client_uri']);
        $this->assertSame('my-app', $body['software_id']);
        $this->assertSame('2.1.0', $body['software_version']);
    }

    #[TestDox('a service account authenticates as itself, with no user and no refresh token')]
    public function testServiceAccountDefaults(): void
    {
        $configuration = $this->configurationOf(OAuth::forServiceAccount('My Job', 'job-client', 'job-secret')->setExpectedIssuer('https://auth.example.com'));

        $this->assertSame(Grant::ClientCredentials, $configuration->grant);
        $this->assertSame('job-client', $configuration->clientId);
        $this->assertSame('job-secret', $configuration->clientSecret);
        $this->assertFalse($configuration->offlineAccess);
        $this->assertSame(['client_credentials'], $configuration->clientMetadata->grantTypes);
    }

    #[TestDox('cross-app access switches to the assertion grant')]
    public function testCrossAppAccessSwitchesGrant(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App')
            ->setClientCredentials('client', 'secret')
            ->setExpectedIssuer('https://auth.example.com')
            ->setCrossAppAccess(new CrossAppAccess('https://idp.example.com/token', 'an-id-token')));

        $crossAppAccess = $configuration->crossAppAccess;

        $this->assertSame(Grant::JwtBearer, $configuration->grant);
        $this->assertNotNull($crossAppAccess);
        $this->assertSame('https://idp.example.com/token', $crossAppAccess->tokenEndpoint);
        $this->assertSame(CrossAppAccess::ID_TOKEN_TYPE, $crossAppAccess->identityTokenType);
    }

    #[TestDox('cross-app access without a client id is refused: the grant is bound to one')]
    public function testCrossAppAccessNeedsAClientId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OAuth::forApplication('My App')
            ->setCrossAppAccess(new CrossAppAccess('https://idp.example.com/token', 'an-id-token'))
            ->build();
    }

    #[TestDox('a service account needs a client secret or a private key')]
    public function testServiceAccountNeedsACredential(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('client secret or a private key');

        OAuth::forServiceAccount('My Job', 'job-client')->build();
    }

    #[TestDox('an unsupported signing algorithm is refused when building')]
    public function testPrivateKeyRefusesAnUnsupportedAlgorithm(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported client assertion algorithm "HS256"');

        OAuth::forServiceAccount('My Job', 'job-client')
            ->setPrivateKeyJwt('a-pem', 'HS256')
            ->setExpectedIssuer('https://auth.example.com')
            ->build();
    }

    #[TestWith([Grant::RefreshToken])]
    #[TestWith([Grant::TokenExchange])]
    #[TestDox('a grant that cannot authorize a client on its own is refused: $grant')]
    public function testRefusesAGrantThatCannotAuthorize(Grant $grant): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be used to authorize a client');

        new OAuthConfiguration(new ClientMetadata('My App'), grant: $grant);
    }

    #[TestDox('the JWT bearer grant needs cross-app access')]
    public function testJwtBearerGrantNeedsCrossAppAccess(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only work together');

        new OAuthConfiguration(new ClientMetadata('My App'), grant: Grant::JwtBearer, clientId: 'client', expectedIssuer: 'https://auth.example.com');
    }

    #[TestDox('a private key needs an expected issuer')]
    public function testPrivateKeyNeedsAnExpectedIssuer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected issuer');

        OAuth::forServiceAccount('My Job', 'job-client')->setPrivateKeyJwt('a-pem', 'ES256')->build();
    }

    #[TestDox('a service account can authenticate with only a private key')]
    public function testServiceAccountWithAPrivateKey(): void
    {
        $configuration = $this->configurationOf(OAuth::forServiceAccount('My Job', 'job-client')->setPrivateKeyJwt('a-pem', 'ES256')->setExpectedIssuer('https://auth.example.com'));

        $this->assertSame('a-pem', $configuration->privateKeyPem);
        $this->assertSame('ES256', $configuration->signingAlgorithm);
    }

    #[TestDox('the identity token is not sent to an insecure identity provider')]
    public function testCrossAppAccessRefusesAnInsecureIdentityProvider(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CrossAppAccess('http://idp.example.com/token', 'an-id-token');
    }

    /**
     * @param int[] $seen
     */
    private function recordingRedirects(array &$seen): MockHttpClient
    {
        if (!(new \ReflectionClass(Psr18Client::class))->hasMethod('withOptions')) {
            $this->markTestSkipped('Psr18Client has no withOptions() before symfony/http-client 6.2.');
        }

        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['max_redirects'];

            return new MockResponse('', ['http_code' => 404]);
        });
    }

    private function challenge(OAuth $oauth): void
    {
        try {
            $oauth->build()->handleChallenge((new Psr17Factory())->createRequest('POST', 'https://mcp.example.com/mcp'), new Response(401));
        } catch (AuthorizationException) {
            // Discovery finds nothing here, only its requests matter.
        }
    }

    private function configurationOf(OAuth $oauth): OAuthConfiguration
    {
        return (new \ReflectionProperty(OAuthAuthenticator::class, 'configuration'))->getValue($oauth->build());
    }
}
