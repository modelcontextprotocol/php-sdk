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

use Mcp\Schema\PromptReference;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Argument completion driven by a `#[CompletionProvider(providerClass: …)]`.
 *
 * The fixture's provider only exists in the container, so completions coming
 * back at all is what proves the attribute reached the registry and the
 * container was asked to build it.
 *
 * @see Fixture/completion.php for the server under test
 */
final class CompletionTest extends IntegrationTestCase
{
    #[TestDox('a providerClass argument completes from the container-built provider')]
    public function testProviderClassCompletesFromTheContainer(): void
    {
        $client = $this->connect('completion');

        $result = $client->complete(new PromptReference('book_seat'), ['name' => 'seat', 'value' => '12']);

        $this->assertSame(['12A', '12B'], $result->values);
    }

    #[TestDox('an empty value offers every completion the provider knows')]
    public function testEmptyValueOffersEveryCompletion(): void
    {
        $client = $this->connect('completion');

        $result = $client->complete(new PromptReference('book_seat'), ['name' => 'seat', 'value' => '']);

        $this->assertSame(['12A', '12B', '14C'], $result->values);
    }

    #[TestDox('an argument without a provider completes to nothing')]
    public function testUnknownArgumentCompletesToNothing(): void
    {
        $client = $this->connect('completion');

        $result = $client->complete(new PromptReference('book_seat'), ['name' => 'unknown', 'value' => '1']);

        $this->assertSame([], $result->values);
    }
}
