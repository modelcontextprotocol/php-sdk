<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension;

/**
 * A server-side MCP protocol extension advertised during capability negotiation.
 *
 * Implementations are typically zero-config — they expose a stable identifier and the
 * capability payload announced under `capabilities.extensions[<id>]` in the initialize
 * response.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface ServerExtensionInterface
{
    /**
     * The reverse-DNS identifier used as the key under `capabilities.extensions`.
     */
    public function getId(): string;

    /**
     * The capability payload announced for this extension.
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;
}
