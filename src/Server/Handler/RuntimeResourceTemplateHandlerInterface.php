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
 * Runtime handler that backs an MCP resource template.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface RuntimeResourceTemplateHandlerInterface extends RuntimeHandlerInterface
{
    /**
     * Returns the completion providers for the URI template variables.
     *
     * Map of variable name => provider class-string or provider instance.
     * Returns null when no completion providers apply.
     *
     * @return array<string, class-string|object>|null
     */
    public function getCompletionProviders(): ?array;
}
