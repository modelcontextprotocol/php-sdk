<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Stateless;

use Mcp\Exception\MissingRequestMetaException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\Implementation;
use Mcp\Server\Wire\InboundClassifier;

/**
 * The per-request metadata that replaces the `initialize` handshake in the
 * modern era (SEP-2575).
 *
 * `protocolVersion` and `clientCapabilities` are structurally required; a
 * server cannot decide how to answer without them. `clientInfo` is a SHOULD,
 * and a request omitting it MUST NOT be refused.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RequestMeta
{
    public const PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    public const CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const LOG_LEVEL = 'io.modelcontextprotocol/logLevel';
    public const SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    public const SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

    /**
     * OpenTelemetry trace context, exempt from the prefix rule by name so it
     * matches what the wider ecosystem already puts on the wire.
     *
     * @see https://www.w3.org/TR/trace-context/
     */
    public const TRACE_KEYS = ['traceparent', 'tracestate', 'baggage'];

    /**
     * @param array<string, string> $traceContext W3C trace context carried through from the request
     */
    public function __construct(
        public readonly string $protocolVersion,
        public readonly ClientCapabilities $clientCapabilities,
        public readonly ?Implementation $clientInfo = null,
        public readonly ?LoggingLevel $logLevel = null,
        public readonly array $traceContext = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $params  the request's `params` member, if any
     * @param array<string, string>     $headers request headers, case-insensitively matched; a
     *                                           transport without a header layer (stdio) passes none
     *
     * @throws MissingRequestMetaException when a structurally required member is absent or malformed
     */
    public static function fromParams(?array $params, array $headers = []): self
    {
        $meta = $params['_meta'] ?? null;

        if (!\is_array($meta)) {
            throw new MissingRequestMetaException('Request is missing the required "params._meta" member.');
        }

        $version = $meta[self::PROTOCOL_VERSION] ?? null;
        if (!\is_string($version) || '' === $version) {
            throw new MissingRequestMetaException(\sprintf('Request "_meta" is missing the required "%s" member.', self::PROTOCOL_VERSION));
        }

        // An empty object is valid — a client with no optional capabilities.
        $capabilities = $meta[self::CLIENT_CAPABILITIES] ?? null;
        if (!\is_array($capabilities) && !$capabilities instanceof \stdClass) {
            throw new MissingRequestMetaException(\sprintf('Request "_meta" is missing the required "%s" member.', self::CLIENT_CAPABILITIES));
        }

        $clientInfo = $meta[self::CLIENT_INFO] ?? null;

        return new self(
            $version,
            ClientCapabilities::fromArray((array) $capabilities),
            \is_array($clientInfo) ? Implementation::fromArray($clientInfo) : null,
            self::parseLogLevel($meta[self::LOG_LEVEL] ?? null),
            self::parseTraceContext($meta, $headers),
        );
    }

    /**
     * An unparseable level means "none requested": failing the caller's actual
     * work over a diagnostic preference would be worse.
     */
    private static function parseLogLevel(mixed $level): ?LoggingLevel
    {
        return \is_string($level) ? LoggingLevel::tryFrom($level) : null;
    }

    /**
     * Carried through opaquely: the values are the tracing ecosystem's to
     * interpret, and a malformed one is not this server's to reject.
     *
     * `_meta` wins over the native W3C header of the same name when both are
     * present — it is the more specific of the two, scoped to this one call
     * rather than the whole HTTP request, and a caller that put it there did
     * so deliberately.
     *
     * @param array<string, mixed>  $meta
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function parseTraceContext(array $meta, array $headers): array
    {
        $context = [];

        foreach (self::TRACE_KEYS as $key) {
            if (\is_string($meta[$key] ?? null)) {
                $context[$key] = $meta[$key];
            } elseif (null !== $header = InboundClassifier::header($headers, $key)) {
                $context[$key] = $header;
            }
        }

        return $context;
    }
}
