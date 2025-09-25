<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\Fixtures;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Tests\Unit\Capability\Attribute\CompletionProviderFixture;

class DiscoverablePromptHandler
{
    /**
     * Generates a creative story prompt.
     *
     * @param string $genre       the genre of the story
     * @param int    $lengthWords approximate length in words
     *
     * @return array the prompt messages
     */
    #[McpPrompt(name: 'creative_story_prompt')]
    public function generateStoryPrompt(
        #[CompletionProvider(provider: CompletionProviderFixture::class)]
        string $genre,
        int $lengthWords = 200,
    ): array {
        return [
            ['role' => 'user', 'content' => "Write a {$genre} story about a lost robot, approximately {$lengthWords} words long."],
        ];
    }

    #[McpPrompt]
    public function simpleQuestionPrompt(string $question): array
    {
        return [
            ['role' => 'user', 'content' => $question],
            ['role' => 'assistant', 'content' => 'I will try to answer that.'],
        ];
    }
}
