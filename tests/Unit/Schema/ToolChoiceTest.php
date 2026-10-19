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
use Mcp\Schema\Enum\ToolChoiceMode;
use Mcp\Schema\ToolChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolChoiceTest extends TestCase
{
    /**
     * @return iterable<string, array{ToolChoiceMode}>
     */
    public static function provideModes(): iterable
    {
        yield 'auto' => [ToolChoiceMode::Auto];
        yield 'required' => [ToolChoiceMode::Required];
        yield 'none' => [ToolChoiceMode::None];
    }

    #[DataProvider('provideModes')]
    public function testRoundTrip(ToolChoiceMode $mode): void
    {
        $choice = new ToolChoice($mode);

        $this->assertSame(\sprintf('{"mode":"%s"}', $mode->value), json_encode($choice));
        $this->assertSame($mode, ToolChoice::fromArray(json_decode(json_encode($choice), true))->mode);
    }

    public function testModeDefaultsToAuto(): void
    {
        $this->assertSame(ToolChoiceMode::Auto, (new ToolChoice())->mode);
        $this->assertSame(ToolChoiceMode::Auto, ToolChoice::fromArray([])->mode);
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool choice mode "any".');

        ToolChoice::fromArray(['mode' => 'any']);
    }

    public function testNonStringModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "mode" in ToolChoice data.');

        ToolChoice::fromArray(['mode' => 1]);
    }

    public function testExplicitNullModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "mode" in ToolChoice data.');

        ToolChoice::fromArray(['mode' => null]);
    }
}
