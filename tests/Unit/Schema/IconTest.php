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
use Mcp\Schema\Icon;
use PHPUnit\Framework\TestCase;

class IconTest extends TestCase
{
    public function testValidConstructor()
    {
        $icon = new Icon('https://www.php.net/images/logos/php-logo-white.svg', 'image/svg+xml', ['any']);

        $this->assertSame('https://www.php.net/images/logos/php-logo-white.svg', $icon->src);
        $this->assertSame('image/svg+xml', $icon->mimeType);
        $this->assertSame('any', $icon->sizes[0]);
    }

    public function testConstructorWithMultipleSizes()
    {
        $icon = new Icon('https://example.com/icon.png', 'image/png', ['48x48', '96x96']);

        $this->assertCount(2, $icon->sizes);
        $this->assertSame(['48x48', '96x96'], $icon->sizes);
    }

    public function testConstructorWithAnySizes()
    {
        $icon = new Icon('https://example.com/icon.svg', 'image/png', ['any']);

        $this->assertSame(['any'], $icon->sizes);
    }

    public function testConstructorWithNullOptionalFields()
    {
        $icon = new Icon('https://example.com/icon.png');

        $this->assertSame('https://example.com/icon.png', $icon->src);
        $this->assertNull($icon->mimeType);
        $this->assertNull($icon->sizes);
    }

    public function testInvalidSizesFormatThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        new Icon('https://example.com/icon.png', 'image/png', ['invalid-size']);
    }

    public function testInvalidPixelSizesFormatThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        new Icon('https://example.com/icon.png', 'image/png', ['180x48x48']);
    }

    public function testEmptySrcThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        new Icon('', 'image/png', ['48x48']);
    }

    public function testInvalidSrcThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        new Icon('not-a-url', 'image/png', ['48x48']);
    }

    public function testValidDataUriSrc()
    {
        $dataUri = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA';
        $icon = new Icon($dataUri, 'image/png', ['48x48']);

        $this->assertSame($dataUri, $icon->src);
    }
}
