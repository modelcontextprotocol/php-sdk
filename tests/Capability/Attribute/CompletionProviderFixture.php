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

namespace Mcp\Tests\Capability\Attribute;

use Mcp\Capability\Prompt\Completion\ProviderInterface;

class CompletionProviderFixture implements ProviderInterface
{
    /**
     * @var string[]
     */
    public static array $completions = ['alpha', 'beta', 'gamma'];
    public static string $lastCurrentValue = '';

    public function getCompletions(string $currentValue): array
    {
        self::$lastCurrentValue = $currentValue;

        return array_filter(self::$completions, fn (string $item): bool => str_starts_with($item, $currentValue));
    }
}
