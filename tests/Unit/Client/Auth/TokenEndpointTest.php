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

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mcp\Client\Auth\AuthorizationServerMetadata;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\TokenEndpoint;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class TokenEndpointTest extends TestCase
{
    /**
     * @return iterable<string, array{TokenEndpointAuthMethod, ?string, array<string, string>}>
     */
    public static function authenticationProvider(): iterable
    {
        yield 'a public client sends only its id' => [
            TokenEndpointAuthMethod::None,
            null,
            ['client_id' => 'a-client'],
        ];

        yield 'a secret in the header' => [
            TokenEndpointAuthMethod::ClientSecretBasic,
            'Basic '.base64_encode('a-client:a-secret'),
            ['client_id' => 'a-client'],
        ];

        yield 'a secret in the body' => [
            TokenEndpointAuthMethod::ClientSecretPost,
            null,
            ['client_id' => 'a-client', 'client_secret' => 'a-secret'],
        ];
    }

    #[DataProvider('authenticationProvider')]
    #[TestDox('the client authenticates with: $_dataName')]
    public function testClientAuthentication(TokenEndpointAuthMethod $method, ?string $authorization, array $expectedFields): void
    {
        $server = new CapturingTokenServer();
        $this->endpoint($server)->request(
            $this->metadata(),
            new ClientRegistration('a-client', 'a-secret', $method),
            ['grant_type' => 'authorization_code', 'code' => 'a-code'],
        );

        $this->assertSame($authorization, $server->authorization);

        foreach ($expectedFields as $field => $value) {
            $this->assertSame($value, $server->form[$field] ?? null, \sprintf('Expected "%s" in the request body.', $field));
        }

        if (TokenEndpointAuthMethod::ClientSecretPost !== $method) {
            $this->assertArrayNotHasKey('client_secret', $server->form);
        }
    }

    // RFC 6749 section 2.3.1 form-urlencodes both halves before joining them, which is
    // the difference between a secret containing a colon working and silently not.
    #[TestDox('a Basic credential is form-encoded before it is joined')]
    public function testBasicCredentialsAreEncoded(): void
    {
        $server = new CapturingTokenServer();
        $this->endpoint($server)->request(
            $this->metadata(),
            new ClientRegistration('a client', 'se:cret', TokenEndpointAuthMethod::ClientSecretBasic),
            ['grant_type' => 'authorization_code'],
        );

        $this->assertSame('a%20client:se%3Acret', base64_decode(substr((string) $server->authorization, 6), true));
    }

    #[RequiresPhpExtension('openssl')]
    #[TestDox('a private key assertion verifies against the public key, and names the client')]
    public function testPrivateKeyJwtAssertion(): void
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privateKey);

        $server = new CapturingTokenServer();
        $this->endpoint($server)->request(
            $this->metadata(),
            new ClientRegistration('a-client', null, TokenEndpointAuthMethod::PrivateKeyJwt),
            ['grant_type' => 'client_credentials'],
            $privateKey,
            'ES256',
        );

        $this->assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $server->form['client_assertion_type'] ?? null);

        $claims = JWT::decode($server->form['client_assertion'], new Key(openssl_pkey_get_details($key)['key'], 'ES256'));

        $this->assertSame('a-client', $claims->iss);
        $this->assertSame('a-client', $claims->sub);
        $this->assertSame('https://auth.example.com', $claims->aud);
        $this->assertGreaterThan(time(), $claims->exp);
    }

    #[TestDox('an assertion is refused rather than skipped when no key was configured')]
    public function testPrivateKeyJwtNeedsAKey(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('no private key was configured');

        $this->endpoint(new CapturingTokenServer())->request(
            $this->metadata(),
            new ClientRegistration('a-client', null, TokenEndpointAuthMethod::PrivateKeyJwt),
            ['grant_type' => 'client_credentials'],
        );
    }

    #[TestDox('the authorization server\'s own error text survives into the exception')]
    public function testErrorResponse(): void
    {
        $server = new CapturingTokenServer(new Response(400, [], (string) json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'The code has expired.',
        ])));

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('invalid_grant (The code has expired.)');

        $this->endpoint($server)->request($this->metadata(), new ClientRegistration('a-client'), ['grant_type' => 'authorization_code']);
    }

    // A token endpoint answering 200 in a shape this client cannot read is still handing
    // over a live token; quoting the body back would put it in every log downstream.
    #[TestDox('an unreadable success body is never quoted into the exception')]
    public function testDoesNotLeakAnUnreadableSuccessBody(): void
    {
        $server = new CapturingTokenServer(new Response(200, ['Content-Type' => 'application/x-www-form-urlencoded'], 'access_token=super-secret-token&token_type=bearer'));

        try {
            $this->endpoint($server)->request($this->metadata(), new ClientRegistration('a-client'), ['grant_type' => 'authorization_code']);
            $this->fail('An unreadable token response should not have been accepted.');
        } catch (AuthorizationException $e) {
            $this->assertStringNotContainsString('super-secret-token', $e->getMessage());
            $this->assertStringContainsString('could not read as JSON', $e->getMessage());
        }
    }

    private function endpoint(ClientInterface $client): TokenEndpoint
    {
        $factory = new Psr17Factory();

        return new TokenEndpoint($client, $factory, $factory);
    }

    private function metadata(): AuthorizationServerMetadata
    {
        return new AuthorizationServerMetadata(
            'https://auth.example.com',
            'https://auth.example.com/authorize',
            'https://auth.example.com/token',
        );
    }
}

/**
 * Records the token request and answers with a canned response.
 */
final class CapturingTokenServer implements ClientInterface
{
    public ?string $authorization = null;

    /** @var array<string, string> */
    public array $form = [];

    public function __construct(private readonly ?ResponseInterface $response = null)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->authorization = $request->hasHeader('Authorization') ? $request->getHeaderLine('Authorization') : null;
        parse_str((string) $request->getBody(), $form);
        $this->form = array_map(strval(...), $form);

        return $this->response ?? new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'a-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));
    }
}
