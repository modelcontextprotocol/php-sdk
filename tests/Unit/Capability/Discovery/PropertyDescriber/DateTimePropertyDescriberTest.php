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

    public function testDenormalizesStringIntoDateTimeImmutableByDefault(): void
    {
        $date = $this->describer->denormalize('2026-05-26T10:00:00+00:00', \DateTimeInterface::class);

        $this->assertInstanceOf(\DateTimeImmutable::class, $date);
        $this->assertSame('2026-05-26T10:00:00+00:00', $date->format(\DateTimeInterface::ATOM));
    }

    public function testDenormalizesIntoConcreteMutableDateTimeWhenTargeted(): void
    {
        $date = $this->describer->denormalize('2026-05-26T10:00:00+00:00', \DateTime::class);

        $this->assertInstanceOf(\DateTime::class, $date);
    }

    public function testDenormalizePassesThroughExistingInstance(): void
    {
        $date = new \DateTimeImmutable('2026-05-26T10:00:00+00:00');

        $this->assertSame($date, $this->describer->denormalize($date, \DateTimeInterface::class));
    }

    public function testNormalizesInstanceToIso8601String(): void
    {
        $date = new \DateTimeImmutable('2026-05-26T10:00:00+00:00');

        $this->assertSame('2026-05-26T10:00:00+00:00', $this->describer->normalize($date));
    }
}
