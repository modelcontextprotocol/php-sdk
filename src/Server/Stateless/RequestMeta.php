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

/**
 * The per-request metadata that replaces the `initialize` handshake in the
 * modern era (SEP-2575).
 *
 * A handshake-era connection learns the protocol version, the client's
 * identity and its capabilities once, then keeps them in session state. With
 * no session to keep them in, every modern request restates them in
 * `params._meta` — so this object is the stateless equivalent of the session
 * a {@see \Mcp\Server\Protocol} run would have resolved.
 *
 * `protocolVersion` and `clientCapabilities` are structurally required: a
 * server cannot decide how to answer without them. `clientInfo` is not — it is
 * a SHOULD, and servers MUST NOT refuse a request that omits it.
 */
final class RequestMeta
{
    public const PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';
    public const CLIENT_INFO = 'io.modelcontextprotocol/clientInfo';
    public const CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    public const LOG_LEVEL = 'io.modelcontextprotocol/logLevel';
    public const SERVER_INFO = 'io.modelcontextprotocol/serverInfo';
    public const SUBSCRIPTION_ID = 'io.modelcontextprotocol/subscriptionId';

    public function __construct(
        public readonly string $protocolVersion,
        public readonly ClientCapabilities $clientCapabilities,
        public readonly ?Implementation $clientInfo = null,
        public readonly ?LoggingLevel $logLevel = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $params the request's `params` member, if any
     *
     * @throws MissingRequestMetaException when a structurally required member is absent or malformed
     */
    public static function fromParams(?array $params): self
    {
        $meta = $params['_meta'] ?? null;

        if (!\is_array($meta)) {
            throw new MissingRequestMetaException('Request is missing the required "params._meta" member.');
        }

        $version = $meta[self::PROTOCOL_VERSION] ?? null;
        if (!\is_string($version) || '' === $version) {
            throw new MissingRequestMetaException(\sprintf('Request "_meta" is missing the required "%s" member.', self::PROTOCOL_VERSION));
        }

        // Required, but an empty object is a perfectly valid value: it declares
        // a client that supports none of the optional capabilities. Only its
        // absence — or a non-object — is a protocol error.
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
        );
    }

    /**
     * An unparseable level is treated as "no level requested" rather than as a
     * protocol error: log verbosity is a diagnostic preference, and rejecting
     * the whole request over it would fail the caller's actual work.
     */
    private static function parseLogLevel(mixed $level): ?LoggingLevel
    {
        return \is_string($level) ? LoggingLevel::tryFrom($level) : null;
    }
}
