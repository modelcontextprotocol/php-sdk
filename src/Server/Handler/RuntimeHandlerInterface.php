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
 * Base contract for handlers that execute at runtime.
 *
 * Unlike string/array/Closure handlers, a runtime handler is a stateful object
 * registered with a reference. The reference handler invokes {@see self::execute()}
 * with the full argument map (including reserved keys such as `_session` and
 * `_request`) and a {@see ClientGateway} for client-side callbacks.
 *
 * Element-specific subtypes ({@see RuntimeToolHandlerInterface},
 * {@see RuntimePromptHandlerInterface}, {@see RuntimeResourceTemplateHandlerInterface},
 * {@see RuntimeResourceHandlerInterface}) declare only the metadata accessors
 * relevant to their element kind. Implementing the base interface alone is
 * supported for backwards-compatibility but new handlers should pick the
 * element-specific subtype that matches how they are registered.
 *
 * Note: arguments are forwarded to {@see self::execute()} as received from the
 * JSON-RPC request, without the type casting performed for reflection-based
 * handlers (string-to-int, string-to-bool, etc.). Runtime handlers are
 * responsible for validating and casting their own inputs, typically against
 * the schema they advertise.
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
