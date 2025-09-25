<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Attribute;

use Mcp\Capability\Attribute\CompletionProvider;
use Mcp\Tests\Unit\Fixtures\Enum\StatusEnum;
use PHPUnit\Framework\TestCase;

class CompletionProviderTest extends TestCase
{
    public function testCanBeConstructedWithProviderClass(): void
    {
        $attribute = new CompletionProvider(provider: CompletionProviderFixture::class);

        $this->assertSame(CompletionProviderFixture::class, $attribute->provider);
        $this->assertNull($attribute->values);
        $this->assertNull($attribute->enum);
    }

    public function testCanBeConstructedWithProviderInstance(): void
    {
        $instance = new CompletionProviderFixture();
        $attribute = new CompletionProvider(provider: $instance);

        $this->assertSame($instance, $attribute->provider);
        $this->assertNull($attribute->values);
        $this->assertNull($attribute->enum);
    }

    public function testCanBeConstructedWithValuesArray(): void
    {
        $values = ['draft', 'published', 'archived'];
        $attribute = new CompletionProvider(values: $values);

        $this->assertNull($attribute->provider);
        $this->assertSame($values, $attribute->values);
        $this->assertNull($attribute->enum);
    }

    public function testCanBeConstructedWithEnumClass(): void
    {
        $attribute = new CompletionProvider(enum: StatusEnum::class);

        $this->assertNull($attribute->provider);
        $this->assertNull($attribute->values);
        $this->assertSame(StatusEnum::class, $attribute->enum);
    }

    public function testThrowsExceptionWhenNoParametersProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one of provider, values, or enum can be set');
        new CompletionProvider();
    }

    public function testThrowsExceptionWhenMultipleParametersProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one of provider, values, or enum can be set');
        new CompletionProvider(
            provider: CompletionProviderFixture::class,
            values: ['test']
        );
    }

    public function testThrowsExceptionWhenAllParametersProvided(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one of provider, values, or enum can be set');
        new CompletionProvider(
            provider: CompletionProviderFixture::class,
            values: ['test'],
            enum: StatusEnum::class
        );
    }
}
