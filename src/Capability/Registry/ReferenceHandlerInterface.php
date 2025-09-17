<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

/**
 * Interface for handling execution of MCP elements.
 * Allows custom implementations of element execution logic.
 *
 * @author Pavel Buchnev <butschster@gmail.com>
 */
interface ReferenceHandlerInterface
{
    /**
     * Handles execution of an MCP element reference.
     *
     * @param ElementReference     $reference the element reference to execute
     * @param array<string, mixed> $arguments arguments to pass to the handler
     *
     * @return mixed the result of the element execution
     *
     * @throws \Mcp\Exception\InvalidArgumentException        if the handler is invalid
     * @throws \Mcp\Exception\ToolExecutionExceptionInterface if the tool reports an error during its execution
     * @throws \Mcp\Exception\RegistryException               if execution fails
     */
    public function handle(ElementReference $reference, array $arguments): mixed;
}
