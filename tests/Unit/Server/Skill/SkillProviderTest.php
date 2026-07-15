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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Skills\McpSkills;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Skill\SkillProvider;
use PHPUnit\Framework\TestCase;

class SkillProviderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/skills';

    public function testRegistersSkillAndSupportingFilesAsResources(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES);

        $resources = $this->registeredResources($builder);
        $uris = array_column($resources, 'uri');

        $this->assertContains('skill://code-review/SKILL.md', $uris);
        $this->assertContains('skill://code-review/references/SECURITY.md', $uris);
        $this->assertContains('skill://acme/billing/refunds/SKILL.md', $uris);
        $this->assertContains('skill://index.json', $uris);
    }

    public function testSkillManifestCarriesFrontmatterMetadata(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES);

        $resource = $this->resourceByUri($builder, 'skill://code-review/SKILL.md');

        $this->assertSame(McpSkills::MIME_TYPE, $resource['mimeType']);
        $this->assertSame('code-review', $resource['title']);
        $this->assertSame('Review a pull request.', $resource['description']);
        $this->assertSame(
            [McpSkills::META_PREFIX => ['version' => '1.0.0', 'tags' => ['review']]],
            $resource['meta'],
        );
    }

    public function testResourceNamesAreSanitizedAndSchemaValid(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES);

        foreach ($this->registeredResources($builder) as $resource) {
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $resource['name']);
        }
    }

    public function testReturnsDiscoveryEntries(): void
    {
        $builder = Server::builder();

        $entries = (new SkillProvider())->registerInto($builder, self::FIXTURES);

        $this->assertCount(2, $entries);
        foreach ($entries as $entry) {
            $this->assertNotNull($entry->url);
            $this->assertStringStartsWith('sha256:', (string) $entry->digest);
        }
        $names = array_map(static fn ($e) => $e->frontmatter->name, $entries);
        $this->assertContains('code-review', $names);
        $this->assertContains('refunds', $names);
    }

    public function testDiscoveryIndexCanBeDisabled(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES, withDiscoveryIndex: false);

        $uris = array_column($this->registeredResources($builder), 'uri');
        $this->assertNotContains('skill://index.json', $uris);
    }

    public function testSupportingFileMimeTypeIsGuessed(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES);

        $resource = $this->resourceByUri($builder, 'skill://code-review/references/SECURITY.md');
        $this->assertSame('text/markdown', $resource['mimeType']);
    }

    public function testThrowsWhenFrontmatterNameDoesNotMatchFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match the final path segment');

        (new SkillProvider())->registerInto(Server::builder(), __DIR__.'/Fixtures/mismatch');
    }

    public function testThrowsWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SkillProvider())->registerInto(Server::builder(), __DIR__.'/Fixtures/does-not-exist');
    }

    public function testBuilderHelperAutoEnablesExtension(): void
    {
        $builder = Server::builder()->addSkillsFromDirectory(self::FIXTURES);

        $extensions = $this->readPrivate($builder, 'extensions');
        $this->assertArrayHasKey(McpSkills::EXTENSION_ID, $extensions);
    }

    public function testNoArchivesByDefault(): void
    {
        $builder = Server::builder();

        $entries = (new SkillProvider())->registerInto($builder, self::FIXTURES);

        $uris = array_column($this->registeredResources($builder), 'uri');
        $this->assertNotContains('skill://code-review.tar.gz', $uris);
        foreach ($entries as $entry) {
            $this->assertSame([], $entry->archives);
        }
    }

    public function testArchivesAreRegisteredAndListedWhenRequested(): void
    {
        $builder = Server::builder();

        $entries = (new SkillProvider())->registerInto($builder, self::FIXTURES, archiveFormats: ['application/gzip']);

        $uris = array_column($this->registeredResources($builder), 'uri');
        $this->assertContains('skill://code-review.tar.gz', $uris);
        $this->assertContains('skill://acme/billing/refunds.tar.gz', $uris);

        foreach ($entries as $entry) {
            $this->assertCount(1, $entry->archives);
            $archive = $entry->archives[0];
            $this->assertSame('application/gzip', $archive->mimeType);
            $this->assertStringStartsWith('sha256:', $archive->digest);
            $this->assertStringEndsWith('.tar.gz', $archive->url);
        }
    }

    public function testArchiveDigestMatchesServedBytes(): void
    {
        $builder = Server::builder();

        $entries = (new SkillProvider())->registerInto($builder, self::FIXTURES, archiveFormats: ['application/gzip']);

        $archive = $entries[0]->archives[0];
        $resource = $this->resourceByUri($builder, $archive->url);

        $this->assertSame('application/gzip', $resource['mimeType']);

        $served = base64_decode(($resource['handler'])()['blob']);
        $this->assertSame('sha256:'.hash('sha256', $served), $archive->digest);
    }

    public function testArchiveUnpacksToSkillFiles(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, self::FIXTURES, archiveFormats: ['application/gzip']);

        $resource = $this->resourceByUri($builder, 'skill://code-review.tar.gz');
        $tar = gzdecode(base64_decode(($resource['handler'])()['blob']));

        $this->assertNotFalse($tar);
        $this->assertStringContainsString('SKILL.md', $tar);
        $this->assertStringContainsString('references/SECURITY.md', $tar);
    }

    public function testUnsupportedArchiveFormatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SkillProvider())->registerInto(Server::builder(), self::FIXTURES, archiveFormats: ['application/x-7z-compressed']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function registeredResources(Builder $builder): array
    {
        return $this->readPrivate($builder, 'resources');
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceByUri(Builder $builder, string $uri): array
    {
        foreach ($this->registeredResources($builder) as $resource) {
            if ($resource['uri'] === $uri) {
                return $resource;
            }
        }

        $this->fail(\sprintf('No resource registered for URI "%s".', $uri));
    }

    private function readPrivate(Builder $builder, string $property): mixed
    {
        $reflection = new \ReflectionProperty(Builder::class, $property);

        return $reflection->getValue($builder);
    }
}
