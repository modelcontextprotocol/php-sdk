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

use Mcp\Schema\Annotations;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Exception\InvalidArgumentException;
use Mcp\Schema\ResourceDefinition;
use PHPUnit\Framework\TestCase;

final class AnnotationsTest extends TestCase
{
    public function testLastModifiedDefaultsToNull(): void
    {
        $annotations = new Annotations([Role::User], 0.5);

        $this->assertNull($annotations->lastModified);
        $this->assertArrayNotHasKey('lastModified', $annotations->jsonSerialize());
    }

    public function testLastModifiedIsSerialized(): void
    {
        $annotations = new Annotations([Role::User], 0.8, '2025-01-12T15:00:58Z');

        $this->assertSame(
            '{"audience":["user"],"priority":0.8,"lastModified":"2025-01-12T15:00:58Z"}',
            json_encode($annotations, \JSON_THROW_ON_ERROR),
        );
    }

    public function testFromArrayReadsLastModified(): void
    {
        $annotations = Annotations::fromArray(['lastModified' => '2025-01-12T15:00:58Z']);

        $this->assertSame('2025-01-12T15:00:58Z', $annotations->lastModified);
        $this->assertNull($annotations->audience);
        $this->assertNull($annotations->priority);
    }

    public function testFromArrayRejectsLastModifiedThatIsNotAString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid "lastModified" in Annotations data; expected a string.');

        Annotations::tryFromArray(['lastModified' => 1736694058], 'Test');
    }

    public function testResourceCarriesLastModified(): void
    {
        $resource = ResourceDefinition::fromArray([
            'uri' => 'file:///project/README.md',
            'name' => 'README.md',
            'annotations' => ['audience' => ['user'], 'priority' => 0.8, 'lastModified' => '2025-01-12T15:00:58Z'],
        ]);

        $this->assertSame('2025-01-12T15:00:58Z', $resource->annotations?->lastModified);
        $this->assertStringContainsString('"lastModified":"2025-01-12T15:00:58Z"', json_encode($resource, \JSON_THROW_ON_ERROR));
    }

    public function testContentBlockCarriesLastModified(): void
    {
        $content = TextContent::fromArray([
            'type' => 'text',
            'text' => 'Notes',
            'annotations' => ['lastModified' => '2025-01-12T15:00:58Z'],
        ]);

        $this->assertSame('2025-01-12T15:00:58Z', $content->annotations?->lastModified);
        $this->assertSame(
            '{"type":"text","text":"Notes","annotations":{"lastModified":"2025-01-12T15:00:58Z"}}',
            json_encode($content, \JSON_THROW_ON_ERROR),
        );
    }
}
