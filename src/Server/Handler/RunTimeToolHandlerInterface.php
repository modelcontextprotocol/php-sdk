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
 * Runtime handler that backs an MCP tool.
 */
interface RunTimeToolHandlerInterface extends RunTimeHandlerInterface
{
    /**
     * Returns the JSON Schema describing tool inputs.
     *
     * Returns null when the Builder caller supplies the schema via the
     * `inputSchema:` keyword (the kwarg takes precedence).
     *
     * @return array<string, mixed>|null
     */
    public function getInputSchema(): ?array;

    /**
     * Returns the JSON Schema describing tool outputs.
     *
     * Returns null when no output schema applies, or when the Builder caller
     * supplies the schema via the `outputSchema:` keyword.
     *
     * @return array<string, mixed>|null
     */
    public function getOutputSchema(): ?array;
}
