<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Enum;

use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ProtocolVersionTest extends TestCase
{
    #[TestDox('comparisons follow declaration order, whatever shape the values have')]
    public function testDeclarationOrderIsTheOnlyOrdering(): void
    {
        $cases = ProtocolVersion::cases();

        // Deliberately not a string sort. The values are an enumerated set rather
        // than an ordered scalar, so asserting that they collate chronologically
        // would bake in an assumption the enum explicitly refuses to make: a future
        // revision that is not date-shaped must still compare correctly.
        foreach ($cases as $index => $version) {
            $this->assertTrue(
                $version->isAtLeast($version),
                \sprintf('%s should be at least itself.', $version->value),
            );

            foreach (\array_slice($cases, $index + 1) as $newer) {
                $this->assertTrue(
                    $newer->isAtLeast($version),
                    \sprintf('%s is declared after %s and should compare newer.', $newer->value, $version->value),
                );
                $this->assertFalse(
                    $version->isAtLeast($newer),
                    \sprintf('%s should not compare newer than %s.', $version->value, $newer->value),
                );
            }
        }
    }

    #[TestDox('splits the known revisions into a handshake and a modern era')]
    public function testEraSplitCoversEveryCaseExactlyOnce(): void
    {
        $handshake = ProtocolVersion::handshakeVersions();
        $modern = ProtocolVersion::modernVersions();

        // Concatenating the two eras back into the full case list proves the split
        // is a partition: every revision appears, in order, and none appears twice.
        $this->assertSame(ProtocolVersion::cases(), [...$handshake, ...$modern]);
    }

    #[TestDox('2026-07-28 opens the modern era')]
    public function testModernEraStartsAt20260728(): void
    {
        $this->assertSame(ProtocolVersion::V2026_07_28, ProtocolVersion::FIRST_MODERN_VERSION);

        $this->assertFalse(ProtocolVersion::V2025_11_25->isModern());
        $this->assertTrue(ProtocolVersion::V2026_07_28->isModern());
    }

    #[TestDox('latestHandshake() stops short of the modern era')]
    public function testLatestHandshakeStopsBeforeTheModernEra(): void
    {
        // The counter-offer a server makes when it cannot honour the requested
        // version, so this must never drift up into a revision the handshake
        // cannot serve.
        $this->assertSame(ProtocolVersion::V2025_11_25, ProtocolVersion::latestHandshake());
        $this->assertFalse(ProtocolVersion::latestHandshake()->isModern());
    }

    #[TestDox('handshake versions never contain a modern revision')]
    public function testHandshakeVersionsExcludeModernRevisions(): void
    {
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            $this->assertFalse($version->isModern(), \sprintf('%s leaked into the handshake era.', $version->value));
        }
    }

    #[TestDox('compares by declaration order, not by string value')]
    #[DataProvider('provideVersionComparisons')]
    public function testIsAtLeast(ProtocolVersion $version, ProtocolVersion $minimum, bool $expected): void
    {
        $this->assertSame($expected, $version->isAtLeast($minimum));
    }

    /**
     * @return iterable<string, array{ProtocolVersion, ProtocolVersion, bool}>
     */
    public static function provideVersionComparisons(): iterable
    {
        yield 'equal' => [ProtocolVersion::V2025_06_18, ProtocolVersion::V2025_06_18, true];
        yield 'newer' => [ProtocolVersion::V2025_11_25, ProtocolVersion::V2025_06_18, true];
        yield 'older' => [ProtocolVersion::V2024_11_05, ProtocolVersion::V2025_03_26, false];
        yield 'across eras' => [ProtocolVersion::V2026_07_28, ProtocolVersion::V2024_11_05, true];
        yield 'oldest against newest' => [ProtocolVersion::V2024_11_05, ProtocolVersion::V2026_07_28, false];
    }

    #[TestDox('the header-absent default is the revision that introduced the header')]
    public function testDefaultNegotiatedVersion(): void
    {
        $this->assertSame(ProtocolVersion::V2025_03_26, ProtocolVersion::DEFAULT_NEGOTIATED_VERSION);
    }
}
