<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Content;

use Mcp\Schema\Content\TextContent;
use PHPUnit\Framework\TestCase;

final class TextContentTest extends TestCase
{
    public function testArrayIsEncodedAsCompactJson(): void
    {
        $this->assertSame('{"key":{"nested":1}}', (new TextContent(['key' => ['nested' => 1]]))->text);
    }

    public function testObjectIsEncodedAsCompactJson(): void
    {
        $this->assertSame('{"key":"value"}', (new TextContent((object) ['key' => 'value']))->text);
    }
}
