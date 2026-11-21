<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\Fixture\Completion;

use Mcp\Server\Capability\Completion\ContextAwareProviderInterface;

/**
 * Suggests the seats of the cabin the client already picked.
 *
 * @author Alejandro Ibarra <alejandro.ibarra@cakedc.com>
 */
final class CabinSeatCompletionProvider implements ContextAwareProviderInterface
{
    private const SEATS = [
        'business' => ['1A', '1C', '2A'],
        'economy' => ['12A', '12B', '14C'],
    ];

    public function getCompletions(string $currentValue): array
    {
        return $this->getCompletionsWithContext($currentValue, []);
    }

    public function getCompletionsWithContext(string $currentValue, array $arguments): array
    {
        $seats = self::SEATS[$arguments['cabin'] ?? ''] ?? array_merge(...array_values(self::SEATS));

        return array_values(array_filter(
            $seats,
            static fn (string $seat): bool => str_starts_with($seat, $currentValue),
        ));
    }
}
