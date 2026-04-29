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
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\RuntimeResourceTemplateHandlerInterface;

final class ResourceTemplateRuntimeHandler implements RuntimeResourceTemplateHandlerInterface
{
    public function getCompletionProviders(): array
    {
        return ['userId' => new ListCompletionProvider(['alice', 'bob'])];
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return ['ok' => true];
    }
}
