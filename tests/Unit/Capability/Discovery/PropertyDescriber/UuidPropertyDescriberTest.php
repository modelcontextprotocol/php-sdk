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

    public function testDenormalizesStringIntoUuidInstance(): void
    {
        $uuid = $this->describer->denormalize('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', Uuid::class);

        $this->assertInstanceOf(Uuid::class, $uuid);
        $this->assertSame('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $uuid->toRfc4122());
    }

    public function testDenormalizePassesThroughExistingInstance(): void
    {
        $uuid = Uuid::v4();

        $this->assertSame($uuid, $this->describer->denormalize($uuid, Uuid::class));
    }

    public function testDenormalizeRejectsMalformedString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->describer->denormalize('not-a-uuid', Uuid::class);
    }

    public function testNormalizesInstanceToRfc4122String(): void
    {
        $uuid = Uuid::fromString('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d');

        $this->assertSame('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d', $this->describer->normalize($uuid));
    }
}
