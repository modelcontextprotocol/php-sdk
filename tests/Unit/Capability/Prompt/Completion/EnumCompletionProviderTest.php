<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Prompt\Completion;

use Mcp\Capability\Prompt\Completion\EnumCompletionProvider;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Tests\Unit\Fixtures\Enum\PriorityEnum;
use Mcp\Tests\Unit\Fixtures\Enum\StatusEnum;
use Mcp\Tests\Unit\Fixtures\Enum\UnitEnum;
use PHPUnit\Framework\TestCase;

class EnumCompletionProviderTest extends TestCase
{
    public function testCreatesProviderFromStringBackedEnum()
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('');
        $this->assertSame(['draft', 'published', 'archived'], $result);
    }

    public function testCreatesProviderFromIntBackedEnumUsingNames()
    {
        $provider = new EnumCompletionProvider(PriorityEnum::class);
        $result = $provider->getCompletions('');

        $this->assertSame(['LOW', 'MEDIUM', 'HIGH'], $result);
    }

    public function testCreatesProviderFromUnitEnumUsingNames()
    {
        $provider = new EnumCompletionProvider(UnitEnum::class);
        $result = $provider->getCompletions('');

        $this->assertSame(['Yes', 'No'], $result);
    }

    public function testFiltersStringEnumValuesByPrefix()
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('ar');

        $this->assertEquals(['archived'], $result);
    }

    public function testFiltersUnitEnumValuesByPrefix()
    {
        $provider = new EnumCompletionProvider(UnitEnum::class);
        $result = $provider->getCompletions('Y');

        $this->assertSame(['Yes'], $result);
    }

    public function testReturnsEmptyArrayWhenNoValuesMatchPrefix()
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('xyz');

        $this->assertSame([], $result);
    }

    public function testThrowsExceptionForNonEnumClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "stdClass" is not an enum.');

        new EnumCompletionProvider(\stdClass::class);
    }

    public function testThrowsExceptionForNonExistentClass()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "NonExistentClass" is not an enum.');

        new EnumCompletionProvider('NonExistentClass'); /* @phpstan-ignore argument.type */
    }
}
