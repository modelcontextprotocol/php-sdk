<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\Middleware;

use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use PHPUnit\Framework\Attributes\TestDox;

final class ProtocolVersionMiddlewareTest extends MiddlewareTestCase
{
    #[TestDox('passes request through when header is absent and 2025-03-26 backwards-compat default is supported')]
    public function testMissingHeaderAcceptedWhenBackwardsCompatVersionIsSupported(): void
    {
        $middleware = new ProtocolVersionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(200, $response->getStatusCode());
    }

    #[TestDox('rejects missing header when 2025-03-26 backwards-compat default is not in supportedVersions')]
    public function testMissingHeaderRejectedByStrictServer(): void
    {
        $middleware = new ProtocolVersionMiddleware(
            supportedVersions: [ProtocolVersion::V2025_11_25],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );
        $request = $this->factory->createServerRequest('POST', 'http://localhost/');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('accepts every version declared in the ProtocolVersion enum')]
    public function testAcceptsSupportedVersions(): void
    {
        $middleware = new ProtocolVersionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);

        foreach (ProtocolVersion::cases() as $version) {
            $request = $this->factory->createServerRequest('POST', 'http://localhost/')
                ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, $version->value);

            $response = $middleware->process($request, $this->passthroughHandler);

            $this->assertSame(200, $response->getStatusCode(), 'Expected '.$version->value.' to be accepted.');
        }
    }

    #[TestDox('rejects unsupported well-formed version with 400')]
    public function testRejectsUnsupportedVersion(): void
    {
        $middleware = new ProtocolVersionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, '1900-01-01');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    #[TestDox('rejects malformed version with 400')]
    public function testRejectsMalformedVersion(): void
    {
        $middleware = new ProtocolVersionMiddleware(responseFactory: $this->factory, streamFactory: $this->factory);
        $request = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, 'not-a-version');

        $response = $middleware->process($request, $this->passthroughHandler);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[TestDox('accepts only the supportedVersions whitelist when provided')]
    public function testRestrictedSupportedVersions(): void
    {
        $middleware = new ProtocolVersionMiddleware(
            supportedVersions: [ProtocolVersion::V2025_11_25],
            responseFactory: $this->factory,
            streamFactory: $this->factory,
        );

        $accepted = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, ProtocolVersion::V2025_11_25->value);
        $rejected = $this->factory->createServerRequest('POST', 'http://localhost/')
            ->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, ProtocolVersion::V2024_11_05->value);

        $this->assertSame(200, $middleware->process($accepted, $this->passthroughHandler)->getStatusCode());
        $this->assertSame(400, $middleware->process($rejected, $this->passthroughHandler)->getStatusCode());
    }
}
