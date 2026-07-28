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

    public function testConstructorNormalizesEmptyInputSchemaPropertiesToObject(): void
    {
        $tool = new Tool(
            name: 'no_params',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: null,
            annotations: null,
        );

        $this->assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);
        $this->assertSame('{"name":"no_params","inputSchema":{"type":"object","properties":{},"required":null}}', json_encode($tool));
    }

    public function testConstructorNormalizesEmptyPropertiesAfterJsonDecodeRoundTrip(): void
    {
        /** @var array{type: 'object', properties: array<string, mixed>, required: null} $schema */
        $schema = json_decode('{"type":"object","properties":{},"required":null}', true);
        $this->assertSame([], $schema['properties']);

        $tool = new Tool('t', null, $schema, null, null);

        $this->assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($tool));
    }

    public function testFromArrayNormalizesNestedEmptyPropertiesRecursively(): void
    {
        $tool = Tool::fromArray([
            'name' => 't',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'filter' => ['type' => 'object', 'properties' => []],
                ],
                'required' => null,
            ],
        ]);

        $this->assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']['filter']['properties']);
        $this->assertStringContainsString('"properties":{}', (string) json_encode($tool->inputSchema['properties']['filter']));
    }

    public function testConstructorNormalizesEmptyOutputSchemaProperties(): void
    {
        $tool = new Tool(
            name: 't',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => null],
            description: null,
            annotations: null,
            outputSchema: ['type' => 'object', 'properties' => []],
        );

        $this->assertInstanceOf(\stdClass::class, $tool->outputSchema['properties']);
        $this->assertStringContainsString('"outputSchema":{"type":"object","properties":{}}', (string) json_encode($tool));
    }

    public function testConstructorNormalizesEmptyPropertiesInsideArrayItems(): void
    {
        $tool = new Tool(
            name: 't',
            title: null,
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'rows' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'properties' => []],
                    ],
                ],
                'required' => null,
            ],
            description: null,
            annotations: null,
        );

        $this->assertInstanceOf(\stdClass::class, $tool->inputSchema['properties']['rows']['items']['properties']);
    }
}
