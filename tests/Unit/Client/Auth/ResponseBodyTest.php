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

use Mcp\Client\Auth\AuthorizationServerMetadata;
use Mcp\Client\Auth\ClientMetadata;
use Mcp\Client\Auth\ClientRegistrar;
use Mcp\Client\Auth\ClientRegistration;
use Mcp\Client\Auth\MetadataDiscovery;
use Mcp\Client\Auth\ResponseBody;
use Mcp\Client\Auth\TokenEndpoint;
use Mcp\Client\Auth\TokenEndpointAuthMethod;
use Mcp\Exception\AuthorizationException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class ResponseBodyTest extends TestCase
{
    #[TestDox('a body within the limit is read completely, from the start')]
    public function testReadsABodyWithinTheLimit(): void
    {
        $body = str_repeat('a', ResponseBody::MAX_BYTES);

        $this->assertSame($body, ResponseBody::read(new Response(200, [], $body)));
    }

    #[TestDox('a body over the limit is refused without reading all of it')]
    public function testStopsReadingAtTheLimit(): void
    {
        $response = new Response(200, [], str_repeat('a', 5 * ResponseBody::MAX_BYTES));

        $this->assertNull(ResponseBody::read($response));
        $this->assertLessThanOrEqual(ResponseBody::MAX_BYTES + 1, $response->getBody()->tell());
    }

    #[TestDox('a token response over the limit is an authorization failure')]
    public function testTokenEndpointRefusesAHugeResponse(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('could not read as JSON');

        (new TokenEndpoint(self::answeringWithAHugeDocument('access_token'), $factory, $factory))->request(
            new AuthorizationServerMetadata('https://auth.example.com', 'https://auth.example.com/authorize', 'https://auth.example.com/token'),
            new ClientRegistration('a-client'),
            ['grant_type' => 'authorization_code'],
        );
    }

    #[TestDox('a registration response over the limit is an authorization failure')]
    public function testRegistrarRefusesAHugeResponse(): void
    {
        $factory = new Psr17Factory();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('refused the client registration');

        (new ClientRegistrar(self::answeringWithAHugeDocument('client_id'), $factory, $factory))->register(
            new AuthorizationServerMetadata('https://auth.example.com', 'https://auth.example.com/authorize', 'https://auth.example.com/token', 'https://auth.example.com/register'),
            new ClientMetadata('My App'),
            TokenEndpointAuthMethod::None,
        );
    }

    #[TestDox('metadata over the limit counts as no metadata')]
    public function testDiscoveryIgnoresAHugeDocument(): void
    {
        $discovery = new MetadataDiscovery(self::answeringWithAHugeDocument('issuer'), new Psr17Factory());

        $this->assertNull($discovery->discoverAuthorizationServer('https://auth.example.com'));
    }

    private static function answeringWithAHugeDocument(string $field): ClientInterface
    {
        return new class($field) implements ClientInterface {
            public function __construct(private readonly string $field)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], (string) json_encode([
                    $this->field => 'https://auth.example.com',
                    'token_endpoint' => 'https://auth.example.com/token',
                    'authorization_endpoint' => 'https://auth.example.com/authorize',
                    'padding' => str_repeat('a', ResponseBody::MAX_BYTES),
                ]));
            }
        };
    }
}
