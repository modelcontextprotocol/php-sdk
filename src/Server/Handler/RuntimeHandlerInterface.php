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
 * Base contract for runtime handlers — stateful objects invoked per request.
 *
 * New handlers should implement the element-specific subtype matching how they
 * are registered. Arguments are forwarded as received from JSON-RPC, without
 * the casting performed for reflection-based handlers; implementations must
 * validate and cast their own inputs.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface RuntimeHandlerInterface
{
    /**
     * Executes the handler and returns its result.
     *
     * @param array<string, mixed> $arguments the handler arguments as key-value pairs
     * @param ClientGateway        $gateway   client gateway for handlers that support callbacks
     *
     * @return mixed the handler result
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed;
}
