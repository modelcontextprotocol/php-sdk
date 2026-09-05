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

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Capability\Attribute\McpPrompt;

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
        #[CompletionProvider(providerClass: SeatCompletionProvider::class)]
        string $seat,
    ): array {
        return [
            ['role' => 'user', 'content' => \sprintf('Book seat %s for me.', $seat)],
        ];
    }
}
