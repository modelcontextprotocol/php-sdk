<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler;

use Mcp\Server\ClientGateway;

/**
 * Contract for explicit resource handlers paired with an `Mcp\Schema\Resource`
 * definition via `Mcp\Server\Builder::add()`.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface ResourceHandlerInterface extends ElementHandlerInterface
{
    public function read(string $uri, ClientGateway $gateway): mixed;
}
