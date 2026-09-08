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

use Mcp\Client\Auth\CrossAppAccess;
use Mcp\Client\Auth\Grant;
use Mcp\Client\Auth\OAuth;
use Mcp\Client\Auth\OAuthAuthenticator;
use Mcp\Client\Auth\OAuthConfiguration;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase
{
    #[TestDox('an application client defaults to a loopback redirect and the code grant')]
    public function testApplicationDefaults(): void
    {
        $configuration = $this->configurationOf(OAuth::forApplication('My App'));

        $this->assertSame(Grant::AuthorizationCode, $configuration->grant);
        $this->assertSame(OAuth::DEFAULT_REDIRECT_URI, $configuration->redirectUri);
        $this->assertSame('My App', $configuration->clientMetadata->clientName);
        $this->assertSame(['http://127.0.0.1:8765/callback'], $configuration->clientMetadata->redirectUris);
        $this->assertSame('native', $configuration->clientMetadata->applicationType);
        $this->assertTrue($configuration->offlineAccess);
        $this->assertNull($configuration->clientId);
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

    #[TestDox('a service account authenticates as itself, with no user and no refresh token')]
    public function testServiceAccountDefaults(): void
    {
        $configuration = $this->configurationOf(OAuth::forServiceAccount('My Job', 'job-client', 'job-secret'));

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

    private function configurationOf(OAuth $oauth): OAuthConfiguration
    {
        return (new \ReflectionProperty(OAuthAuthenticator::class, 'configuration'))->getValue($oauth->build());
    }
}
