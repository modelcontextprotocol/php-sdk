<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Exception;

use Mcp\Exception\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class AuthorizationExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messageProvider(): iterable
    {
        yield 'a terminal escape sequence' => ["denied: \x1b[2Jsafe", 'denied:  [2Jsafe'];
        yield 'a forged log line' => ["denied\r\nforged line", 'denied forged line'];
        yield 'a C1 control character' => ["denied \u{9B}2J", 'denied  2J'];
        yield 'a delete character' => ["de\x7Fnied", 'de nied'];
        yield 'text in other scripts' => ['verweigert: Größe 日本語', 'verweigert: Größe 日本語'];
    }

    #[DataProvider('messageProvider')]
    #[TestDox('a message can not write control characters: $_dataName')]
    public function testRemovesControlCharacters(string $message, string $expected): void
    {
        $this->assertSame($expected, (new AuthorizationException($message))->getMessage());
    }
}
