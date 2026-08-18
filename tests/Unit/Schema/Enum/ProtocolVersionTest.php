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

        // Deliberately not a string sort: asserting the values collate
        // chronologically would bake in the date shape the enum refuses to assume.
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

        // A partition: every revision in exactly one era, in declaration order.
        $this->assertSame(ProtocolVersion::cases(), [...$handshake, ...$modern]);
    }

    #[TestDox('every known revision is assigned to an era on purpose')]
    public function testEveryRevisionIsClassifiedExplicitly(): void
    {
        // Hand-maintained on purpose: a revision appended below FIRST_MODERN_VERSION
        // is classified modern by accident, and every other assertion in this file
        // stays green while it vanishes from the handshake negotiation.
        $eras = [
            '2024-11-05' => false,
            '2025-03-26' => false,
            '2025-06-18' => false,
            '2025-11-25' => false,
            '2026-07-28' => true,
        ];

        $this->assertSame(
            array_keys($eras),
            array_map(static fn (ProtocolVersion $version): string => $version->value, ProtocolVersion::cases()),
            'A revision was added or removed: list it above with the era it belongs to.',
        );

        foreach (ProtocolVersion::cases() as $version) {
            $this->assertSame(
                $eras[$version->value],
                $version->isModern(),
                \sprintf('%s is in the wrong era; a handshake revision must be declared above %s.', $version->value, ProtocolVersion::FIRST_MODERN_VERSION->value),
            );
        }
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
    public function testDefaultHeaderVersion(): void
    {
        $this->assertSame(ProtocolVersion::V2025_03_26, ProtocolVersion::DEFAULT_HEADER_VERSION);
    }

    #[TestDox('SEP-2106 lifts the object-only rule for structuredContent')]
    public function testRequiresObjectStructuredContent(): void
    {
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            $this->assertTrue($version->requiresObjectStructuredContent(), \sprintf('%s predates SEP-2106.', $version->value));
        }

        $this->assertFalse(ProtocolVersion::V2026_07_28->requiresObjectStructuredContent());
    }

    #[TestDox('SEP-2164 moves resource-not-found from -32002 to -32602')]
    public function testUsesInvalidParamsForResourceNotFound(): void
    {
        foreach (ProtocolVersion::handshakeVersions() as $version) {
            $this->assertFalse(
                $version->usesInvalidParamsForResourceNotFound(),
                \sprintf('%s predates SEP-2164 and still expects -32002.', $version->value),
            );
        }

        $this->assertTrue(ProtocolVersion::V2026_07_28->usesInvalidParamsForResourceNotFound());
    }
}
