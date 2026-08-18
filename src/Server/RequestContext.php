<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Capability\Logger\ClientLogger;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Server\Session\SessionInterface;

/**
 * Context related to a single request. This includes information about the session and
 * will build request-specific objects.
 *
 * This is a stateful object, it should not be reused between requests.
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class RequestContext
{
    /**
     * `_meta` key carrying the protocol revision of a single request, introduced
     * with the modern era that replaced the `initialize` handshake.
     *
     * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning
     */
    private const PROTOCOL_VERSION_META_KEY = 'io.modelcontextprotocol/protocolVersion';

    private ?ClientGateway $clientGateway = null;
    private ?ClientLogger $clientLogger = null;

    public function __construct(
        private readonly SessionInterface $session,
        private readonly Request $request,
    ) {
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getSession(): SessionInterface
    {
        return $this->session;
    }

    /**
     * The protocol revision this request is served under.
     *
     * Modern revisions declare it per request in `_meta`, handshake ones negotiate
     * it once and keep it on the session. Neither is guaranteed to be present — a
     * transport may skip `initialize` entirely — so this falls back to the newest
     * handshake revision, whose rules hold for every revision below it too.
     */
    public function getProtocolVersion(): ProtocolVersion
    {
        $requested = $this->request->getMeta()[self::PROTOCOL_VERSION_META_KEY]
            ?? $this->session->get('protocol_version');

        if (!\is_string($requested)) {
            return ProtocolVersion::latestHandshake();
        }

        return ProtocolVersion::tryFrom($requested) ?? ProtocolVersion::latestHandshake();
    }

    public function getClientGateway(): ClientGateway
    {
        if (null == $this->clientGateway) {
            $this->clientGateway = new ClientGateway($this->session);
        }

        return $this->clientGateway;
    }

    public function getClientLogger(): ClientLogger
    {
        if (null === $this->clientLogger) {
            $this->clientLogger = new ClientLogger($this->getClientGateway(), $this->session);
        }

        return $this->clientLogger;
    }
}
