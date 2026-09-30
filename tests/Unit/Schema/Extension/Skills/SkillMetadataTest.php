<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Schema\Extension\Skills;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use PHPUnit\Framework\TestCase;

class SkillMetadataTest extends TestCase
{
    public function testFromArrayExtractsExtra(): void
    {
        $metadata = SkillMetadata::fromArray([
            'name' => 'code-review',
            'description' => 'Review a pull request.',
            'version' => '1.0.0',
            'tags' => ['review', 'quality'],
        ]);

        $this->assertSame('code-review', $metadata->name);
        $this->assertSame('Review a pull request.', $metadata->description);
        $this->assertSame(['version' => '1.0.0', 'tags' => ['review', 'quality']], $metadata->extra);
    }

    public function testFromArrayRequiresName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkillMetadata::fromArray(['description' => 'no name here']);
    }

    public function testFromArrayRequiresDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SkillMetadata::fromArray(['name' => 'refunds']);
    }

    public function testSerializationMergesExtra(): void
    {
        $metadata = new SkillMetadata('refunds', 'Process refunds.', ['version' => '2.0.0']);

        $this->assertSame([
            'name' => 'refunds',
            'description' => 'Process refunds.',
            'version' => '2.0.0',
        ], $metadata->jsonSerialize());
    }
}
