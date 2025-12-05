<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

use Mcp\Capability\Formatter\PromptResultFormatter;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Prompt;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class PromptReference extends ElementReference
{
    use ReflectionArgumentPreparationTrait;

    /**
     * @param \Closure|array{0: class-string|object, 1: string}|string $handler
     * @param array<string, class-string|object>                       $completionProviders
     */
    public function __construct(
        public readonly Prompt $prompt,
        \Closure|array|string $handler,
        bool $isManual = false,
        public readonly array $completionProviders = [],
    ) {
        parent::__construct($handler, $isManual);
    }

    /**
     * Formats the raw result of a prompt generator into an array of MCP PromptMessages.
     *
     * @param mixed $promptGenerationResult expected: array of message structures
     *
     * @return PromptMessage[] array of PromptMessage objects
     *
     * @throws \RuntimeException if the result cannot be formatted
     * @throws \JsonException    if JSON encoding fails
     */
    public function formatResult(mixed $promptGenerationResult): array
    {
        return (new PromptResultFormatter())->format($promptGenerationResult);
    }
}
