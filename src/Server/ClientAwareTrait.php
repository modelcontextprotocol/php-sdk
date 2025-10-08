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

use Mcp\Exception\LogicException;

trait ClientAwareTrait
{
    private ClientGateway $clientGateway;

    public function setClientGateway(ClientGateway $clientGateway): void
    {
        $this->clientGateway = $clientGateway;
    }

    protected function getClientGateway(): ClientGateway
    {
        if (!isset($this->clientGateway)) {
            throw new LogicException('ClientGateway has not been set.');
        }

        return $this->clientGateway;
    }
}
