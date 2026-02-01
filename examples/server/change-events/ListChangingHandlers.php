<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\ChangeEvents;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\Tool;

final class ListChangingHandlers
{
    public function __construct(
        private readonly RegistryInterface $registry,
    ) {
    }

    public function addPrompt(string $name, string $content): string
    {
        $this->registry->registerPrompt(
            new Prompt($name),
            static fn () => [new PromptMessage(Role::User, new TextContent($content))],
            isManual: true,
        );

        return \sprintf('Prompt "%s" registered.', $name);
    }

    public function addResource(string $uri, string $name): string
    {
        $this->registry->registerResource(
            new Resource($uri, $name),
            static fn () => \sprintf('This is the content of the dynamically added resource "%s" at URI "%s".', $name, $uri),
            true,
        );

        return \sprintf('Resource "%s" registered.', $name);
    }

    public function addTool(string $name): string
    {
        $this->registry->registerTool(
            new Tool(
                $name,
                ['type' => 'object', 'properties' => new \stdClass(), 'required' => []],
                'Dynamically added tool',
                null
            ),
            static fn () => \sprintf('This is the output of the dynamically added tool "%s".', $name),
            true,
        );

        return \sprintf('Tool "%s" registered.', $name);
    }
}
