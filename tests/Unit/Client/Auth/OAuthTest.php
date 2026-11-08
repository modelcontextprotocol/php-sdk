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
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Mcp\Exception\InvalidArgumentException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
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

    #[TestDox('a client that registers itself needs no expected issuer')]
    public function testDynamicRegistrationNeedsNoExpectedIssuer(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App'));

        $this->assertNull($configuration->expectedIssuer);
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
