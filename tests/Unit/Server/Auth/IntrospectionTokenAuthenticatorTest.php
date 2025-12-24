<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Auth;

use Mcp\Server\Auth\IntrospectionTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * @covers \Mcp\Server\Auth\IntrospectionTokenAuthenticator
 */
final class IntrospectionTokenAuthenticatorTest extends TestCase
{
    public function testAuthenticatesActiveToken(): void
    {
        $authenticator = $this->createAuthenticator([
            'active' => true,
            'sub' => 'user-123',
            'scope' => 'read write',
            'client_id' => 'my-client',
        ]);

        $result = $authenticator->authenticate('valid-token');

        $this->assertTrue($result->authenticated);
        $this->assertSame('user-123', $result->getSubject());
        $this->assertSame(['read', 'write'], $result->getScopes());
    }

    public function testRejectsInactiveToken(): void
    {
        $authenticator = $this->createAuthenticator([
            'active' => false,
        ]);

        $result = $authenticator->authenticate('expired-token');

        $this->assertFalse($result->authenticated);
        $this->assertSame('invalid_token', $result->error);
    }

    public function testRejectsEmptyToken(): void
    {
        $authenticator = $this->createAuthenticator([]);

        $result = $authenticator->authenticate('');

        $this->assertFalse($result->authenticated);
        $this->assertSame('invalid_token', $result->error);
    }

    public function testHandlesHttpError(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockRequestFactory = $this->createMock(RequestFactoryInterface::class);
        $mockStreamFactory = $this->createMock(StreamFactoryInterface::class);

        $mockRequest = $this->createMock(RequestInterface::class);
        $mockRequest->method('withHeader')->willReturnSelf();
        $mockRequest->method('withBody')->willReturnSelf();

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStreamFactory->method('createStream')->willReturn($mockStream);

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(500);

        $mockRequestFactory->method('createRequest')->willReturn($mockRequest);
        $mockClient->method('sendRequest')->willReturn($mockResponse);

        $authenticator = new IntrospectionTokenAuthenticator(
            introspectionEndpoint: 'https://auth.example.com/introspect',
            clientId: 'resource-server',
            clientSecret: 'secret',
            httpClient: $mockClient,
            requestFactory: $mockRequestFactory,
            streamFactory: $mockStreamFactory,
        );

        $result = $authenticator->authenticate('some-token');

        $this->assertFalse($result->authenticated);
    }

    public function testHandlesInvalidJsonResponse(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockRequestFactory = $this->createMock(RequestFactoryInterface::class);
        $mockStreamFactory = $this->createMock(StreamFactoryInterface::class);

        $mockRequest = $this->createMock(RequestInterface::class);
        $mockRequest->method('withHeader')->willReturnSelf();
        $mockRequest->method('withBody')->willReturnSelf();

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStreamFactory->method('createStream')->willReturn($mockStream);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn('not valid json');

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($bodyStream);

        $mockRequestFactory->method('createRequest')->willReturn($mockRequest);
        $mockClient->method('sendRequest')->willReturn($mockResponse);

        $authenticator = new IntrospectionTokenAuthenticator(
            introspectionEndpoint: 'https://auth.example.com/introspect',
            clientId: 'resource-server',
            clientSecret: 'secret',
            httpClient: $mockClient,
            requestFactory: $mockRequestFactory,
            streamFactory: $mockStreamFactory,
        );

        $result = $authenticator->authenticate('some-token');

        $this->assertFalse($result->authenticated);
    }

    public function testIncludesBasicAuth(): void
    {
        $authHeader = null;

        $mockClient = $this->createMock(ClientInterface::class);
        $mockRequestFactory = $this->createMock(RequestFactoryInterface::class);
        $mockStreamFactory = $this->createMock(StreamFactoryInterface::class);

        $mockRequest = $this->createMock(RequestInterface::class);
        $mockRequest->method('withHeader')->willReturnCallback(function ($name, $value) use ($mockRequest, &$authHeader) {
            if ('Authorization' === $name) {
                $authHeader = $value;
            }

            return $mockRequest;
        });
        $mockRequest->method('withBody')->willReturnSelf();

        $mockStreamFactory->method('createStream')->willReturnCallback(function ($content) {
            $stream = $this->createMock(StreamInterface::class);
            $stream->method('__toString')->willReturn($content);

            return $stream;
        });

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn(json_encode(['active' => false]));

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($bodyStream);

        $mockRequestFactory->method('createRequest')->willReturn($mockRequest);
        $mockClient->method('sendRequest')->willReturn($mockResponse);

        $authenticator = new IntrospectionTokenAuthenticator(
            introspectionEndpoint: 'https://auth.example.com/introspect',
            clientId: 'resource-server',
            clientSecret: 'secret',
            httpClient: $mockClient,
            requestFactory: $mockRequestFactory,
            streamFactory: $mockStreamFactory,
        );

        $authenticator->authenticate('test-token');

        $expectedAuth = 'Basic ' . base64_encode('resource-server:secret');
        $this->assertSame($expectedAuth, $authHeader);
    }

    private function createAuthenticator(array $introspectionResponse): IntrospectionTokenAuthenticator
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $mockRequestFactory = $this->createMock(RequestFactoryInterface::class);
        $mockStreamFactory = $this->createMock(StreamFactoryInterface::class);

        $mockRequest = $this->createMock(RequestInterface::class);
        $mockRequest->method('withHeader')->willReturnSelf();
        $mockRequest->method('withBody')->willReturnSelf();

        $mockStream = $this->createMock(StreamInterface::class);
        $mockStreamFactory->method('createStream')->willReturn($mockStream);

        $bodyStream = $this->createMock(StreamInterface::class);
        $bodyStream->method('__toString')->willReturn(json_encode($introspectionResponse));

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getBody')->willReturn($bodyStream);

        $mockRequestFactory->method('createRequest')->willReturn($mockRequest);
        $mockClient->method('sendRequest')->willReturn($mockResponse);

        return new IntrospectionTokenAuthenticator(
            introspectionEndpoint: 'https://auth.example.com/introspect',
            clientId: 'resource-server',
            clientSecret: 'secret',
            httpClient: $mockClient,
            requestFactory: $mockRequestFactory,
            streamFactory: $mockStreamFactory,
        );
    }
}

