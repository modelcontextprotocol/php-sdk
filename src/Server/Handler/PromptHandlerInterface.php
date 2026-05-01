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
 * Contract for explicit prompt handlers paired with an `Mcp\Schema\Prompt` definition
 * via `Mcp\Server\Builder::add()`.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface PromptHandlerInterface extends ElementHandlerInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function get(array $arguments, ClientGateway $gateway): mixed;
}
