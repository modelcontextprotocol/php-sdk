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
 * Base for an extension that only announces a capability and adds no RPC
 * methods of its own — the common case. Implementers only need {@see
 * ExtensionInterface::getId()} and {@see ExtensionInterface::getCapabilities()}.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class AbstractExtension implements ExtensionInterface
{
    public function getMessages(): array
    {
        return [];
    }

    public function getRequestHandlers(): iterable
    {
        return [];
    }
}
