<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery\PropertyDescriber;

use Mcp\Capability\Discovery\PropertyDescriber\DateTimePropertyDescriber;
use PHPUnit\Framework\TestCase;

final class DateTimePropertyDescriberTest extends TestCase
{
    private DateTimePropertyDescriber $describer;

    protected function setUp(): void
    {
        $this->describer = new DateTimePropertyDescriber();
    }

    public function testSupportsDateTimeInterface(): void
    {
        $this->assertSame(\DateTimeInterface::class, DateTimePropertyDescriber::supportedClass());
    }

    public function testDescribesAsIsoDateTimeString(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $this->describer->describe(),
        );
    }
}
