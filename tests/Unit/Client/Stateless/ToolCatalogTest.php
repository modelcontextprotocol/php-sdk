<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Client\Stateless;

use Mcp\Client\Stateless\ToolCatalog;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ToolCatalogTest extends TestCase
{
    #[TestDox('mirrors an annotated argument into its header')]
    public function testMirrorsAnnotatedArguments(): void
    {
        $catalog = new ToolCatalog();
        $catalog->record([self::annotatedTool()]);

        $this->assertSame(
            ['Region' => 'us-west1', 'Priority' => '7', 'Verbose' => 'false'],
            $catalog->headersFor('search', ['region' => 'us-west1', 'priority' => 7, 'verbose' => false]),
        );
    }

    #[TestDox('an argument that is absent or null contributes no header')]
    public function testOmittedArgumentsAreNotMirrored(): void
    {
        $catalog = new ToolCatalog();
        $catalog->record([self::annotatedTool()]);

        // An omitted header is how "no value" is said; an empty one would
        // assert that the argument was present and empty.
        $this->assertSame(
            ['Region' => 'us-west1'],
            $catalog->headersFor('search', ['region' => 'us-west1', 'verbose' => null]),
        );
    }

    #[TestDox('an unannotated argument is never mirrored')]
    public function testUnannotatedArgumentsAreNotMirrored(): void
    {
        $catalog = new ToolCatalog();
        $catalog->record([self::annotatedTool()]);

        $this->assertSame([], $catalog->headersFor('search', ['query' => 'SELECT 1']));
    }

    #[TestDox('a tool the client never listed is not second-guessed')]
    public function testUnknownToolIsNeitherMirroredNorRejected(): void
    {
        $catalog = new ToolCatalog();

        $this->assertSame([], $catalog->headersFor('unlisted', ['region' => 'x']));
        $this->assertFalse($catalog->isRejected('unlisted'));
    }

    #[TestDox('a malformed annotation drops that tool and leaves the rest usable')]
    public function testMalformedToolIsDroppedAlone(): void
    {
        $catalog = new ToolCatalog();

        $usable = $catalog->record([
            self::annotatedTool(),
            [
                'name' => 'broken',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['data' => ['type' => 'object', 'x-mcp-header' => 'Data']],
                ],
            ],
        ]);

        $this->assertSame(['search'], array_column($usable, 'name'));
        $this->assertTrue($catalog->isRejected('broken'));
        $this->assertFalse($catalog->isRejected('search'));
        $this->assertStringContainsString('only string, integer and boolean', (string) $catalog->reasonFor('broken'));
    }

    #[TestDox('a later listing replaces what was known about a tool')]
    public function testRelistingReplacesTheVerdict(): void
    {
        $catalog = new ToolCatalog();
        $catalog->record([[
            'name' => 'search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['region' => ['type' => 'object', 'x-mcp-header' => 'Region']],
            ],
        ]]);

        $this->assertTrue($catalog->isRejected('search'));

        $catalog->record([self::annotatedTool()]);

        $this->assertFalse($catalog->isRejected('search'));
        $this->assertSame(['Region' => 'eu'], $catalog->headersFor('search', ['region' => 'eu']));
    }

    /**
     * @return array<string, mixed>
     */
    private static function annotatedTool(): array
    {
        return [
            'name' => 'search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
                    'verbose' => ['type' => 'boolean', 'x-mcp-header' => 'Verbose'],
                    'query' => ['type' => 'string'],
                ],
            ],
        ];
    }
}
