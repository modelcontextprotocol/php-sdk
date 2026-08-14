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

    /**
     * Each case is a schema that is already valid JSON: decoding it collapses every `{}`
     * to `[]`, and normalization has to restore it verbatim.
     *
     * @return iterable<string, array{string}>
     */
    public static function emptySubSchemaProvider(): iterable
    {
        yield 'top-level properties' => ['{"type":"object","properties":{}}'];
        yield 'nested properties' => ['{"type":"object","properties":{"filter":{"type":"object","properties":{}}}}'];
        yield 'property schema' => ['{"type":"object","properties":{"anything":{}}}'];
        yield 'items schema' => ['{"type":"object","properties":{"tags":{"type":"array","items":{}}}}'];
        yield 'tuple items schema' => ['{"type":"object","properties":{"pair":{"type":"array","items":[{"type":"object","properties":{}},{}]}}}'];
        yield 'additionalItems schema' => ['{"type":"object","properties":{"tags":{"type":"array","additionalItems":{}}}}'];
        yield 'additionalProperties schema' => ['{"type":"object","properties":{"map":{"type":"object","additionalProperties":{}}}}'];
        yield 'propertyNames schema' => ['{"type":"object","properties":{"map":{"type":"object","propertyNames":{}}}}'];
        yield 'contains schema' => ['{"type":"object","properties":{"tags":{"type":"array","contains":{}}}}'];
        yield 'unevaluatedItems schema' => ['{"type":"object","properties":{"tags":{"type":"array","unevaluatedItems":{}}}}'];
        yield 'unevaluatedProperties schema' => ['{"type":"object","properties":{"map":{"type":"object","unevaluatedProperties":{}}}}'];
        yield 'not schema' => ['{"type":"object","properties":{"a":{"not":{}}}}'];
        yield 'if/then/else schemas' => ['{"type":"object","properties":{"a":{"if":{},"then":{"type":"object","properties":{}},"else":{}}}}'];
        yield '$defs entry' => ['{"type":"object","properties":{"a":{"$ref":"#/$defs/E"}},"$defs":{"E":{"type":"object","properties":{}}}}'];
        yield 'definitions entry' => ['{"type":"object","properties":{"a":{"$ref":"#/definitions/E"}},"definitions":{"E":{}}}'];
        yield 'patternProperties entry' => ['{"type":"object","properties":{"map":{"type":"object","patternProperties":{"^x":{}}}}}'];
        yield 'dependentSchemas entry' => ['{"type":"object","properties":{"a":{"type":"string"}},"dependentSchemas":{"a":{}}}'];
        yield 'combinator branches' => ['{"type":"object","properties":{"a":{"anyOf":[{},{"type":"object","properties":{}}],"oneOf":[{}],"allOf":[{}]}}}'];
        yield 'prefixItems entry' => ['{"type":"object","properties":{"a":{"type":"array","prefixItems":[{},{"type":"object","properties":{}}]}}}'];
    }

    #[DataProvider('emptySubSchemaProvider')]
    public function testConstructorNormalizesEmptySubSchemas(string $schemaJson): void
    {
        /** @var array{type: 'object', properties: array<string, mixed>, required: string[]|null} $schema */
        $schema = json_decode($schemaJson, true, 512, \JSON_THROW_ON_ERROR);

        $tool = new Tool(name: 't', title: null, inputSchema: $schema, description: null, annotations: null);

        $this->assertSame($schemaJson, json_encode($tool->inputSchema, \JSON_UNESCAPED_SLASHES));
    }

    #[DataProvider('emptySubSchemaProvider')]
    public function testConstructorNormalizesEmptySubSchemasInOutputSchema(string $schemaJson): void
    {
        /** @var array{type: 'object', properties?: array<string, mixed>} $schema */
        $schema = json_decode($schemaJson, true, 512, \JSON_THROW_ON_ERROR);

        $tool = new Tool(
            name: 't',
            title: null,
            inputSchema: self::validInputSchema(),
            description: null,
            annotations: null,
            outputSchema: $schema,
        );

        $this->assertSame($schemaJson, json_encode($tool->outputSchema, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preservedEmptyArrayProvider(): iterable
    {
        yield 'empty combinator list' => ['{"type":"object","properties":{},"allOf":[]}'];
        yield 'empty prefixItems list' => ['{"type":"object","properties":{},"prefixItems":[]}'];
        yield 'empty required list' => ['{"type":"object","properties":{},"required":[]}'];
        yield 'empty enum list' => ['{"type":"object","properties":{"a":{"enum":[]}}}'];
        yield 'empty dependentRequired list' => ['{"type":"object","properties":{"a":{}},"dependentRequired":{"a":[]}}'];
    }

    /**
     * Keywords that hold JSON arrays — not sub-schemas — must keep encoding as `[]`.
     */
    #[DataProvider('preservedEmptyArrayProvider')]
    public function testConstructorLeavesNonSchemaEmptyArraysAlone(string $schemaJson): void
    {
        /** @var array{type: 'object', properties: array<string, mixed>, required: string[]|null} $schema */
        $schema = json_decode($schemaJson, true, 512, \JSON_THROW_ON_ERROR);

        $tool = new Tool(name: 't', title: null, inputSchema: $schema, description: null, annotations: null);

        $this->assertSame($schemaJson, json_encode($tool->inputSchema, \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Regression test for #151: `SchemaGenerator` emits `items: {}` for untyped arrays,
     * but a client decoding that payload gets `items: []` back — re-serializing it used to
     * hand strict clients the very schema #151 fixed.
     */
    public function testFromArrayRoundTripPreservesEmptyItemsSchema(): void
    {
        $tool = new Tool(
            name: 't',
            title: null,
            inputSchema: [
                'type' => 'object',
                'properties' => ['tags' => ['type' => 'array', 'items' => new \stdClass()]],
                'required' => null,
            ],
            description: null,
            annotations: null,
        );

        $wire = (string) json_encode($tool);
        $this->assertStringContainsString('"items":{}', $wire);

        /** @var array{name: string, inputSchema: array{type: 'object', properties: array<string, mixed>, required: string[]|null}} $decoded */
        $decoded = json_decode($wire, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame($wire, json_encode(Tool::fromArray($decoded)));
    }
}
