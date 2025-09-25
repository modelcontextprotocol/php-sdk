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

use Mcp\Capability\Attribute\McpResourceTemplate;

#[McpResourceTemplate(uriTemplate: 'invokable://user-profile/{userId}')]
class InvocableResourceTemplateFixture
{
    public function __invoke(string $userId): array
    {
        return ['id' => $userId, 'email' => "user{$userId}@example-invokable.com"];
    }
}
