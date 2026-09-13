<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Skill;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Extension\Skills\McpSkills;
use Mcp\Schema\Extension\Skills\Skill;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Schema\Extension\Skills\SkillResource;
use Mcp\Server\Builder;
use Symfony\Component\Finder\Finder;

/**
 * Exposes a directory of skills as `skill://` resources on a {@see Builder}, and registers each
 * skill's complete, digest-and-size manifest into a {@see SkillRegistry} so it can be served
 * through `skills/list` and `skills/get`.
 *
 * Each immediate or nested folder containing a `SKILL.md` is registered as a skill. The directory
 * path relative to the base directory becomes the skill path (its final segment must match the
 * `name` in the SKILL.md frontmatter), and every file within the folder is exposed as a resource:
 *
 *   skills/code-review/SKILL.md                  → skill://code-review/SKILL.md
 *   skills/code-review/references/SECURITY.md    → skill://code-review/references/SECURITY.md
 *   skills/acme/billing/refunds/SKILL.md         → skill://acme/billing/refunds/SKILL.md
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillProvider
{
    /** Resources per skill this extension fixes as the limit every conforming host must accept. */
    private const MAX_RESOURCES_PER_SKILL = 512;

    /** Total file size per skill this extension fixes as the limit every conforming host must accept. */
    private const MAX_TOTAL_SIZE_PER_SKILL = 16_777_216;

    public function __construct(
        private readonly FrontmatterParser $frontmatter = new FrontmatterParser(),
    ) {
    }

    /**
     * Walks $baseDirectory, registers every discovered skill (and its supporting files) as
     * `skill://` resources on $builder, and records each skill's manifest in $registry.
     *
     * @return Skill[] the discovered skills
     *
     * @throws InvalidArgumentException if the directory is missing, a skill violates the spec, or
     *                                  a skill exceeds the extension's per-skill resource/size limits
     */
    public function registerInto(Builder $builder, SkillRegistry $registry, string $baseDirectory): array
    {
        $base = realpath($baseDirectory);
        if (false === $base || !is_dir($base)) {
            throw new InvalidArgumentException(\sprintf('Skills directory "%s" does not exist or is not a directory.', $baseDirectory));
        }

        $skills = [];

        foreach ($this->findSkillManifests($base) as $manifestPath) {
            $skill = $this->registerSkill($builder, $base, $manifestPath);
            $registry->add($skill);
            $skills[] = $skill;
        }

        return $skills;
    }

    private function registerSkill(Builder $builder, string $base, string $manifestPath): Skill
    {
        $skillDir = \dirname($manifestPath);
        $skillPath = $this->relativePath($base, $skillDir);

        $content = (string) file_get_contents($manifestPath);
        $metadata = $this->frontmatter->parseMetadata($content);

        $lastSegment = basename($skillPath);
        if ($lastSegment !== $metadata->name) {
            throw new InvalidArgumentException(\sprintf('Skill at "%s": frontmatter name "%s" must match the final path segment "%s".', $skillPath, $metadata->name, $lastSegment));
        }

        $entryUri = \sprintf('%s://%s/%s', McpSkills::URI_SCHEME, $skillPath, McpSkills::ENTRY_POINT);
        $entrySize = \strlen($content);
        $entryDigest = 'sha256:'.hash('sha256', $content);

        $this->registerFile(
            $builder,
            $base,
            $manifestPath,
            $entryUri,
            name: $metadata->name,
            mimeType: McpSkills::MIME_TYPE,
            description: $metadata->description,
            size: $entrySize,
            meta: $this->metaFor($metadata),
        );

        $resources = [new SkillResource($entryUri, $entryDigest, $entrySize)];

        foreach ($this->findSupportingFiles($skillDir, $manifestPath) as $filePath) {
            $relative = $this->relativePath($skillDir, $filePath);
            $uri = \sprintf('%s://%s/%s', McpSkills::URI_SCHEME, $skillPath, $relative);
            $size = (int) filesize($filePath);
            $digest = 'sha256:'.hash_file('sha256', $filePath);

            $this->registerFile(
                $builder,
                $base,
                $filePath,
                $uri,
                name: basename($filePath),
                mimeType: $this->guessMimeType($filePath),
                description: null,
                size: $size,
                meta: null,
            );

            $resources[] = new SkillResource($uri, $digest, $size);
        }

        $this->checkLimits($skillPath, $resources);

        return new Skill($entryUri, $metadata, $resources);
    }

    /**
     * @param SkillResource[] $resources
     */
    private function checkLimits(string $skillPath, array $resources): void
    {
        if (\count($resources) > self::MAX_RESOURCES_PER_SKILL) {
            throw new InvalidArgumentException(\sprintf('Skill "%s" has %d resources, exceeding the %d this extension fixes as the per-skill limit.', $skillPath, \count($resources), self::MAX_RESOURCES_PER_SKILL));
        }

        $totalSize = array_sum(array_map(static fn (SkillResource $r): int => $r->size, $resources));
        if ($totalSize > self::MAX_TOTAL_SIZE_PER_SKILL) {
            throw new InvalidArgumentException(\sprintf('Skill "%s" totals %d bytes, exceeding the %d bytes this extension fixes as the per-skill limit.', $skillPath, $totalSize, self::MAX_TOTAL_SIZE_PER_SKILL));
        }
    }

    /**
     * @return array<string, mixed>|null the `_meta` map for the skill's SKILL.md resource, each
     *                                   extra frontmatter field under its own {@see McpSkills::META_PREFIX}-prefixed key
     */
    private function metaFor(SkillMetadata $metadata): ?array
    {
        if ([] === $metadata->extra) {
            return null;
        }

        $meta = [];
        foreach ($metadata->extra as $key => $value) {
            $meta[McpSkills::META_PREFIX.$key] = $value;
        }

        return $meta;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private function registerFile(Builder $builder, string $base, string $filePath, string $uri, string $name, string $mimeType, ?string $description, int $size, ?array $meta): void
    {
        $absolute = realpath($filePath);
        if (false === $absolute || !str_starts_with($absolute, $base.\DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(\sprintf('Skill file "%s" resolves outside the skills directory.', $filePath));
        }

        $builder->addResource(
            static fn (): \SplFileInfo => new \SplFileInfo($absolute),
            $uri,
            name: $name,
            description: $description,
            mimeType: $mimeType,
            size: $size,
            meta: $meta,
        );
    }

    /**
     * @return iterable<string> absolute paths to every SKILL.md under $base
     */
    private function findSkillManifests(string $base): iterable
    {
        if (class_exists(Finder::class)) {
            $finder = (new Finder())->files()->in($base)->name(McpSkills::ENTRY_POINT)->sortByName();
            foreach ($finder as $file) {
                yield $file->getPathname();
            }

            return;
        }

        yield from $this->iterateFiles($base, static fn (string $path): bool => McpSkills::ENTRY_POINT === basename($path));
    }

    /**
     * @return iterable<string> absolute paths to all files in $skillDir except the manifest
     */
    private function findSupportingFiles(string $skillDir, string $manifestPath): iterable
    {
        if (class_exists(Finder::class)) {
            $finder = (new Finder())->files()->in($skillDir)->sortByName();
            foreach ($finder as $file) {
                if ($file->getPathname() !== $manifestPath) {
                    yield $file->getPathname();
                }
            }

            return;
        }

        yield from $this->iterateFiles($skillDir, static fn (string $path): bool => $path !== $manifestPath);
    }

    /**
     * @param callable(string): bool $accept
     *
     * @return iterable<string>
     */
    private function iterateFiles(string $directory, callable $accept): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $accept($file->getPathname())) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        yield from $paths;
    }

    /**
     * Returns $path relative to $base, using forward slashes.
     */
    private function relativePath(string $base, string $path): string
    {
        $relative = ltrim(substr($path, \strlen($base)), \DIRECTORY_SEPARATOR);

        return str_replace(\DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function guessMimeType(string $path): string
    {
        $byExtension = [
            'md' => 'text/markdown',
            'markdown' => 'text/markdown',
            'json' => 'application/json',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'yaml' => 'application/yaml',
            'yml' => 'application/yaml',
        ];

        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if (isset($byExtension[$extension])) {
            return $byExtension[$extension];
        }

        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detected = $finfo->file($path);

        return \is_string($detected) && '' !== $detected ? $detected : 'application/octet-stream';
    }
}
