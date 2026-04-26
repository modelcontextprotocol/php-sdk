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
 */
interface RunTimePromptHandlerInterface extends RunTimeHandlerInterface
{
    /**
     * Returns the prompt arguments for this handler.
     *
     * Returns null when the prompt takes no arguments.
     *
     * @return list<\Mcp\Schema\PromptArgument>|null
     */
    public function getPromptArguments(): ?array;

    /**
     * Returns the completion providers for the prompt arguments.
     *
     * Map of argument name => provider class-string or provider instance.
     * Returns null when no completion providers apply.
     *
     * @return array<string, class-string|object>|null
     */
    public function getCompletionProviders(): ?array;
}
