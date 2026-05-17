<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Registry\Loader\Stub;

use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;

final class ToolWriterLoader implements LoaderInterface
{
    public function __construct(private string $toolName, private \Closure $handler)
    {
    }

    public function load(RegistryInterface $registry): void
    {
        $registry->registerTool(new Tool(
            name: $this->toolName,
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: null,
            annotations: null,
            icons: null,
            meta: null,
            outputSchema: null,
        ), $this->handler);
    }
}
