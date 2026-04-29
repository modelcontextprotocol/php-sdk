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

/**
 * Runtime handler that backs an MCP resource.
 *
 * Resources have no extra metadata beyond the base contract; this interface
 * exists so {@see \Mcp\Server\Builder::addResource()} can reject runtime
 * handlers intended for other element kinds at the type level.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface RuntimeResourceHandlerInterface extends RuntimeHandlerInterface
{
}
