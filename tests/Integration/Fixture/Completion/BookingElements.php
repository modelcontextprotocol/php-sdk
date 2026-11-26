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

use Mcp\Server\Capability\Attribute\CompletionProvider;
use Mcp\Server\Capability\Attribute\McpPrompt;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class BookingElements
{
    /**
     * Confirms a seat booking.
     *
     * @param string $seat the seat to book
     *
     * @return array the prompt messages
     */
    #[McpPrompt(name: 'book_seat')]
    public function bookSeat(
        #[CompletionProvider(provider: SeatCompletionProvider::class)]
        string $seat,
    ): array {
        return [
            ['role' => 'user', 'content' => \sprintf('Book seat %s for me.', $seat)],
        ];
    }

    /**
     * Confirms a seat booking in a given cabin.
     *
     * @param string $cabin the cabin to book in
     * @param string $seat  the seat to book
     *
     * @return array the prompt messages
     */
    #[McpPrompt(name: 'book_cabin_seat')]
    public function bookCabinSeat(
        string $cabin,
        #[CompletionProvider(provider: CabinSeatCompletionProvider::class)]
        string $seat,
    ): array {
        return [
            ['role' => 'user', 'content' => \sprintf('Book seat %s in %s for me.', $seat, $cabin)],
        ];
    }
}
