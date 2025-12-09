<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Provider;

use Mcp\Schema\Tool;

/**
 * Provider for runtime tool discovery and execution.
 *
 * Implement ClientAwareInterface to access ClientGateway.
 *
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
interface DynamicToolProviderInterface
{
    /**
     * @return iterable<Tool>
     */
    public function getTools(): iterable;

    public function supportsTool(string $toolName): bool;

    /**
     * @param array<string, mixed> $arguments
     */
    public function executeTool(string $toolName, array $arguments): mixed;
}
