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
use Mcp\Schema\Extension\Skills\SkillResource;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Skill\SkillProvider;
use Mcp\Server\Skill\SkillRegistry;
use PHPUnit\Framework\TestCase;

class SkillProviderTest extends TestCase
{
    private const FIXTURES = __DIR__.'/Fixtures/skills';

    public function testRegistersSkillAndSupportingFilesAsResources(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);

        $uris = array_column($this->registeredResources($builder), 'uri');

        $this->assertContains('skill://code-review/SKILL.md', $uris);
        $this->assertContains('skill://code-review/references/SECURITY.md', $uris);
        $this->assertContains('skill://acme/billing/refunds/SKILL.md', $uris);
    }

    public function testSkillManifestResourceUsesFrontmatterNameAndDescription(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);

        $resource = $this->resourceByUri($builder, 'skill://code-review/SKILL.md');

        $this->assertSame(McpSkills::MIME_TYPE, $resource['mimeType']);
        $this->assertSame('code-review', $resource['name']);
        $this->assertSame('Review a pull request.', $resource['description']);
        $this->assertSame(
            ['io.modelcontextprotocol.skills/version' => '1.0.0', 'io.modelcontextprotocol.skills/tags' => ['review']],
            $resource['meta'],
        );
    }

    public function testSupportingFileHasNoExtraMeta(): void
    {
        $builder = Server::builder();

        (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);

        $resource = $this->resourceByUri($builder, 'skill://code-review/references/SECURITY.md');

        $this->assertSame('text/markdown', $resource['mimeType']);
        $this->assertNull($resource['meta']);
    }

    public function testReturnsSkillsWithCompleteResourceManifest(): void
    {
        $builder = Server::builder();

        $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);

        $this->assertCount(2, $skills);

        $codeReview = current(array_filter($skills, static fn ($s) => 'code-review' === $s->frontmatter->name));
        $this->assertNotFalse($codeReview);
        $this->assertIsArray($codeReview->resources);
        $this->assertCount(2, $codeReview->resources); // SKILL.md + references/SECURITY.md

        $manifestEntry = current(array_filter($codeReview->resources, static fn (SkillResource $r) => $r->uri === $codeReview->uri));
        $this->assertNotFalse($manifestEntry);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $manifestEntry->digest);
        $this->assertGreaterThan(0, $manifestEntry->size);
    }

    public function testDigestMatchesServedManifestBytes(): void
    {
        $builder = Server::builder();

        $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);
        $codeReview = current(array_filter($skills, static fn ($s) => 'code-review' === $s->frontmatter->name));

        $resource = $this->resourceByUri($builder, $codeReview->uri);
        /** @var \SplFileInfo $file */
        $file = ($resource['handler'])();
        $served = (string) file_get_contents($file->getPathname());

        $manifestEntry = current(array_filter($codeReview->resources, static fn (SkillResource $r) => $r->uri === $codeReview->uri));
        $this->assertSame('sha256:'.hash('sha256', $served), $manifestEntry->digest);
        $this->assertSame(\strlen($served), $manifestEntry->size);
    }

    public function testRegistersEntriesIntoRegistry(): void
    {
        $builder = Server::builder();
        $registry = new SkillRegistry();

        (new SkillProvider())->registerInto($builder, $registry, self::FIXTURES);

        $this->assertNotNull($registry->get('skill://code-review/SKILL.md'));
        $this->assertNotNull($registry->get('skill://acme/billing/refunds/SKILL.md'));
    }

    public function testNestedSkillIsRegisteredOnceWithItsOwnMetadata(): void
    {
        $builder = Server::builder();

        $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), __DIR__.'/Fixtures/nested');

        $this->assertCount(2, $skills);

        // Registered as a resource exactly once, under its own frontmatter — not the generic
        // "supporting file" metadata a naive walk of the parent skill's directory would produce.
        $resource = $this->resourceByUri($builder, 'skill://parent-skill/nested-skill/SKILL.md');
        $this->assertSame('nested-skill', $resource['name']);
        $this->assertSame('A skill nested inside another skill\'s directory.', $resource['description']);

        $uris = array_column($this->registeredResources($builder), 'uri');
        $this->assertCount(1, array_filter($uris, static fn ($uri) => 'skill://parent-skill/nested-skill/SKILL.md' === $uri));
    }

    public function testNestedSkillManifestListsItInBothEntries(): void
    {
        $builder = Server::builder();

        $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), __DIR__.'/Fixtures/nested');

        $parent = current(array_filter($skills, static fn ($s) => 'parent-skill' === $s->frontmatter->name));
        $nested = current(array_filter($skills, static fn ($s) => 'nested-skill' === $s->frontmatter->name));
        $this->assertNotFalse($parent);
        $this->assertNotFalse($nested);

        $this->assertIsArray($parent->resources);
        $this->assertCount(2, $parent->resources); // its own SKILL.md + the nested one

        $this->assertIsArray($nested->resources);
        $this->assertCount(1, $nested->resources); // just its own SKILL.md
        $this->assertSame('skill://parent-skill/nested-skill/SKILL.md', $nested->resources[0]->uri);
    }

    public function testThrowsWhenFrontmatterNameDoesNotMatchFolder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must match the final path segment');

        (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), __DIR__.'/Fixtures/mismatch');
    }

    public function testThrowsWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), __DIR__.'/Fixtures/does-not-exist');
    }

    public function testBuilderHelperAutoEnablesExtension(): void
    {
        $builder = Server::builder()->addSkillsFromDirectory(self::FIXTURES);

        $extensions = $this->readPrivate($builder, 'extensions');
        $this->assertArrayHasKey(McpSkills::EXTENSION_ID, $extensions);
    }

    public function testBuilderHelperAccumulatesAcrossCalls(): void
    {
        $dir1 = $this->makeTempDir();
        $dir2 = $this->makeTempDir();
        mkdir($dir1.'/skill-a', 0777, true);
        file_put_contents($dir1.'/skill-a/SKILL.md', "---\nname: skill-a\ndescription: First skill.\n---\nbody");
        mkdir($dir2.'/skill-b', 0777, true);
        file_put_contents($dir2.'/skill-b/SKILL.md', "---\nname: skill-b\ndescription: Second skill.\n---\nbody");

        try {
            $builder = Server::builder()
                ->addSkillsFromDirectory($dir1)
                ->addSkillsFromDirectory($dir2);

            $registry = $this->readPrivate($builder, 'skillRegistry');
            $this->assertNotNull($registry->get('skill://skill-a/SKILL.md'));
            $this->assertNotNull($registry->get('skill://skill-b/SKILL.md'));

            $extensions = $this->readPrivate($builder, 'extensions');
            $this->assertCount(1, $extensions);
        } finally {
            $this->removeDir($dir1);
            $this->removeDir($dir2);
        }
    }

    public function testThrowsWhenSkillExceedsResourceCountLimit(): void
    {
        $dir = $this->makeTempDir();
        $skillDir = $dir.'/many/many';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: many\ndescription: Too many files.\n---\nbody");
        for ($i = 0; $i < 512; ++$i) {
            file_put_contents($skillDir.\sprintf('/file-%03d.txt', $i), 'x');
        }

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeding the 512');

            (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testThrowsWhenSkillExceedsTotalSizeLimit(): void
    {
        $dir = $this->makeTempDir();
        $skillDir = $dir.'/big/big';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: big\ndescription: Too big.\n---\nbody");

        $handle = fopen($skillDir.'/blob.bin', 'w');
        \assert(false !== $handle);
        fseek($handle, 17_000_000 - 1);
        fwrite($handle, "\0");
        fclose($handle);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeding the 16777216 bytes');

            (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);
        } finally {
            $this->removeDir($dir);
        }
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

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/mcp-skill-provider-test-'.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
