<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace App;

use Mcp\Capability\Prompt\MetadataInterface;
use Mcp\Capability\Prompt\PromptGetterInterface;
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class ExamplePrompt implements MetadataInterface, PromptGetterInterface
{
    public function get(GetPromptRequest $request): GetPromptResult
    {
        $firstName = $request->arguments['firstName'] ?? null;

        return new GetPromptResult(
            [new PromptMessage(
                Role::User,
                new TextContent(\sprintf('Hello %s', $firstName ?? 'World')),
            )],
            $this->getDescription(),
        );
    }

    public function getName(): string
    {
        return 'Greet';
    }

    public function getDescription(): ?string
    {
        return 'Greet a person with a nice message';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'first name',
                'description' => 'The name of the person to greet',
                'required' => false,
            ],
        ];
    }
}
