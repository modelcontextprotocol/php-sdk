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

use Mcp\Capability\Completion\ProviderInterface;

/**
 * A provider that cannot be built without its seat map.
 *
 * The constructor takes a scalar the auto-wiring container cannot supply, so a
 * completion that comes back with seats in it proves the provider was taken
 * from the container rather than instantiated on the spot.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SeatCompletionProvider implements ProviderInterface
{
    /**
     * @param list<string> $seats
     */
    public function __construct(
        private readonly array $seats,
    ) {
    }

    public function getCompletions(string $currentValue): array
    {
        return array_values(array_filter(
            $this->seats,
            static fn (string $seat): bool => str_starts_with($seat, $currentValue),
        ));
    }
}
