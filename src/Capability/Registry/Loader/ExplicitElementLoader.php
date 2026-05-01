<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry\Loader;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Translates `Builder::add()` definition+handler pairs into Registry entries.
 *
 * Each pair is registered with `isManual: true`, matching the precedence used
 * by the `ArrayLoader` for closure/array/string handlers.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
final class ExplicitElementLoader implements LoaderInterface
{
    /**
     * @param list<array{definition: Tool, handler: ToolHandlerInterface}>                         $tools
     * @param list<array{definition: \Mcp\Schema\Resource, handler: ResourceHandlerInterface}>     $resources
     * @param list<array{definition: ResourceTemplate, handler: ResourceTemplateHandlerInterface}> $resourceTemplates
     * @param list<array{definition: Prompt, handler: PromptHandlerInterface}>                     $prompts
     */
    public function __construct(
        private readonly array $tools = [],
        private readonly array $resources = [],
        private readonly array $resourceTemplates = [],
        private readonly array $prompts = [],
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        foreach ($this->tools as $entry) {
            $registry->registerTool($entry['definition'], $entry['handler'], true);
        }

        foreach ($this->resources as $entry) {
            $registry->registerResource($entry['definition'], $entry['handler'], true);
        }

        foreach ($this->resourceTemplates as $entry) {
            $registry->registerResourceTemplate($entry['definition'], $entry['handler'], [], true);
        }

        foreach ($this->prompts as $entry) {
            $registry->registerPrompt($entry['definition'], $entry['handler'], [], true);
        }
    }
}
