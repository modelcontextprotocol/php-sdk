<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Implementation;
use PHPUnit\Framework\TestCase;

final class ImplementationTest extends TestCase
{
    public function testConstructorDefaults(): void
    {
        $implementation = new Implementation();

        $this->assertSame('app', $implementation->name);
        $this->assertSame('dev', $implementation->version);
        $this->assertNull($implementation->description);
        $this->assertNull($implementation->icons);
        $this->assertNull($implementation->websiteUrl);
    }

    public function testFromArrayWithMinimalData(): void
    {
        $implementation = Implementation::fromArray([
            'name' => 'my-client',
            'version' => '1.2.3',
        ]);

        $this->assertSame('my-client', $implementation->name);
        $this->assertSame('1.2.3', $implementation->version);
        $this->assertNull($implementation->description);
        $this->assertNull($implementation->icons);
        $this->assertNull($implementation->websiteUrl);
    }

    public function testFromArrayWithAllFields(): void
    {
        $implementation = Implementation::fromArray([
            'name' => 'my-client',
            'version' => '1.2.3',
            'description' => 'A test client',
            'icons' => [['src' => 'https://example.com/icon.png']],
            'websiteUrl' => 'https://example.com',
        ]);

        $this->assertSame('my-client', $implementation->name);
        $this->assertSame('1.2.3', $implementation->version);
        $this->assertSame('A test client', $implementation->description);
        $this->assertIsArray($implementation->icons);
        $this->assertCount(1, $implementation->icons);
        $this->assertSame('https://example.com', $implementation->websiteUrl);
    }

    /**
     * Regression test for #392: falsy-but-valid version strings such as "0"
     * were rejected because empty('0') === true.
     */
    public function testFromArrayAcceptsZeroStringVersion(): void
    {
        $implementation = Implementation::fromArray([
            'name' => 'my-client',
            'version' => '0',
        ]);

        $this->assertSame('0', $implementation->version);
    }

    /**
     * Regression test for #392: a name of "0" is a valid string and must be accepted.
     */
    public function testFromArrayAcceptsZeroStringName(): void
    {
        $implementation = Implementation::fromArray([
            'name' => '0',
            'version' => '1.0.0',
        ]);

        $this->assertSame('0', $implementation->name);
    }

    public function testFromArrayThrowsOnMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "name" in Implementation data.');

        /* @phpstan-ignore argument.type */
        Implementation::fromArray(['version' => '1.0.0']);
    }

    public function testFromArrayThrowsOnEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "name" in Implementation data.');

        Implementation::fromArray(['name' => '', 'version' => '1.0.0']);
    }

    public function testFromArrayThrowsOnNonStringName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "name" in Implementation data.');

        /* @phpstan-ignore argument.type */
        Implementation::fromArray(['name' => 123, 'version' => '1.0.0']);
    }

    public function testFromArrayThrowsOnMissingVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "version" in Implementation data.');

        /* @phpstan-ignore argument.type */
        Implementation::fromArray(['name' => 'my-client']);
    }

    public function testFromArrayThrowsOnEmptyVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "version" in Implementation data.');

        Implementation::fromArray(['name' => 'my-client', 'version' => '']);
    }

    public function testFromArrayThrowsOnNonStringVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or missing "version" in Implementation data.');

        /* @phpstan-ignore argument.type */
        Implementation::fromArray(['name' => 'my-client', 'version' => 1]);
    }

    public function testFromArrayThrowsOnNonArrayIcons(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "icons" in Implementation data; expected an array.');

        /* @phpstan-ignore argument.type */
        Implementation::fromArray(['name' => 'my-client', 'version' => '1.0.0', 'icons' => 'nope']);
    }

    public function testJsonSerializeRoundTrip(): void
    {
        $implementation = Implementation::fromArray([
            'name' => 'my-client',
            'version' => '0',
            'description' => 'A test client',
            'websiteUrl' => 'https://example.com',
        ]);

        $this->assertSame([
            'name' => 'my-client',
            'version' => '0',
            'description' => 'A test client',
            'websiteUrl' => 'https://example.com',
        ], $implementation->jsonSerialize());
    }

    public function testJsonSerializeOmitsNullOptionalFields(): void
    {
        $implementation = new Implementation('my-client', '1.0.0');

        $this->assertSame([
            'name' => 'my-client',
            'version' => '1.0.0',
        ], $implementation->jsonSerialize());
    }
}
