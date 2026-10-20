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
use Mcp\Schema\Extension\Skills\SkillResource;
use PHPUnit\Framework\TestCase;

class SkillResourceTest extends TestCase
{
    public function testSerialization(): void
    {
        $resource = new SkillResource('skill://code-review/SKILL.md', 'sha256:'.hash('sha256', 'x'), 1);

        $this->assertSame([
            'uri' => 'skill://code-review/SKILL.md',
            'digest' => 'sha256:'.hash('sha256', 'x'),
            'size' => 1,
        ], $resource->jsonSerialize());
    }

    public function testFromArrayRoundTrip(): void
    {
        $digest = 'sha256:'.hash('sha256', 'x');

        $resource = SkillResource::fromArray([
            'uri' => 'skill://code-review/SKILL.md',
            'digest' => $digest,
            'size' => 1,
        ]);

        $this->assertSame('skill://code-review/SKILL.md', $resource->uri);
        $this->assertSame($digest, $resource->digest);
        $this->assertSame(1, $resource->size);
    }

    public function testRejectsMalformedDigest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SkillResource('skill://code-review/SKILL.md', 'not-a-digest', 1);
    }

    public function testRejectsUppercaseHexDigest(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SkillResource('skill://code-review/SKILL.md', 'sha256:'.strtoupper(hash('sha256', 'x')), 1);
    }

    public function testRejectsNegativeSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SkillResource('skill://code-review/SKILL.md', 'sha256:'.hash('sha256', 'x'), -1);
    }
}
