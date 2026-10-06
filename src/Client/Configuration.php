<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;

/**
 * Client configuration holder.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Configuration
{
    /**
     * @param ProtocolVersion      $protocolVersion         the revision the client prefers. A modern one is
     *                                                      probed for with `server/discover` before anything else
     * @param ProtocolVersion|null $fallbackProtocolVersion the handshake revision offered through `initialize` when
     *                                                      a probe shows the server does not speak the modern era;
     *                                                      null makes a modern client modern-only. Unused when
     *                                                      $protocolVersion is a handshake revision already
     */
    public function __construct(
        public readonly Implementation $clientInfo,
        public readonly ClientCapabilities $capabilities,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V2026_07_28,
        public readonly int $initTimeout = 30,
        public readonly int $requestTimeout = 120,
        public readonly int $maxRetries = 3,
        public readonly ?ProtocolVersion $fallbackProtocolVersion = ProtocolVersion::V2025_11_25,
    ) {
        if (null !== $fallbackProtocolVersion && $fallbackProtocolVersion->isModern()) {
            throw new InvalidArgumentException(\sprintf('The fallback protocol version must be one reached through the "initialize" handshake, got "%s".', $fallbackProtocolVersion->value));
        }

        if ($initTimeout < 1) {
            throw new InvalidArgumentException(\sprintf('The initialization timeout must be a positive number of seconds, got %d.', $initTimeout));
        }

        if ($requestTimeout < 1) {
            throw new InvalidArgumentException(\sprintf('The request timeout must be a positive number of seconds, got %d.', $requestTimeout));
        }

        if ($maxRetries < 0) {
            throw new InvalidArgumentException(\sprintf('The maximum number of retries must be zero or greater, got %d.', $maxRetries));
        }
    }
}
