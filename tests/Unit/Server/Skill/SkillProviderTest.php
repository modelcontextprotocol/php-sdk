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
use PHPUnit\Framework\Attributes\DataProvider;
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

        $codeReview = array_values(array_filter($skills, static fn ($s) => 'code-review' === $s->frontmatter->name))[0];
        $this->assertIsArray($codeReview->resources);
        $this->assertCount(2, $codeReview->resources); // SKILL.md + references/SECURITY.md

        $manifestEntry = array_values(array_filter($codeReview->resources, static fn (SkillResource $r) => $r->uri === $codeReview->uri))[0];
        $this->assertNotFalse($manifestEntry);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $manifestEntry->digest);
        $this->assertGreaterThan(0, $manifestEntry->size);
    }

    public function testDigestMatchesServedManifestBytes(): void
    {
        $builder = Server::builder();

        $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), self::FIXTURES);
        $codeReview = array_values(array_filter($skills, static fn ($s) => 'code-review' === $s->frontmatter->name))[0];
        $this->assertIsArray($codeReview->resources);

        $resource = $this->resourceByUri($builder, $codeReview->uri);
        /** @var \SplFileInfo $file */
        $file = ($resource['handler'])();
        $served = (string) file_get_contents($file->getPathname());

        $manifestEntry = array_values(array_filter($codeReview->resources, static fn (SkillResource $r) => $r->uri === $codeReview->uri))[0];
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

        $parent = array_values(array_filter($skills, static fn ($s) => 'parent-skill' === $s->frontmatter->name))[0];
        $nested = array_values(array_filter($skills, static fn ($s) => 'nested-skill' === $s->frontmatter->name))[0];
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
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNames(): iterable
    {
        yield 'uppercase' => ['Code-Review'];
        yield 'underscore' => ['code_review'];
        yield 'leading hyphen' => ['-review'];
        yield 'trailing hyphen' => ['review-'];
        yield 'double hyphen' => ['code--review'];
        yield 'too long' => [str_repeat('a', 65)];
    }

    #[DataProvider('provideInvalidNames')]
    public function testRejectsNamesBreakingTheAgentSkillsNamingRules(string $name): void
    {
        $dir = $this->makeTempDir();
        $this->writeSkill($dir, $name, "name: {$name}\ndescription: Some skill.");

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must be 1-64 characters');

            (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAcceptsTheLongestValidName(): void
    {
        $dir = $this->makeTempDir();
        $name = str_repeat('a', 64);
        $this->writeSkill($dir, $name, "name: {$name}\ndescription: Some skill.");

        try {
            $skills = (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);

            $this->assertCount(1, $skills);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRejectsDescriptionsLongerThan1024Characters(): void
    {
        $dir = $this->makeTempDir();
        $this->writeSkill($dir, 'long-description', 'name: long-description'."\n".'description: '.str_repeat('x', 1025));

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('exceeding the limit of 1024');

            (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testAcceptsA1024CharacterDescription(): void
    {
        $dir = $this->makeTempDir();
        $this->writeSkill($dir, 'max-description', "name: max-description\ndescription: ".str_repeat('x', 1024));

        try {
            $skills = (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);

            $this->assertCount(1, $skills);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testSkipsHiddenFilesAndFoldersWhateverIsInstalled(): void
    {
        $dir = $this->makeTempDir();
        $skillDir = $this->writeSkill($dir, 'visible', "name: visible\ndescription: Has hidden files.");
        file_put_contents($skillDir.'/guide.md', 'guide');
        file_put_contents($skillDir.'/.env', 'SECRET=1');
        mkdir($skillDir.'/.git', 0777, true);
        file_put_contents($skillDir.'/.git/config', 'repo');
        mkdir($skillDir.'/refs/.cache', 0777, true);
        file_put_contents($skillDir.'/refs/.cache/blob', 'cache');
        file_put_contents($skillDir.'/refs/note.md', 'note');

        try {
            $builder = Server::builder();
            $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), $dir);

            $this->assertIsArray($skills[0]->resources);
            $listed = array_map(static fn (SkillResource $r): string => $r->uri, $skills[0]->resources);
            sort($listed);

            $this->assertSame(['skill://visible/SKILL.md', 'skill://visible/guide.md', 'skill://visible/refs/note.md'], $listed);
            $served = array_column($this->registeredResources($builder), 'uri');
            sort($served);
            $this->assertSame($listed, $served, 'Everything served is listed, and the other way round.');
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testPercentEncodesUrisAndKeepsThemServable(): void
    {
        $dir = $this->makeTempDir();
        $skillDir = $this->writeSkill($dir, 'encoded', "name: encoded\ndescription: Odd file names.");
        mkdir($skillDir.'/my docs', 0777, true);
        file_put_contents($skillDir.'/my docs/a b.md', 'one');
        file_put_contents($skillDir.'/a#b.md', 'two');
        file_put_contents($skillDir.'/x%y.md', 'three');

        try {
            $builder = Server::builder();
            $skills = (new SkillProvider())->registerInto($builder, new SkillRegistry(), $dir);

            $this->assertIsArray($skills[0]->resources);
            $listed = array_map(static fn (SkillResource $r): string => $r->uri, $skills[0]->resources);

            $this->assertContains('skill://encoded/my%20docs/a%20b.md', $listed);
            $this->assertContains('skill://encoded/a%23b.md', $listed);
            $this->assertContains('skill://encoded/x%25y.md', $listed);
            foreach ($listed as $uri) {
                $this->assertDoesNotMatchRegularExpression('/[ #]/', $uri);
                $resource = $this->resourceByUri($builder, $uri);
                $file = ($resource['handler'])();
                $this->assertInstanceOf(\SplFileInfo::class, $file);
                $this->assertFileExists($file->getPathname());
            }
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testEncodesPrefixSegmentsToo(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/team a', 0777, true);
        $this->writeSkill($dir.'/team a', 'tool', "name: tool\ndescription: Prefixed skill.");

        try {
            $skills = (new SkillProvider())->registerInto(Server::builder(), new SkillRegistry(), $dir);

            $this->assertSame('skill://team%20a/tool/SKILL.md', $skills[0]->uri);
        } finally {
            $this->removeDir($dir);
        }
    }

    public function testRejectsASkillPathThatIsAlreadyRegistered(): void
    {
        $dir1 = $this->makeTempDir();
        $dir2 = $this->makeTempDir();
        $this->writeSkill($dir1, 'system-information', "name: system-information\ndescription: From the first package.");
        file_put_contents($dir1.'/system-information/only-first.md', 'first');
        $this->writeSkill($dir2, 'system-information', "name: system-information\ndescription: From the second package.");

        try {
            $builder = Server::builder();
            $registry = new SkillRegistry();
            $provider = new SkillProvider();
            $provider->registerInto($builder, $registry, $dir1);
            $resourcesBefore = $this->registeredResources($builder);

            try {
                $provider->registerInto($builder, $registry, $dir2);
                $this->fail('A second skill at the same path must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('skill://system-information/SKILL.md', $e->getMessage());
                $this->assertStringContainsString($dir2, $e->getMessage());
            }

            $this->assertSame($resourcesBefore, $this->registeredResources($builder), 'The rejected skill registers nothing.');
            $skill = $registry->get('skill://system-information/SKILL.md');
            $this->assertNotNull($skill);
            $this->assertSame('From the first package.', $skill->frontmatter->description);
        } finally {
            $this->removeDir($dir1);
            $this->removeDir($dir2);
        }
    }

    public function testRejectsAFileThatLandsOnTheUriOfAnotherSkillsFile(): void
    {
        $dir1 = $this->makeTempDir();
        $dir2 = $this->makeTempDir();
        $first = $this->writeSkill($dir1, 'acme', "name: acme\ndescription: The outer skill.");
        mkdir($first.'/billing/refunds', 0777, true);
        file_put_contents($first.'/billing/refunds/x.md', 'abcd');
        mkdir($dir2.'/acme/billing', 0777, true);
        $second = $this->writeSkill($dir2.'/acme/billing', 'refunds', "name: refunds\ndescription: A skill at the path of the file above.");
        file_put_contents($second.'/x.md', 'abcdefg');

        try {
            $builder = Server::builder();
            $registry = new SkillRegistry();
            $provider = new SkillProvider();
            $provider->registerInto($builder, $registry, $dir1);

            try {
                $provider->registerInto($builder, $registry, $dir2);
                $this->fail('A file served at the URI of another skill\'s file must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('skill://acme/billing/refunds/x.md', $e->getMessage());
            }

            $this->assertNull($registry->get('skill://acme/billing/refunds/SKILL.md'));
        } finally {
            $this->removeDir($dir1);
            $this->removeDir($dir2);
        }
    }

    public function testBuilderRejectsTheSameDirectoryTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('collides with an already registered skill');

        Server::builder()
            ->addSkillsFromDirectory(self::FIXTURES)
            ->addSkillsFromDirectory(self::FIXTURES);
    }

    private function writeSkill(string $baseDir, string $name, string $frontmatter): string
    {
        $skillDir = $baseDir.'/'.$name;
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\n{$frontmatter}\n---\nbody");

        return $skillDir;
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
