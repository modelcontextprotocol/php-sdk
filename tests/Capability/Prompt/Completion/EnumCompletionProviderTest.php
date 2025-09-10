<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Tests\Capability\Prompt\Completion;

use stdClass;
use Mcp\Capability\Prompt\Completion\EnumCompletionProvider;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Tests\Fixtures\Enum\PriorityEnum;
use Mcp\Tests\Fixtures\Enum\StatusEnum;
use Mcp\Tests\Fixtures\Enum\UnitEnum;
use PHPUnit\Framework\TestCase;

class EnumCompletionProviderTest extends TestCase
{
    public function testCreatesProviderFromStringBackedEnum(): void
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('');
        $this->assertSame(['draft', 'published', 'archived'], $result);
    }

    public function testCreatesProviderFromIntBackedEnumUsingNames(): void
    {
        $provider = new EnumCompletionProvider(PriorityEnum::class);
        $result = $provider->getCompletions('');

        $this->assertSame(['LOW', 'MEDIUM', 'HIGH'], $result);
    }

    public function testCreatesProviderFromUnitEnumUsingNames(): void
    {
        $provider = new EnumCompletionProvider(UnitEnum::class);
        $result = $provider->getCompletions('');

        $this->assertSame(['Yes', 'No'], $result);
    }

    public function testFiltersStringEnumValuesByPrefix(): void
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('ar');

        $this->assertEquals(['archived'], $result);
    }

    public function testFiltersUnitEnumValuesByPrefix(): void
    {
        $provider = new EnumCompletionProvider(UnitEnum::class);
        $result = $provider->getCompletions('Y');

        $this->assertSame(['Yes'], $result);
    }

    public function testReturnsEmptyArrayWhenNoValuesMatchPrefix(): void
    {
        $provider = new EnumCompletionProvider(StatusEnum::class);
        $result = $provider->getCompletions('xyz');

        $this->assertSame([], $result);
    }

    public function testThrowsExceptionForNonEnumClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "stdClass" is not an enum.');

        new EnumCompletionProvider(stdClass::class);
    }

    public function testThrowsExceptionForNonExistentClass(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "NonExistentClass" is not an enum.');

        new EnumCompletionProvider('NonExistentClass'); /* @phpstan-ignore argument.type */
    }
}
