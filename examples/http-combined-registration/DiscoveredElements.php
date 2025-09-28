<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\HttpCombinedRegistration;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;

final class DiscoveredElements
{
    /**
     * A tool discovered via attributes.
     *
     * @return string a status message
     */
    #[McpTool(name: 'discovered_status_check')]
    public function checkSystemStatus(): string
    {
        return 'System status: OK (discovered)';
    }

    /**
     * A resource discovered via attributes.
     * This will be overridden by a manual registration with the same URI.
     *
     * @return string content
     */
    #[McpResource(uri: 'config://priority', name: 'priority_config_discovered')]
    public function getPriorityConfigDiscovered(): string
    {
        return 'Discovered Priority Config: Low';
    }
}
