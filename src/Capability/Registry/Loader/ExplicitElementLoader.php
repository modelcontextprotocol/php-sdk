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

use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Capability\Registry\ReferenceHandler;
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
 * Each registered closure is bound to {@see ReferenceHandler} as its scope so the
 * reference handler short-circuits reflection and invokes it with the raw argument bag.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
final class ExplicitElementLoader implements LoaderInterface
{
    /**
     * @param list<array{definition: Tool, handler: ToolHandlerInterface}>                                                                                $tools
     * @param list<array{definition: ResourceDefinition, handler: ResourceHandlerInterface}>                                                              $resources
     * @param list<array{definition: ResourceTemplate, handler: ResourceTemplateHandlerInterface, completionProviders: array<string, ProviderInterface>}> $resourceTemplates
     * @param list<array{definition: Prompt, handler: PromptHandlerInterface, completionProviders: array<string, ProviderInterface>}>                     $prompts
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
            $registry->registerTool($entry['definition'], $this->boundClosure(
                static function (array $arguments) use ($handler): mixed {
                    $gateway = new ClientGateway($arguments['_session']);
                    unset($arguments['_session'], $arguments['_request']);

                    return $handler->execute($arguments, $gateway);
                },
            ));
        }

        foreach ($this->resources as $entry) {
            $handler = $entry['handler'];
            $registry->registerResource($entry['definition'], $this->boundClosure(
                static fn (array $arguments): mixed => $handler->read(
                    $arguments['uri'],
                    new ClientGateway($arguments['_session']),
                ),
            ));
        }

        foreach ($this->resourceTemplates as $entry) {
            $handler = $entry['handler'];
            $registry->registerResourceTemplate($entry['definition'], $this->boundClosure(
                static function (array $arguments) use ($handler): mixed {
                    $gateway = new ClientGateway($arguments['_session']);
                    $uri = $arguments['uri'];
                    unset($arguments['_session'], $arguments['_request'], $arguments['uri']);

                    return $handler->read($uri, $arguments, $gateway);
                },
            ), $entry['completionProviders']);
        }

        foreach ($this->prompts as $entry) {
            $handler = $entry['handler'];
            $registry->registerPrompt($entry['definition'], $this->boundClosure(
                static function (array $arguments) use ($handler): mixed {
                    $gateway = new ClientGateway($arguments['_session']);
                    unset($arguments['_session'], $arguments['_request']);

                    return $handler->get($arguments, $gateway);
                },
            ), $entry['completionProviders']);
        }
    }

    private function boundClosure(\Closure $closure): \Closure
    {
        return \Closure::bind($closure, null, ReferenceHandler::class);
    }
}
