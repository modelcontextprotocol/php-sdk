<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The elicitation example, driven from both eras against one endpoint.
 *
 * This is the example where the two lifecycles genuinely part ways: the
 * handshake era can interrupt a call to ask the user something, and the modern
 * era cannot. Its handlers fork in one place — a private `ask()` — and these
 * tests are what say the fork works both ways round.
 */
final class DualEraElicitationTest extends DualEraExampleTestCase
{
    protected const ANSWERS = [
        'confirm' => true,
        'party_size' => 4,
        'date' => '2026-09-01',
        'dietary' => 'vegan',
        'rating' => '5',
        'comments' => 'Excellent',
    ];

    protected static function server(): string
    {
        return __DIR__.'/../../examples/server/elicitation/server.php';
    }

    protected static function portBase(): int
    {
        return 9500;
    }

    #[DataProvider('provideEras')]
    #[TestDox('a confirmation is collected on $_dataName')]
    public function testConfirmAction(ProtocolVersion $era): void
    {
        $client = $this->connect($era, elicitation: true);

        $result = $client->callTool('confirm_action', ['actionDescription' => 'delete the staging database']);

        $this->assertStringContainsString('Action confirmed', self::text($result));

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('a multi-field form is collected on $_dataName')]
    public function testBookRestaurant(ProtocolVersion $era): void
    {
        $client = $this->connect($era, elicitation: true);

        $result = $client->callTool('book_restaurant', ['restaurantName' => 'Osteria']);

        $this->assertStringContainsString('Reservation confirmed at Osteria for 4 guests', self::text($result));

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('feedback with an optional field is collected on $_dataName')]
    public function testCollectFeedback(ProtocolVersion $era): void
    {
        $client = $this->connect($era, elicitation: true);

        $result = $client->callTool('collect_feedback', ['topic' => 'the new checkout flow']);

        $this->assertStringContainsString('Thank you for your feedback', self::text($result));

        $client->disconnect();
    }

    #[DataProvider('provideEras')]
    #[TestDox('a client that declares no elicitation is told what to do instead, on $_dataName')]
    public function testWithoutTheCapability(ProtocolVersion $era): void
    {
        $client = $this->connect($era);

        // Both eras refuse an ask the client cannot answer. The handshake era
        // finds out from the capability the client declared at initialize, the
        // modern era from the envelope on this very request — and the example
        // says the same thing either way.
        try {
            $answer = self::text($client->callTool('confirm_action', ['actionDescription' => 'anything']));
            $this->assertStringContainsString('does not support elicitation', $answer);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('did not declare it can provide', $e->getMessage());
        }

        $client->disconnect();
    }
}
