<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension;

use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class ExtensionIdentifierTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'official tasks' => ['io.modelcontextprotocol/tasks'];
        yield 'official ui' => ['io.modelcontextprotocol/ui'];
        yield 'vendor' => ['com.example/my-extension'];
        yield 'deep prefix' => ['org.example.api.v2/thing'];
        yield 'name with dots' => ['com.example/a.b.c'];
        yield 'name with underscores' => ['com.example/a_b'];
        yield 'digits inside labels' => ['com.example2/x1'];
    }

    #[DataProvider('validIdentifiers')]
    #[TestDox('a well-formed identifier is accepted and stringifies back to itself')]
    public function testValidIdentifiers(string $identifier): void
    {
        $this->assertSame($identifier, (string) new ExtensionIdentifier($identifier));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'no prefix' => ['tasks', 'has no prefix'];
        yield 'empty name' => ['com.example/', 'not a valid extension name'];
        yield 'prefix label starting with a digit' => ['1com.example/x', 'not a valid prefix'];
        yield 'prefix label ending with a hyphen' => ['com.example-/x', 'not a valid prefix'];
        yield 'empty prefix label' => ['com..example/x', 'not a valid prefix'];
        yield 'name starting with a dot' => ['com.example/.x', 'not a valid extension name'];
        yield 'name ending with a hyphen' => ['com.example/x-', 'not a valid extension name'];
        yield 'space in the name' => ['com.example/my extension', 'not a valid extension name'];
    }

    #[DataProvider('invalidIdentifiers')]
    #[TestDox('a malformed identifier is refused at construction, with the reason')]
    public function testInvalidIdentifiers(string $identifier, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        new ExtensionIdentifier($identifier);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reservations(): iterable
    {
        yield 'io.modelcontextprotocol is reserved' => ['io.modelcontextprotocol/tasks', true];
        yield 'dev.mcp is reserved' => ['dev.mcp/thing', true];
        yield 'org.modelcontextprotocol.api is reserved' => ['org.modelcontextprotocol.api/thing', true];
        yield 'com.mcp.tools is reserved' => ['com.mcp.tools/thing', true];
        yield 'com.example.mcp is not: the second label is example' => ['com.example.mcp/thing', false];
        yield 'com.example is not' => ['com.example/thing', false];
        yield 'a single-label prefix has no second label' => ['example/thing', false];
    }

    #[DataProvider('reservations')]
    #[TestDox('the reserved second labels are recognised, and only those')]
    public function testReservedPrefixes(string $identifier, bool $reserved): void
    {
        $this->assertSame($reserved, (new ExtensionIdentifier($identifier))->isReserved());
    }
}
