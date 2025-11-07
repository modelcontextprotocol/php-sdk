<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Tool;

use Mcp\Capability\Tool\NameValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameValidatorTest extends TestCase
{
    #[DataProvider('provideValidNames')]
    public function testValidNames(string $name): void
    {
        $this->assertTrue((new NameValidator())->isValid($name));
    }

    public static function provideValidNames(): array
    {
        return [
            ['my_tool'],
            ['MyTool123'],
            ['my.tool'],
            ['my-tool'],
            ['my/tool'],
            ['my_tool-01.02'],
            ['my_long_toolname_that_is_exactly_sixty_four_characters_long_1234'],
        ];
    }

    #[DataProvider('provideInvalidNames')]
    public function testInvalidNames(string $name): void
    {
        $this->assertFalse((new NameValidator())->isValid($name));
    }

    public static function provideInvalidNames(): array
    {
        return [
            [''],
            ['my tool'],
            ['my@tool'],
            ['my!tool'],
            ['my_tool#1'],
            ['this_tool_name_is_way_too_long_because_it_exceeds_the_sixty_four_character_limit_set_by_the_validator'],
        ];
    }
}
