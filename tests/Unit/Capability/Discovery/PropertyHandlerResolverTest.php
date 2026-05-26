<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\PropertyDenormalizerInterface;
use Mcp\Capability\Discovery\PropertyDescriber\DateTimePropertyDescriber;
use Mcp\Capability\Discovery\PropertyDescriber\UuidPropertyDescriber;
use Mcp\Capability\Discovery\PropertyDescriberInterface;
use Mcp\Capability\Discovery\PropertyHandlerResolver;
use Mcp\Capability\Discovery\PropertyNormalizerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;

final class PropertyHandlerResolverTest extends TestCase
{
    public function testResolvesHandlerForEachConcernItImplements(): void
    {
        $resolver = new PropertyHandlerResolver([new UuidPropertyDescriber()]);

        $this->assertInstanceOf(UuidPropertyDescriber::class, $resolver->resolve(Uuid::class, PropertyDescriberInterface::class));
        $this->assertInstanceOf(UuidPropertyDescriber::class, $resolver->resolve(Uuid::class, PropertyDenormalizerInterface::class));
        $this->assertInstanceOf(UuidPropertyDescriber::class, $resolver->resolve(Uuid::class, PropertyNormalizerInterface::class));
    }

    public function testMatchesSubtypesOfSupportedClass(): void
    {
        $resolver = new PropertyHandlerResolver([new UuidPropertyDescriber()]);

        $this->assertInstanceOf(UuidPropertyDescriber::class, $resolver->resolve(UuidV4::class, PropertyDescriberInterface::class));
    }

    public function testReturnsNullWhenNoHandlerSupportsClass(): void
    {
        $resolver = new PropertyHandlerResolver([new UuidPropertyDescriber()]);

        $this->assertNull($resolver->resolve(\DateTimeImmutable::class, PropertyDescriberInterface::class));
    }

    public function testFiltersByConcernInterface(): void
    {
        $describeOnly = new class implements PropertyDescriberInterface {
            public static function supportedClass(): string
            {
                return Uuid::class;
            }

            public function describe(): array
            {
                return ['type' => 'string'];
            }
        };

        $resolver = new PropertyHandlerResolver([$describeOnly]);

        $this->assertSame($describeOnly, $resolver->resolve(Uuid::class, PropertyDescriberInterface::class));
        $this->assertNull($resolver->resolve(Uuid::class, PropertyDenormalizerInterface::class));
    }

    public function testFirstRegisteredMatchWins(): void
    {
        $first = new class implements PropertyDescriberInterface {
            public static function supportedClass(): string
            {
                return \DateTimeInterface::class;
            }

            public function describe(): array
            {
                return ['type' => 'string', 'format' => 'first'];
            }
        };

        $resolver = new PropertyHandlerResolver([$first, new DateTimePropertyDescriber()]);

        $this->assertSame($first, $resolver->resolve(\DateTimeImmutable::class, PropertyDescriberInterface::class));
    }

    public function testResolutionIsStableAcrossCalls(): void
    {
        $resolver = new PropertyHandlerResolver([new UuidPropertyDescriber()]);

        $this->assertSame(
            $resolver->resolve(Uuid::class, PropertyDescriberInterface::class),
            $resolver->resolve(Uuid::class, PropertyDescriberInterface::class),
        );
    }
}
