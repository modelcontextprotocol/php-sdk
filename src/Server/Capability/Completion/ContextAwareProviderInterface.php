<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Completion;

/**
 * A provider whose completions depend on the arguments the user already filled in.
 *
 * The completion handler calls {@see getCompletionsWithContext()} instead of
 * {@see ProviderInterface::getCompletions()} on such a provider.
 *
 * @author Alejandro Ibarra <alejandro.ibarra@cakedc.com>
 */
interface ContextAwareProviderInterface extends ProviderInterface
{
    /**
     * @param string                $currentValue the current value to get completions for
     * @param array<string, string> $arguments    previously resolved arguments of the prompt or URI template, by name
     *
     * @return string[] the completions
     */
    public function getCompletionsWithContext(string $currentValue, array $arguments): array;
}
