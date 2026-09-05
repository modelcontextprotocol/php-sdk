<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Content;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\ToolUseContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolUseContentTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $content = ToolUseContent::fromArray([
            'type' => 'tool_use',
            'id' => 'call-1',
            'name' => 'weather',
            'input' => ['city' => 'Paris'],
            '_meta' => ['provider' => 'test'],
        ]);

        $this->assertSame('tool_use', $content->type);
        $this->assertSame('call-1', $content->id);
        $this->assertSame('weather', $content->name);
        $this->assertSame(['city' => 'Paris'], $content->input);
        $this->assertSame(['provider' => 'test'], $content->meta);

        $this->assertSame(
            '{"type":"tool_use","id":"call-1","name":"weather","input":{"city":"Paris"},"_meta":{"provider":"test"}}',
            json_encode($content),
        );
    }

    public function testEmptyInputSerializesAsObject(): void
    {
        $content = new ToolUseContent('call-1', 'ping', []);

        $this->assertSame('{"type":"tool_use","id":"call-1","name":"ping","input":{}}', json_encode($content));
    }

    public function testEmptyInputSurvivesRoundTrip(): void
    {
        $decoded = json_decode(json_encode(new ToolUseContent('call-1', 'ping', []), \JSON_THROW_ON_ERROR), true);

        $this->assertSame([], ToolUseContent::fromArray($decoded)->input);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidData(): iterable
    {
        yield 'missing id' => [['name' => 'weather', 'input' => []]];
        yield 'non-string id' => [['id' => 1, 'name' => 'weather', 'input' => []]];
        yield 'missing name' => [['id' => 'call-1', 'input' => []]];
        yield 'non-string name' => [['id' => 'call-1', 'name' => 1, 'input' => []]];
        yield 'missing input' => [['id' => 'call-1', 'name' => 'weather']];
        yield 'non-array input' => [['id' => 'call-1', 'name' => 'weather', 'input' => 'nope']];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('provideInvalidData')]
    public function testInvalidDataIsRejected(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolUseContent::fromArray($data);
    }

    public function testRejectsListInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ToolUseContent "input" must be a map of argument names, not a list.');

        /* @phpstan-ignore argument.type (deliberately list-shaped) */
        new ToolUseContent('call-1', 'get_weather', ['Berlin']);
    }

    public function testRejectsListInputFromArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'call-1', 'name' => 'x', 'input' => [1]]);
    }

    public function testEmptyInputIsAccepted(): void
    {
        $content = ToolUseContent::fromArray(['type' => 'tool_use', 'id' => 'call-1', 'name' => 'ping', 'input' => []]);

        $this->assertSame([], $content->input);
        $this->assertSame('{"type":"tool_use","id":"call-1","name":"ping","input":{}}', json_encode($content));
    }
}
