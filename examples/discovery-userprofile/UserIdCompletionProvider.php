<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\DiscoveryUserProfile;

use Mcp\Capability\Completion\ProviderInterface;

final class UserIdCompletionProvider implements ProviderInterface
{
    private const AVAILABLE_USER_IDS = ['101', '102', '103'];

    public function getCompletions(string $currentValue): array
    {
        return array_filter(self::AVAILABLE_USER_IDS, fn (string $userId) => str_contains($userId, $currentValue));
    }
}
