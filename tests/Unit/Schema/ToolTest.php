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
            'properties' => [
                'q' => ['type' => 'string'],
            ],
            'required' => null,
        ];
    }

    public function testConstructsWithTitleAndSerializesTitleAfterName(): void
    {
        $tool = new Tool(
            name: 'x',
            title: 'Friendly Title',
            inputSchema: self::validInputSchema(),
            description: null,
            annotations: null,
        );

        $serialized = $tool->jsonSerialize();
        $keys = array_keys($serialized);

        $this->assertSame('Friendly Title', $serialized['title']);
        $this->assertSame('name', $keys[0]);
        $this->assertSame('title', $keys[1]);
        $this->assertSame('inputSchema', $keys[2]);
    }

    public function testSerializesWithoutTitleKeyWhenNull(): void
    {
        $tool = new Tool(
            name: 'x',
            title: null,
            inputSchema: self::validInputSchema(),
            description: null,
            annotations: null,
        );

        $serialized = $tool->jsonSerialize();

        $this->assertArrayNotHasKey('title', $serialized);
        $keys = array_keys($serialized);
        $this->assertSame(['name', 'inputSchema'], $keys);
    }

    public function testFromArrayReadsTitle(): void
    {
        $tool = Tool::fromArray([
            'name' => 'x',
            'title' => 'Friendly Title',
            'inputSchema' => self::validInputSchema(),
        ]);

        $this->assertSame('Friendly Title', $tool->title);
    }

    public function testFromArrayDefaultsTitleToNull(): void
    {
        $tool = Tool::fromArray([
            'name' => 'x',
            'inputSchema' => self::validInputSchema(),
        ]);

        $this->assertNull($tool->title);
    }

    public function testRoundTripPreservesTitle(): void
    {
        $original = new Tool(
            name: 'x',
            title: 'Friendly Title',
            inputSchema: self::validInputSchema(),
            description: 'desc',
            annotations: null,
        );

        /** @var array{name: string, title?: string, inputSchema: array{type: 'object', properties: array<string, mixed>, required: string[]|null}, description?: string|null} $serialized */
        $serialized = $original->jsonSerialize();
        $restored = Tool::fromArray($serialized);

        $this->assertSame('Friendly Title', $restored->title);
        $this->assertSame($original->name, $restored->name);
        $this->assertSame($original->description, $restored->description);
    }
}
