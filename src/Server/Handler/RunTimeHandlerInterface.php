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
 * Element-specific subtypes ({@see RunTimeToolHandlerInterface},
 * {@see RunTimePromptHandlerInterface}, {@see RunTimeResourceTemplateHandlerInterface})
 * declare only the metadata accessors relevant to their element kind.
 * Resources have no extra metadata and may implement this base interface
 * directly.
 */
interface RunTimeHandlerInterface
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
