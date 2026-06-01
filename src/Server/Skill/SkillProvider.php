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
use Mcp\Schema\Extension\Skills\SkillDiscoveryEntry;
use Mcp\Schema\Extension\Skills\SkillDiscoveryIndex;
use Mcp\Schema\Extension\Skills\SkillMetadata;
use Mcp\Schema\Extension\Skills\SkillType;
use Mcp\Server\Builder;
use Symfony\Component\Finder\Finder;

/**
 * Exposes a directory of skills as `skill://` resources on a {@see Builder}.
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
    /**
     * @var array<string, true> used to keep generated resource names unique
     */
    private array $usedNames = [];

    public function __construct(
        private readonly FrontmatterParser $frontmatter = new FrontmatterParser(),
    ) {
    }

    /**
     * Walks $baseDirectory and registers every discovered skill (and its supporting files) as
     * `skill://` resources on $builder, optionally serving a `skill://index.json` discovery index.
     *
     * @return SkillDiscoveryEntry[] the discovered skills
     *
     * @throws InvalidArgumentException if the directory is missing, or a skill violates the spec
     */
    public function registerInto(Builder $builder, string $baseDirectory, bool $withDiscoveryIndex = true): array
    {
        $base = realpath($baseDirectory);
        if (false === $base || !is_dir($base)) {
            throw new InvalidArgumentException(\sprintf('Skills directory "%s" does not exist or is not a directory.', $baseDirectory));
        }

        $this->usedNames = [];
        $entries = [];

        foreach ($this->findSkillManifests($base) as $manifestPath) {
            $entries[] = $this->registerSkill($builder, $base, $manifestPath);
        }

        if ($withDiscoveryIndex) {
            // Return the serialized array (not the DTO) so ResourceResultFormatter JSON-encodes it.
            $index = (new SkillDiscoveryIndex($entries))->jsonSerialize();
            $builder->addResource(
                static fn (): array => $index,
                McpSkills::DISCOVERY_URI,
                name: 'skills-index',
                title: 'Skills discovery index',
                description: 'Agent Skills discovery index of all skills served by this server.',
                mimeType: 'application/json',
            );
        }

        return $entries;
    }

    private function registerSkill(Builder $builder, string $base, string $manifestPath): SkillDiscoveryEntry
    {
        $skillDir = \dirname($manifestPath);
        $skillPath = $this->relativePath($base, $skillDir);

        $metadata = $this->frontmatter->parseMetadata((string) file_get_contents($manifestPath));

        $lastSegment = basename($skillPath);
        if ($lastSegment !== $metadata->name) {
            throw new InvalidArgumentException(\sprintf('Skill at "%s": frontmatter name "%s" must match the final path segment "%s".', $skillPath, $metadata->name, $lastSegment));
        }

        // Register the SKILL.md entry point.
        $entryUri = \sprintf('%s://%s/%s', McpSkills::URI_SCHEME, $skillPath, McpSkills::ENTRY_POINT);
        $this->registerFile($builder, $base, $manifestPath, $entryUri, McpSkills::MIME_TYPE, $metadata);

        // Register all supporting files within the skill directory.
        foreach ($this->findSupportingFiles($skillDir, $manifestPath) as $filePath) {
            $relative = $this->relativePath($skillDir, $filePath);
            $uri = \sprintf('%s://%s/%s', McpSkills::URI_SCHEME, $skillPath, $relative);
            $this->registerFile($builder, $base, $filePath, $uri, $this->guessMimeType($filePath), null);
        }

        return new SkillDiscoveryEntry(
            name: $metadata->name,
            type: SkillType::SkillMd,
            url: $entryUri,
            description: $metadata->description,
        );
    }

    private function registerFile(Builder $builder, string $base, string $filePath, string $uri, string $mimeType, ?SkillMetadata $metadata): void
    {
        $absolute = realpath($filePath);
        if (false === $absolute || !str_starts_with($absolute, $base.\DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(\sprintf('Skill file "%s" resolves outside the skills directory.', $filePath));
        }

        $meta = null;
        $title = null;
        $description = null;
        if (null !== $metadata) {
            $title = $metadata->name;
            $description = $metadata->description;
            if ([] !== $metadata->extra) {
                $meta = [McpSkills::META_PREFIX => $metadata->extra];
            }
        }

        $builder->addResource(
            static fn (): \SplFileInfo => new \SplFileInfo($absolute),
            $uri,
            name: $this->uniqueResourceName($uri),
            title: $title,
            description: $description,
            mimeType: $mimeType,
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

    /**
     * Derives a unique, schema-valid resource name from a skill URI.
     *
     * {@see \Mcp\Schema\ResourceDefinition} restricts names to `[a-zA-Z0-9_-]+`, so slashes and dots
     * in the URI path are replaced; the real path is preserved in the URI itself.
     */
    private function uniqueResourceName(string $uri): string
    {
        $path = substr($uri, \strlen(McpSkills::URI_SCHEME.'://'));
        $name = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $path) ?? '';
        $name = trim($name, '-');
        if ('' === $name) {
            $name = 'skill';
        }

        $candidate = $name;
        $suffix = 1;
        while (isset($this->usedNames[$candidate])) {
            $candidate = $name.'-'.(++$suffix);
        }
        $this->usedNames[$candidate] = true;

        return $candidate;
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
