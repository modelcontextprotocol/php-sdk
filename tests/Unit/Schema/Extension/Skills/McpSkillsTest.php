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
use Mcp\Schema\Extension\Skills\SkillArchive;
use Mcp\Schema\Extension\Skills\SkillDiscoveryEntry;
use Mcp\Schema\Extension\Skills\SkillDiscoveryIndex;
use Mcp\Schema\Extension\Skills\SkillMetadata;
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

    public function testSkillDiscoveryEntrySerialization(): void
    {
        $entry = new SkillDiscoveryEntry(
            frontmatter: new SkillMetadata('code-review', 'Review a pull request.'),
            url: 'skill://code-review/SKILL.md',
            digest: 'sha256:abc123',
        );

        $serialized = $entry->jsonSerialize();

        $this->assertSame('skill://code-review/SKILL.md', $serialized['url']);
        $this->assertSame('sha256:abc123', $serialized['digest']);
        $this->assertInstanceOf(SkillMetadata::class, $serialized['frontmatter']);
        $this->assertArrayNotHasKey('archives', $serialized);
    }

    public function testSkillDiscoveryEntryArchiveOnlyOmitsUrlAndDigest(): void
    {
        $entry = new SkillDiscoveryEntry(
            frontmatter: new SkillMetadata('pdf-processing', 'Process PDFs.'),
            archives: [new SkillArchive('skill://pdf-processing.tar.gz', 'application/gzip', 'sha256:def456')],
        );

        $serialized = $entry->jsonSerialize();

        $this->assertArrayNotHasKey('url', $serialized);
        $this->assertArrayNotHasKey('digest', $serialized);
        $this->assertCount(1, $serialized['archives']);
    }

    public function testSkillDiscoveryEntryUrlRequiresDigest(): void
    {
        $this->expectException(\Mcp\Exception\InvalidArgumentException::class);

        new SkillDiscoveryEntry(new SkillMetadata('refunds'), url: 'skill://refunds/SKILL.md');
    }

    public function testSkillDiscoveryEntryRequiresUrlOrArchives(): void
    {
        $this->expectException(\Mcp\Exception\InvalidArgumentException::class);

        new SkillDiscoveryEntry(new SkillMetadata('refunds'));
    }

    public function testSkillDiscoveryEntryFromArray(): void
    {
        $entry = SkillDiscoveryEntry::fromArray([
            'url' => 'skill://code-review/SKILL.md',
            'digest' => 'sha256:abc123',
            'frontmatter' => ['name' => 'code-review', 'description' => 'Review a pull request.'],
        ]);

        $this->assertSame('skill://code-review/SKILL.md', $entry->url);
        $this->assertSame('sha256:abc123', $entry->digest);
        $this->assertSame('code-review', $entry->frontmatter->name);
    }

    public function testSkillArchiveRoundTrip(): void
    {
        $archive = SkillArchive::fromArray([
            'url' => 'skill://pdf-processing.tar.gz',
            'mimeType' => 'application/gzip',
            'digest' => 'sha256:def456',
        ]);

        $this->assertSame([
            'url' => 'skill://pdf-processing.tar.gz',
            'mimeType' => 'application/gzip',
            'digest' => 'sha256:def456',
        ], $archive->jsonSerialize());
    }

    public function testSkillDiscoveryIndexSerialization(): void
    {
        $index = new SkillDiscoveryIndex([
            new SkillDiscoveryEntry(new SkillMetadata('code-review', 'Review a pull request.'), 'skill://code-review/SKILL.md', 'sha256:abc123'),
        ]);

        $serialized = $index->jsonSerialize();

        $this->assertArrayNotHasKey('$schema', $serialized);
        $this->assertCount(1, $serialized['skills']);
        $this->assertInstanceOf(SkillDiscoveryEntry::class, $serialized['skills'][0]);
    }

    public function testSkillDiscoveryIndexRoundTrip(): void
    {
        $index = SkillDiscoveryIndex::fromArray([
            'skills' => [
                [
                    'url' => 'skill://acme/billing/refunds/SKILL.md',
                    'digest' => 'sha256:abc123',
                    'frontmatter' => ['name' => 'refunds', 'description' => 'Process refunds.'],
                ],
            ],
        ]);

        $this->assertCount(1, $index->skills);
        $this->assertSame('refunds', $index->skills[0]->frontmatter->name);
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
