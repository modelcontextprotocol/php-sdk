<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Request;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Request\CallToolRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallToolRequestTest extends TestCase
{
    public function testMissingArgumentsAreEmpty(): void
    {
        $this->assertSame([], self::request(['name' => 'weather'])->arguments);
    }

    public function testEmptyArgumentsSerializeAsAnObject(): void
    {
        $json = json_encode(self::request(['name' => 'weather', 'arguments' => new \stdClass()]), \JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('"arguments":{}', $json);
    }

    public function testAssociativeArgumentsAreAccepted(): void
    {
        $this->assertSame(['city' => 'Berlin'], self::request(['name' => 'weather', 'arguments' => ['city' => 'Berlin']])->arguments);
    }

    public function testObjectParamsKeepTheJsonTypesOfArgumentsOnly(): void
    {
        $arguments = (object) ['filter' => new \stdClass(), 'ids' => []];
        $request = self::request((object) ['name' => 'weather', 'arguments' => $arguments, '_meta' => (object) ['trace' => (object) []]]);

        $this->assertEquals(new \stdClass(), $request->arguments['filter']);
        $this->assertSame([], $request->arguments['ids']);
        $this->assertSame(['trace' => []], $request->getMeta());
    }

    #[DataProvider('invalidArguments')]
    public function testArgumentsMustBeAnObject(mixed $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Parameter "arguments" must be an object.');

        self::request(['name' => 'weather', 'arguments' => $arguments]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'empty list' => [[]];
        yield 'list' => [['Berlin']];
        yield 'string' => ['Berlin'];
        yield 'null' => [null];
    }

    /**
     * @param array<string, mixed>|\stdClass $params
     */
    private static function request(array|\stdClass $params): CallToolRequest
    {
        return CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params]);
    }
}
