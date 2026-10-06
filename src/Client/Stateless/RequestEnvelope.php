<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Stateless;

use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Server\Stateless\RequestMeta;

/**
 * Stamps the per-request `_meta` that replaces the `initialize` handshake
 * (SEP-2575).
 *
 * The modern era has no connection-scoped negotiation, so what a handshake
 * would have said once now rides on every message: the revision this request
 * is written against, and what the client can be asked to do while answering
 * it. {@see RequestMeta} is the server's reader for the same envelope.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/index#meta
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RequestEnvelope
{
    /**
     * @param LoggingLevel|null $logLevel the least severe log message the client wants to hear about while a
     *                                    request runs; null asks for none, which is what this revision assumes
     */
    public function __construct(
        private readonly ProtocolVersion $protocolVersion,
        private readonly ClientCapabilities $capabilities,
        private readonly Implementation $clientInfo,
        private readonly ?LoggingLevel $logLevel = null,
    ) {
    }

    public function protocolVersion(): ProtocolVersion
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(ProtocolVersion $protocolVersion): self
    {
        return new self($protocolVersion, $this->capabilities, $this->clientInfo, $this->logLevel);
    }

    /**
     * The per-request replacement for `logging/setLevel`, which this revision
     * removed: the level now rides on every request instead of being set once.
     */
    public function withLogLevel(?LoggingLevel $logLevel): self
    {
        return new self($this->protocolVersion, $this->capabilities, $this->clientInfo, $logLevel);
    }

    /**
     * Merges the envelope into an encoded message, preserving whatever `_meta`
     * the caller already put there — a `progressToken` most of all, which would
     * otherwise be dropped and take every progress notification with it.
     *
     * @param array<string, mixed> $payload a serialized JSON-RPC message
     *
     * @return array<string, mixed>
     */
    public function stamp(array $payload): array
    {
        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $meta = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $params['_meta'] = [
            ...$meta,
            RequestMeta::PROTOCOL_VERSION => $this->protocolVersion->value,
            // ClientCapabilities encodes an empty set as `{}` already; `[]`
            // would reach the server as a JSON array and fail its check.
            RequestMeta::CLIENT_CAPABILITIES => $this->capabilities,
            RequestMeta::CLIENT_INFO => $this->clientInfo,
        ];

        if (null !== $this->logLevel) {
            $params['_meta'][RequestMeta::LOG_LEVEL] = $this->logLevel->value;
        }

        $payload['params'] = $params;

        return $payload;
    }
}
