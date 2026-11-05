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

use Firebase\JWT\Key;
use Mcp\Client\Auth\AuthorizationServerMetadata;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\TokenEndpoint;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // Signing with the HMAC family would use the private key as a shared secret, which
    // is `client_secret_jwt` wearing the wrong name -- a downgrade, not a signature.
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
        parse_str((string) $request->getBody(), $parsed);
        $this->form = [];

        foreach ($parsed as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $this->form[$name] = (string) $value;
            }
        }

        return $this->response ?? new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'access_token' => 'a-token',
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]));
    }
}
