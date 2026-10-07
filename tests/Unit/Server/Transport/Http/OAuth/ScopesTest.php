<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Http\OAuth;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Server\Transport\Http\OAuth\Scopes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScopesTest extends TestCase
{
    public function testAcceptsScopeTokensAndDedupes(): void
    {
        $this->assertSame(
            ['mcp:read', 'https://mcp.example.com/tools', '!#[]~', '42'],
            Scopes::normalize(['mcp:read', 'https://mcp.example.com/tools', 'mcp:read', '!#[]~', '42']),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideInvalidScopes(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'tab' => ["a\tb"];
        yield 'quote' => ['a"'];
        yield 'backslash' => ['a\\b'];
        yield 'NUL' => ["a\0b"];
        yield 'DEL' => ["a\x7F"];
        yield 'control character' => ["a\x01b"];
        yield 'non-ASCII' => ['é'];
        yield 'not a string' => [42];
    }

    #[DataProvider('provideInvalidScopes')]
    public function testRejectsInvalidScopes(mixed $scope): void
    {
        $this->expectException(InvalidArgumentException::class);

        Scopes::normalize([$scope]);
    }
}
