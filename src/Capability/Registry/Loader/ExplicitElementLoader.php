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
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\PromptHandlerInterface;
use Mcp\Server\Handler\ResourceHandlerInterface;
use Mcp\Server\Handler\ResourceTemplateHandlerInterface;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Translates `Builder::add()` definition+handler pairs into Registry entries.
 *
 * Wraps each handler instance in a closure that matches the callable contract the
 * `ReferenceHandler` already invokes, so per-interface dispatch knowledge is confined
 * to this loader. Manual-over-discovered precedence is preserved by loader ordering
 * (this loader runs before the `DiscoveryLoader`).
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
final class ExplicitElementLoader implements LoaderInterface
{
    /**
     * @param list<array{definition: Tool, handler: ToolHandlerInterface}>                         $tools
     * @param list<array{definition: ResourceDefinition, handler: ResourceHandlerInterface}>       $resources
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
            $handler = $entry['handler'];
            $registry->registerTool(
                $entry['definition'],
                static fn (array $arguments, ClientGateway $client) => $handler->execute($arguments, $client),
            );
        }

        foreach ($this->resources as $entry) {
            $handler = $entry['handler'];
            $registry->registerResource(
                $entry['definition'],
                static fn (string $uri, ClientGateway $client) => $handler->read($uri, $client),
            );
        }

        foreach ($this->resourceTemplates as $entry) {
            $handler = $entry['handler'];
            $registry->registerResourceTemplate(
                $entry['definition'],
                static fn (string $uri, array $variables, ClientGateway $client) => $handler->read($uri, $variables, $client),
            );
        }

        foreach ($this->prompts as $entry) {
            $handler = $entry['handler'];
            $registry->registerPrompt(
                $entry['definition'],
                static fn (array $arguments, ClientGateway $client) => $handler->get($arguments, $client),
            );
        }
    }
}
