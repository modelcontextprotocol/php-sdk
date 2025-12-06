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

use Mcp\Schema\Prompt;

/**
 * Provider for runtime prompt discovery and execution.
 *
 * Implement ClientAwareInterface to access ClientGateway.
 *
 * @author Mateu Aguiló Bosch <mateu@mateuaguilo.com>
 */
interface DynamicPromptProviderInterface
{
    /**
     * @return iterable<Prompt>
     */
    public function getPrompts(): iterable;

    public function supportsPrompt(string $promptName): bool;

    /**
     * @param array<string, mixed> $arguments
     */
    public function getPrompt(string $promptName, array $arguments): mixed;

    /**
     * @return array<string, class-string|object>
     */
    public function getCompletionProviders(string $promptName): array;
}
