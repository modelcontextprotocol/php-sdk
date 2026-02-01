<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Attribute;

use Mcp\Capability\Completion\ProviderInterface;

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

        return array_filter(self::$completions, static fn ($item) => str_starts_with($item, $currentValue));
    }
}
