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
    public function __construct(
        public readonly Implementation $clientInfo,
        public readonly ClientCapabilities $capabilities,
        public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V2025_11_25,
        public readonly int $initTimeout = 30,
        public readonly int $requestTimeout = 120,
        public readonly int $maxRetries = 3,
    ) {
        if ($initTimeout < 1) {
            throw new InvalidArgumentException(\sprintf('The initialization timeout must be a positive number of seconds, got %d.', $initTimeout));
        }

        if ($requestTimeout < 1) {
            throw new InvalidArgumentException(\sprintf('The request timeout must be a positive number of seconds, got %d.', $requestTimeout));
        }
    }
}
