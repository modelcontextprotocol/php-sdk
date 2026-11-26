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
 * An extension that only works alongside other extensions enabled on the same
 * client or server.
 *
 * The requirement is local: it is checked when the client or server is built,
 * whatever order the extensions were enabled in, and is never sent to the peer.
 *
 * @author Antoine Bluchet <soyuka@gmail.com>
 */
interface DependentExtensionInterface extends ExtensionInterface
{
    /**
     * @return list<ExtensionIdentifier>
     */
    public function getRequiredExtensions(): array;
}
