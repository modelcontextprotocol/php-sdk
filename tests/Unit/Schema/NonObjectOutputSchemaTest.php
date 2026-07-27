<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * SEP-2106 dropped the object-only restriction on `outputSchema` and widened
 * `structuredContent` to any JSON value.
 */
class NonObjectOutputSchemaTest extends TestCase
{
    /**
     * @return array{type: 'object', properties: array<string, mixed>, required: string[]|null}
     */
    private static function validInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => null,
        ];
    }

    /**
     * @param array<string, mixed> $outputSchema
     */
    #[TestDox('outputSchema accepts a non-object root')]
    #[DataProvider('provideNonObjectSchemas')]
    public function testAcceptsNonObjectOutputSchema(array $outputSchema): void
    {
        $tool = Tool::fromArray([
            'name' => 'demo',
            'inputSchema' => self::validInputSchema(),
            'outputSchema' => $outputSchema,
        ]);

        $this->assertSame($outputSchema, $tool->outputSchema);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideNonObjectSchemas(): iterable
    {
        yield 'array' => [['type' => 'array', 'items' => ['type' => 'string']]];
        yield 'string' => [['type' => 'string']];
        yield 'number' => [['type' => 'number']];
        yield 'boolean' => [['type' => 'boolean']];
        yield 'composition without a root type' => [['oneOf' => [['type' => 'string'], ['type' => 'number']]]];
    }

    #[TestDox('inputSchema still requires an object root, since tool arguments are always an object')]
    public function testInputSchemaStillRequiresObjectRoot(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore-next-line argument.type (deliberately invalid: an array root must be rejected) */
        Tool::fromArray([
            'name' => 'demo',
            'inputSchema' => ['type' => 'array', 'properties' => [], 'required' => null],
        ]);
    }

    #[TestDox('structuredContent holds any JSON value')]
    #[DataProvider('provideStructuredValues')]
    public function testStructuredContentHoldsAnyJsonValue(mixed $value): void
    {
        $result = new CallToolResult([new TextContent('ok')], false, $value);

        $this->assertSame($value, $result->structuredContent);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideStructuredValues(): iterable
    {
        yield 'list' => [[1, 2, 3]];
        yield 'string' => ['hello'];
        yield 'int' => [42];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'zero' => [0];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'empty array' => [[]];
    }

    #[TestDox('a truthy non-object value reaches the wire')]
    public function testNonObjectValueIsSerialized(): void
    {
        $result = new CallToolResult([new TextContent('ok')], false, [1, 2, 3]);

        $this->assertSame([1, 2, 3], $result->jsonSerialize()['structuredContent']);
    }

    #[TestDox('a null structuredContent is omitted entirely')]
    public function testNullStructuredContentOmitted(): void
    {
        $result = new CallToolResult([new TextContent('ok')]);

        $this->assertArrayNotHasKey('structuredContent', $result->jsonSerialize());
    }

    /**
     * Emission of falsy values stays gated until results serialize per negotiated
     * protocol version: every version this SDK speaks today still requires
     * structuredContent to be an object.
     */
    #[TestDox('falsy values are still withheld from the wire pending version-gated serialization')]
    public function testFalsyValuesAreNotYetEmitted(): void
    {
        $result = new CallToolResult([new TextContent('ok')], false, []);

        $this->assertSame([], $result->structuredContent);
        $this->assertArrayNotHasKey('structuredContent', $result->jsonSerialize());
    }

    #[TestDox('round-trips a non-object structuredContent through fromArray')]
    public function testRoundTrip(): void
    {
        $result = CallToolResult::fromArray([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'structuredContent' => ['a', 'b'],
        ]);

        $this->assertSame(['a', 'b'], $result->structuredContent);
    }
}
