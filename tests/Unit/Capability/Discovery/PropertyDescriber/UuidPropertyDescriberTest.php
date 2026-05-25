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

final class UuidPropertyDescriberTest extends TestCase
{
    private UuidPropertyDescriber $describer;

    protected function setUp(): void
    {
        $this->describer = new UuidPropertyDescriber();
    }

    public function testSupportsUuid(): void
    {
        $this->assertSame(Uuid::class, UuidPropertyDescriber::supportedClass());
    }

    public function testDescribesAsUuidFormatString(): void
    {
        $this->assertSame(
            ['type' => 'string', 'format' => 'uuid'],
            $this->describer->describe(),
        );
    }
}
