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

use Mcp\Schema\Extension\Skills\McpSkills;
use Mcp\Schema\Extension\Skills\SkillDiscoveryEntry;
use Mcp\Schema\Extension\Skills\SkillDiscoveryIndex;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Schema\Extension\Skills\SkillType;
use Mcp\Schema\ServerCapabilities;
use PHPUnit\Framework\TestCase;

class McpSkillsTest extends TestCase
{
    public function testServerExtensionInterface(): void
    {
        $extension = new McpSkills();

        $this->assertSame('io.modelcontextprotocol/skills', $extension->getId());
        $this->assertSame([], $extension->getCapabilities());
    }

    public function testCapabilitiesSerializeAsEmptyObject(): void
    {
        $capabilities = new ServerCapabilities(extensions: [McpSkills::EXTENSION_ID => (new McpSkills())->getCapabilities()]);

        $json = json_encode($capabilities, \JSON_UNESCAPED_SLASHES);

        // The empty extension payload MUST serialize to `{}`, not `[]`.
        $this->assertStringContainsString('"io.modelcontextprotocol/skills":{}', $json);
        $this->assertStringNotContainsString('"io.modelcontextprotocol/skills":[]', $json);
    }

    public function testSkillTypeEnum(): void
    {
        $this->assertSame('skill-md', SkillType::SkillMd->value);
        $this->assertSame('mcp-resource-template', SkillType::McpResourceTemplate->value);
    }

    public function testSkillDiscoveryEntrySerialization(): void
    {
        $entry = new SkillDiscoveryEntry(
            name: 'code-review',
            type: SkillType::SkillMd,
            url: 'skill://code-review/SKILL.md',
            description: 'Review a pull request.',
        );

        $serialized = $entry->jsonSerialize();

        $this->assertSame('code-review', $serialized['name']);
        $this->assertSame('skill-md', $serialized['type']);
        $this->assertSame('Review a pull request.', $serialized['description']);
        $this->assertSame('skill://code-review/SKILL.md', $serialized['url']);
    }

    public function testSkillDiscoveryEntryOmitsNullDescription(): void
    {
        $entry = new SkillDiscoveryEntry('refunds', SkillType::SkillMd, 'skill://refunds/SKILL.md');

        $serialized = $entry->jsonSerialize();

        $this->assertArrayNotHasKey('description', $serialized);
        $this->assertSame('skill://refunds/SKILL.md', $serialized['url']);
    }

    public function testSkillDiscoveryEntryFromArray(): void
    {
        $entry = SkillDiscoveryEntry::fromArray([
            'name' => 'code-review',
            'type' => 'skill-md',
            'url' => 'skill://code-review/SKILL.md',
        ]);

        $this->assertSame('code-review', $entry->name);
        $this->assertSame(SkillType::SkillMd, $entry->type);
        $this->assertSame('skill://code-review/SKILL.md', $entry->url);
        $this->assertNull($entry->description);
    }

    public function testSkillDiscoveryIndexSerialization(): void
    {
        $index = new SkillDiscoveryIndex([
            new SkillDiscoveryEntry('code-review', SkillType::SkillMd, 'skill://code-review/SKILL.md'),
        ]);

        $serialized = $index->jsonSerialize();

        $this->assertSame(SkillDiscoveryIndex::SCHEMA_URL, $serialized['$schema']);
        $this->assertCount(1, $serialized['skills']);
        $this->assertInstanceOf(SkillDiscoveryEntry::class, $serialized['skills'][0]);
    }

    public function testSkillDiscoveryIndexRoundTrip(): void
    {
        $index = SkillDiscoveryIndex::fromArray([
            '$schema' => SkillDiscoveryIndex::SCHEMA_URL,
            'skills' => [
                ['name' => 'refunds', 'type' => 'skill-md', 'url' => 'skill://acme/billing/refunds/SKILL.md'],
            ],
        ]);

        $this->assertCount(1, $index->skills);
        $this->assertSame('refunds', $index->skills[0]->name);
        $this->assertSame('skill://acme/billing/refunds/SKILL.md', $index->skills[0]->url);
    }

    public function testSkillMetadataFromArrayExtractsExtra(): void
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

    public function testSkillMetadataRequiresName(): void
    {
        $this->expectException(\Mcp\Exception\InvalidArgumentException::class);

        SkillMetadata::fromArray(['description' => 'no name here']);
    }

    public function testSkillMetadataSerializationMergesExtra(): void
    {
        $metadata = new SkillMetadata('refunds', 'Process refunds.', ['version' => '2.0.0']);

        $this->assertSame([
            'name' => 'refunds',
            'description' => 'Process refunds.',
            'version' => '2.0.0',
        ], $metadata->jsonSerialize());
    }
}
