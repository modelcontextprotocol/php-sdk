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

/**
 * @deprecated This is deprecated since 0.2.0 and will be removed in 0.3.0. Use RequestContext with argument injection instead.
 */
interface ClientAwareInterface
{
    public function setClient(ClientGateway $clientGateway): void;
}
