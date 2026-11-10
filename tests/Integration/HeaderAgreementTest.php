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

use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Server\Stateless\StandardHeaderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Agreeing with a hand-written expectation is not the point, agreeing with the other side is.
 */
final class HeaderAgreementTest extends TestCase
{
    /**
     * @return iterable<string, array{array{method: string, params: array<string, mixed>}}>
     */
    public static function providePayloads(): iterable
    {
        yield 'a subject' => [['method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => new \stdClass()]]];
        yield 'a subject that is not header-safe' => [['method' => 'resources/read', 'params' => ['uri' => 'file:///café.txt']]];
        yield 'mirrored arguments' => [['method' => 'tools/call', 'params' => ['name' => 'search', 'arguments' => ['region' => ' padded ', 'priority' => 7]]]];
    }

    /**
     * @param array{method: string, params: array<string, mixed>} $payload
     */
    #[DataProvider('providePayloads')]
    public function testServerAcceptsWhatTheClientEmits(array $payload): void
    {
        $catalog = new ToolCatalog();
        $catalog->record([[
            'name' => 'search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                    'priority' => ['type' => 'integer', 'x-mcp-header' => 'Priority'],
                ],
            ],
        ]]);

        $headers = (new HeaderFactory($catalog))->forMessage($payload, ProtocolVersion::V2026_07_28);

        $this->assertNull((new StandardHeaderValidator())->validate($payload['method'], $payload['params'], $headers));
    }
}
