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

use Mcp\Schema\Tool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ToolTest extends TestCase
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

    private static function makeTool(?string $title, ?string $description = null): Tool
    {
        return new Tool(
            name: 'x',
            inputSchema: self::validInputSchema(),
            description: $description,
            annotations: null,
            title: $title,
        );
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function serializationKeyOrderProvider(): iterable
    {
        yield 'with title' => ['Friendly Title', ['name', 'title', 'inputSchema']];
        yield 'without title' => [null, ['name', 'inputSchema']];
    }

    /**
     * @param list<string> $expectedKeys
     */
    #[DataProvider('serializationKeyOrderProvider')]
    public function testSerializationPlacesTitleBetweenNameAndInputSchema(?string $title, array $expectedKeys): void
    {
        $serialized = self::makeTool($title)->jsonSerialize();

        $this->assertSame($expectedKeys, array_keys($serialized));
        if (null !== $title) {
            $this->assertSame($title, $serialized['title']);
        } else {
            $this->assertArrayNotHasKey('title', $serialized);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?string}>
     */
    public static function fromArrayTitleProvider(): iterable
    {
        yield 'title present' => [['title' => 'Friendly Title'], 'Friendly Title'];
        yield 'title missing' => [[], null];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('fromArrayTitleProvider')]
    public function testFromArrayReadsTitle(array $extra, ?string $expectedTitle): void
    {
        $tool = Tool::fromArray(['name' => 'x', 'inputSchema' => self::validInputSchema()] + $extra);

        $this->assertSame($expectedTitle, $tool->title);
    }

    public function testRoundTripPreservesTitle(): void
    {
        $original = self::makeTool('Friendly Title', 'desc');

        /** @var array{name: string, title?: string, inputSchema: array{type: 'object', properties: array<string, mixed>, required: string[]|null}, description?: string|null} $serialized */
        $serialized = $original->jsonSerialize();
        $restored = Tool::fromArray($serialized);

        $this->assertSame('Friendly Title', $restored->title);
        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->description, $restored->description);
    }
}
