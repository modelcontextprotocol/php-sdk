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
use Mcp\Schema\Extension\Skills\Skill;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Schema\Extension\Skills\SkillResource;
use PHPUnit\Framework\TestCase;

class SkillTest extends TestCase
{
    public function testSerializationWithResourceList(): void
    {
        $skill = new Skill(
            'skill://code-review/SKILL.md',
            new SkillMetadata('code-review', 'Review a pull request.'),
            [new SkillResource('skill://code-review/SKILL.md', 'sha256:'.hash('sha256', 'x'), 1)],
        );

        $serialized = $skill->jsonSerialize();

        $this->assertSame('skill://code-review/SKILL.md', $serialized['uri']);
        $this->assertInstanceOf(SkillMetadata::class, $serialized['frontmatter']);
        $this->assertCount(1, $serialized['resources']);
        $this->assertInstanceOf(SkillResource::class, $serialized['resources'][0]);
    }

    public function testSerializationWithDynamicResources(): void
    {
        $skill = new Skill(
            'skill://reports/daily/SKILL.md',
            new SkillMetadata('daily', 'Assemble a report.'),
            'dynamic',
        );

        $this->assertSame('dynamic', $skill->jsonSerialize()['resources']);
    }

    public function testRejectsNonListResourcesArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Skill(
            'skill://code-review/SKILL.md',
            new SkillMetadata('code-review', 'Review a pull request.'),
            ['not-a-list' => new SkillResource('skill://code-review/SKILL.md', 'sha256:'.hash('sha256', 'x'), 1)],
        );
    }

    public function testFromArrayRoundTrip(): void
    {
        $skill = Skill::fromArray([
            'uri' => 'skill://acme/billing/refunds/SKILL.md',
            'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
            'resources' => [
                ['uri' => 'skill://acme/billing/refunds/SKILL.md', 'digest' => 'sha256:'.hash('sha256', 'x'), 'size' => 1],
            ],
        ]);

        $this->assertSame('refunds', $skill->frontmatter->name);
        $this->assertIsArray($skill->resources);
        $this->assertCount(1, $skill->resources);
    }

    public function testFromArrayAcceptsDynamicResources(): void
    {
        $skill = Skill::fromArray([
            'uri' => 'skill://reports/daily/SKILL.md',
            'frontmatter' => ['name' => 'daily', 'description' => 'Assemble a report.'],
            'resources' => 'dynamic',
        ]);

        $this->assertSame('dynamic', $skill->resources);
    }

    public function testFromArrayRejectsInvalidResources(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /* @phpstan-ignore argument.type (deliberately invalid: neither an array nor "dynamic" must be rejected) */
        Skill::fromArray([
            'uri' => 'skill://reports/daily/SKILL.md',
            'frontmatter' => ['name' => 'daily', 'description' => 'Assemble a report.'],
            'resources' => 'not-dynamic',
        ]);
    }
}
