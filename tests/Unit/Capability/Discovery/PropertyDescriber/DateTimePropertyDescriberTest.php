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

    public function testDescribesDateTimeInterfaceAsIsoDateTimeString(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $this->describer->describe(\DateTimeInterface::class),
        );
    }

    public function testDescribesDateTimeImplementations(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $this->describer->describe(\DateTime::class),
        );
        $this->assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $this->describer->describe(\DateTimeImmutable::class),
        );
    }

    public function testPassesOnUnrelatedClass(): void
    {
        $this->assertNull($this->describer->describe(\stdClass::class));
        $this->assertNull($this->describer->describe(\Exception::class));
    }
}
