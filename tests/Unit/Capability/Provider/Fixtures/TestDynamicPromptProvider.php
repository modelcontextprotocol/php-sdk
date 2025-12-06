<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Provider\Fixtures;

use Mcp\Capability\Provider\DynamicPromptProviderInterface;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;

/**
 * Test fixture for DynamicPromptProviderInterface.
 *
 * This class provides a simple implementation for testing dynamic prompt providers.
 */
final class TestDynamicPromptProvider implements DynamicPromptProviderInterface
{
    /**
     * @param array<Prompt>                                     $prompts
     * @param array<string, array<string, class-string|object>> $completionProviders Map of prompt name to argument->provider map
     */
    public function __construct(
        private readonly array $prompts = [],
        private readonly array $completionProviders = [],
    ) {
    }

    public function getPrompts(): iterable
    {
        return $this->prompts;
    }

    public function supportsPrompt(string $promptName): bool
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $promptName) {
                return true;
            }
        }

        return false;
    }

    public function getPrompt(string $promptName, array $arguments): mixed
    {
        foreach ($this->prompts as $prompt) {
            if ($prompt->name === $promptName) {
                $argsJson = json_encode($arguments);

                return [
                    new PromptMessage(
                        Role::User,
                        new TextContent("Prompt {$promptName} with arguments: {$argsJson}"),
                    ),
                ];
            }
        }

        throw new \RuntimeException("Prompt {$promptName} not found");
    }

    public function getCompletionProviders(string $promptName): array
    {
        return $this->completionProviders[$promptName] ?? [];
    }
}
