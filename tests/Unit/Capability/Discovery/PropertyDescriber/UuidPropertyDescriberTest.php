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

use Mcp\Capability\Discovery\PropertyDescriber\UuidPropertyDescriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use Symfony\Component\Uid\UuidV6;

final class UuidPropertyDescriberTest extends TestCase
{
    private UuidPropertyDescriber $describer;

    protected function setUp(): void
    {
        $this->describer = new UuidPropertyDescriber();
    }

    public function testDescribesUuidAsUuidFormatString(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'uuid'],
            $this->describer->describe(Uuid::class),
        );
    }

    public function testDescribesUuidSubclasses(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'uuid'],
            $this->describer->describe(UuidV4::class),
        );
        $this->assertSame(
            ['type' => 'string', 'format' => 'uuid'],
            $this->describer->describe(UuidV6::class),
        );
    }

    public function testPassesOnUnrelatedClass(): void
    {
        $this->assertNull($this->describer->describe(\stdClass::class));
        $this->assertNull($this->describer->describe(\DateTime::class));
    }
}
