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
 * Runtime handler that backs an MCP prompt.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface RuntimePromptHandlerInterface extends RuntimeHandlerInterface
{
    /**
     * Returns the prompt arguments for this handler, or an empty array when the prompt takes no arguments.
     *
     * @return list<\Mcp\Schema\PromptArgument>
     */
    public function getPromptArguments(): array;

    /**
     * Returns the completion providers for the prompt arguments.
     *
     * Map of argument name => provider class-string or provider instance.
     * Returns an empty array when no completion providers apply.
     *
     * @return array<string, class-string|object>
     */
    public function getCompletionProviders(): array;
}
