<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Skill;

use Mcp\Schema\Extension\Skills\Skill;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Server\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class SkillRegistryTest extends TestCase
{
    public function testAddAndGetByUri(): void
    {
        $registry = new SkillRegistry();
        $skill = new Skill('skill://code-review/SKILL.md', new SkillMetadata('code-review', 'Review a PR.'), 'dynamic');

        $registry->add($skill);

        $this->assertSame($skill, $registry->get('skill://code-review/SKILL.md'));
    }

    public function testGetReturnsNullForUnknownUri(): void
    {
        $registry = new SkillRegistry();

        $this->assertNull($registry->get('skill://unknown/SKILL.md'));
    }

    public function testAllReturnsInRegistrationOrder(): void
    {
        $registry = new SkillRegistry();
        $first = new Skill('skill://a/SKILL.md', new SkillMetadata('a', 'A.'), 'dynamic');
        $second = new Skill('skill://b/SKILL.md', new SkillMetadata('b', 'B.'), 'dynamic');

        $registry->add($first);
        $registry->add($second);

        $this->assertSame([$first, $second], $registry->all());
    }

    public function testAddOverwritesSameUri(): void
    {
        $registry = new SkillRegistry();
        $original = new Skill('skill://a/SKILL.md', new SkillMetadata('a', 'Original.'), 'dynamic');
        $updated = new Skill('skill://a/SKILL.md', new SkillMetadata('a', 'Updated.'), 'dynamic');

        $registry->add($original);
        $registry->add($updated);

        $this->assertCount(1, $registry->all());
        $this->assertSame($updated, $registry->get('skill://a/SKILL.md'));
    }
}
