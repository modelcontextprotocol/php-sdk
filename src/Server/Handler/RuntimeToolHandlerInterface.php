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
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface RuntimeToolHandlerInterface extends RuntimeHandlerInterface
{
    /**
     * Returns the JSON Schema describing tool inputs.
     *
     * The Builder's `inputSchema:` named argument, when supplied, takes precedence
     * over this value.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * Returns the JSON Schema describing tool outputs.
     *
     * Returns null when no output schema applies, or when the Builder caller
     * supplies the schema via the `outputSchema:` named argument.
     *
     * @return array<string, mixed>|null
     */
    public function getOutputSchema(): ?array;
}
