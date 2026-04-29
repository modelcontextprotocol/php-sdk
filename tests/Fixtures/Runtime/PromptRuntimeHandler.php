<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Fixtures\Runtime;

use Mcp\Capability\Completion\ListCompletionProvider;
use Mcp\Schema\PromptArgument;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\RuntimePromptHandlerInterface;

final class PromptRuntimeHandler implements RuntimePromptHandlerInterface
{
    public function getPromptArguments(): array
    {
        return [new PromptArgument('q', 'The question', true)];
    }

    public function getCompletionProviders(): array
    {
        return ['q' => new ListCompletionProvider(['hello', 'world'])];
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return [];
    }
}
