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
 * Contract for handlers that resolve their own arguments and execute at runtime.
 *
 * Unlike string/array/Closure handlers, a runtime handler is a stateful object
 * registered with a reference. The reference handler delegates argument
 * filtering and execution to it, and provides a {@see ClientGateway} so the
 * handler can communicate with the client (notifications, sampling, etc.).
 */
interface RunTimeHandlerInterface
{
    /**
     * Filters out arguments that the handler does not care about.
     *
     * The reference handler builds a generic argument map (including reserved
     * keys such as `_session` and `_request`); this method narrows it down to
     * what {@see self::execute()} expects.
     *
     * @param array<string, mixed> $arguments arguments as constructed by the reference handler
     *
     * @return array<string, mixed> the arguments the handler cares about
     *
     * @see \Mcp\Capability\Registry\ReferenceHandler::handle()
     */
    public function filterArguments(array $arguments): array;

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
