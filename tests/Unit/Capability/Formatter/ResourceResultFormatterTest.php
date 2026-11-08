<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Formatter;

use Mcp\Capability\Formatter\ResourceResultFormatter;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use PHPUnit\Framework\TestCase;

class ResourceResultFormatterTest extends TestCase
{
    public function testMixedResourcesAreFlattenedInOrder(): void
    {
        $first = new TextResourceContents('file://test', 'text/plain', 'first');
        $second = new TextResourceContents('file://test', 'text/plain', 'second');
        $third = new TextResourceContents('file://test', 'text/plain', 'third');

        $result = (new ResourceResultFormatter())->format([
            $first,
            ['named' => $second, 8 => $first],
            ['named' => $third, 5 => $second],
        ], 'file://test');

        $this->assertSame([$first, $second, $first, $third, $second], $result);
    }

    public function testFormatStringResult(): void
    {
        $result = (new ResourceResultFormatter())->format('content', 'file://test');
        $this->assertCount(1, $result);
        $this->assertInstanceOf(TextResourceContents::class, $result[0]);
    }

    public function testStringThatIsNotValidUtf8IsSentAsBlob(): void
    {
        $latin1 = "caf\xE9";

        $result = (new ResourceResultFormatter())->format($latin1, 'file://test', 'text/plain');

        $this->assertCount(1, $result);
        $this->assertInstanceOf(BlobResourceContents::class, $result[0]);
        $this->assertSame('text/plain', $result[0]->mimeType);
        $this->assertSame(base64_encode($latin1), $result[0]->blob);
        $this->assertNotFalse(json_encode($result[0]), 'The blob can be encoded as JSON.');
    }

    public function testTextFileThatIsNotValidUtf8IsSentAsBlob(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-latin1-');
        $this->assertNotFalse($path);
        file_put_contents($path, "caf\xE9");

        try {
            $result = (new ResourceResultFormatter())->format(new \SplFileInfo($path), 'file://test', 'text/plain');
        } finally {
            unlink($path);
        }

        $this->assertCount(1, $result);
        $this->assertInstanceOf(BlobResourceContents::class, $result[0]);
        $this->assertSame('text/plain', $result[0]->mimeType);
        $this->assertSame(base64_encode("caf\xE9"), $result[0]->blob);
    }

    public function testTextFileThatIsValidUtf8StaysText(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mcp-utf8-');
        $this->assertNotFalse($path);
        file_put_contents($path, 'café');

        try {
            $result = (new ResourceResultFormatter())->format(new \SplFileInfo($path), 'file://test', 'text/plain');
        } finally {
            unlink($path);
        }

        $this->assertInstanceOf(TextResourceContents::class, $result[0]);
        $this->assertSame('café', $result[0]->text);
    }

    public function testTextArrayThatIsNotValidUtf8IsSentAsBlob(): void
    {
        $result = (new ResourceResultFormatter())->format(['text' => "caf\xE9"], 'file://test');

        $this->assertInstanceOf(BlobResourceContents::class, $result[0]);
        $this->assertSame('text/plain', $result[0]->mimeType);
    }

    public function testFormatResourceContents(): void
    {
        $contents = new TextResourceContents('file://test', 'text/plain', 'content');
        $result = (new ResourceResultFormatter())->format($contents, 'file://test');
        $this->assertSame([$contents], $result);
    }

    public function testFormatArrayResultAsCompactJson(): void
    {
        $result = (new ResourceResultFormatter())->format(['key' => ['nested' => 1]], 'test://uri', 'application/json');
        $this->assertInstanceOf(TextResourceContents::class, $result[0]);
        $this->assertSame('{"key":{"nested":1}}', $result[0]->text);

        $result = (new ResourceResultFormatter())->format(['key' => ['nested' => 1]], 'test://uri');
        $this->assertInstanceOf(TextResourceContents::class, $result[0]);
        $this->assertSame('{"key":{"nested":1}}', $result[0]->text);
    }

    public function testFormatWithMimeType(): void
    {
        $result = (new ResourceResultFormatter())->format('content', 'file://test', 'text/html');
        $this->assertCount(1, $result);
        $this->assertSame('text/html', $result[0]->mimeType);
    }
}
