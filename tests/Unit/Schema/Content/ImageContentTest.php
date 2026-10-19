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

use Mcp\Schema\Annotations;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Enum\Role;
use PHPUnit\Framework\TestCase;

final class ImageContentTest extends TestCase
{
    public function testConstructorDefaultsAnnotationsToNull(): void
    {
        $content = new ImageContent(base64_encode('binary'), 'image/png');

        $this->assertNull($content->annotations);
    }

    public function testConstructorAcceptsAnnotations(): void
    {
        $annotations = new Annotations([Role::User], 0.5);
        $content = new ImageContent(base64_encode('binary'), 'image/png', $annotations);

        $this->assertSame($annotations, $content->annotations);
    }

    public function testFromArrayDeserializesAnnotations(): void
    {
        $content = ImageContent::fromArray([
            'type' => 'image',
            'data' => base64_encode('binary'),
            'mimeType' => 'image/png',
            'annotations' => ['audience' => ['user'], 'priority' => 0.5],
        ]);

        $this->assertNotNull($content->annotations);
        $this->assertSame([Role::User], $content->annotations->audience);
        $this->assertSame(0.5, $content->annotations->priority);
    }

    public function testJsonSerializeOmitsNullAnnotations(): void
    {
        $content = new ImageContent(base64_encode('binary'), 'image/png');

        $this->assertArrayNotHasKey('annotations', $content->jsonSerialize());
    }

    public function testJsonSerializeIncludesAnnotations(): void
    {
        $annotations = new Annotations([Role::User], 0.5);
        $content = new ImageContent(base64_encode('binary'), 'image/png', $annotations);

        $data = $content->jsonSerialize();

        $this->assertSame($annotations, $data['annotations'] ?? null);
    }

    public function testRoundTripWithAnnotations(): void
    {
        $original = new ImageContent(base64_encode('binary'), 'image/png', new Annotations([Role::User], 0.5));

        $decoded = json_decode(json_encode($original, \JSON_THROW_ON_ERROR), true);
        $rehydrated = ImageContent::fromArray($decoded);

        $this->assertSame($original->data, $rehydrated->data);
        $this->assertSame($original->mimeType, $rehydrated->mimeType);
        $this->assertEquals($original->annotations, $rehydrated->annotations);
    }

    public function testFromStringAcceptsAnnotations(): void
    {
        $annotations = new Annotations([Role::User]);
        $content = ImageContent::fromString('binary', 'image/png', $annotations);

        $this->assertSame(base64_encode('binary'), $content->data);
        $this->assertSame($annotations, $content->annotations);
    }
}
