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
 * Default null implementations of the four metadata accessors declared on
 * {@see RunTimeHandlerInterface}. Implementers `use` this trait and override
 * only the accessors relevant to their element kind (tool, resource,
 * resource template, or prompt).
 */
trait RunTimeHandlerTrait
{
    /**
     * @return array<string, mixed>|null
     */
    public function getInputSchema(): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutputSchema(): ?array
    {
        return null;
    }

    /**
     * @return list<\Mcp\Schema\PromptArgument>|null
     */
    public function getPromptArguments(): ?array
    {
        return null;
    }

    /**
     * @return array<string, class-string|object>|null
     */
    public function getCompletionProviders(): ?array
    {
        return null;
    }
}
