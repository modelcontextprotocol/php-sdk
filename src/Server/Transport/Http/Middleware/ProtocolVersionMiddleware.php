<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\Middleware;

use Http\Discovery\Psr17FactoryDiscovery;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\Http\JsonRpcErrorResponse;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Validates the `MCP-Protocol-Version` header against the SDK's supported set.
 *
 * Per the MCP Streamable HTTP spec, after the `initialize` handshake clients
 * must echo the negotiated protocol version on every subsequent request via
 * the `MCP-Protocol-Version` header. Servers MUST reject unknown or malformed
 * values with `400 Bad Request`.
 *
 * When the header is absent the middleware passes the request through —
 * the `initialize` round-trip does not carry the header, and legacy clients
 * that omit it are tolerated.
 *
 * @see https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#protocol-version-header
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class ProtocolVersionMiddleware implements MiddlewareInterface
{
    private ResponseFactoryInterface $responseFactory;
    private StreamFactoryInterface $streamFactory;

    /** @var list<string> */
    private readonly array $supportedVersions;

    /**
     * @param list<ProtocolVersion>|null    $supportedVersions Versions the server accepts. Defaults to all values of {@see ProtocolVersion}.
     * @param ResponseFactoryInterface|null $responseFactory   PSR-17 response factory (auto-discovered if null)
     * @param StreamFactoryInterface|null   $streamFactory     PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        ?array $supportedVersions = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $versions = $supportedVersions ?? ProtocolVersion::cases();
        $this->supportedVersions = array_values(array_map(static fn (ProtocolVersion $v): string => $v->value, $versions));
        $this->responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $headerValue = $request->getHeaderLine(StreamableHttpTransport::PROTOCOL_VERSION_HEADER);

        // Spec backwards-compat: when the header is absent, the server SHOULD assume
        // protocol version 2025-03-26 — the release in which Streamable HTTP and the
        // header itself were introduced. This is deliberately lower than the SDK's
        // own default (V2025_06_18) so clients predating the header convention still
        // get a deterministic protocol version applied. Servers that whitelist only
        // newer versions in $supportedVersions will reject such requests with 400.
        $version = '' === $headerValue ? ProtocolVersion::V2025_03_26->value : $headerValue;

        if (\in_array($version, $this->supportedVersions, true)) {
            return $handler->handle($request);
        }

        $message = '' === $headerValue
            ? \sprintf(
                'Missing %s header; backwards-compat default %s is not accepted. Supported versions: %s.',
                StreamableHttpTransport::PROTOCOL_VERSION_HEADER,
                $version,
                implode(', ', $this->supportedVersions),
            )
            : \sprintf(
                'Unsupported %s header value: %s. Supported versions: %s.',
                StreamableHttpTransport::PROTOCOL_VERSION_HEADER,
                $headerValue,
                implode(', ', $this->supportedVersions),
            );

        return JsonRpcErrorResponse::create($this->responseFactory, $this->streamFactory, 400, Error::forInvalidParams($message));
    }
}
