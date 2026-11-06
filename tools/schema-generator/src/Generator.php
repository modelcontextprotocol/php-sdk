<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tools\SchemaGenerator;

use Mcp\Exception\RuntimeException;

/**
 * @phpstan-import-type GeneratorConfig from ModelBuilder
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Generator
{
    /**
     * @param GeneratorConfig $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public static function fromConfigFile(string $path): self
    {
        /** @var GeneratorConfig $config */
        $config = require $path;

        return new self($config);
    }

    /**
     * @return array<string, string> source keyed by path relative to the output directory
     */
    public function generate(): array
    {
        $definitions = [];
        foreach ($this->config['revisions'] as $revision) {
            $schema = json_decode($this->read($revision.'/schema.json'), true, flags: \JSON_THROW_ON_ERROR);
            $definitions[$revision] = $schema['$defs'] ?? $schema['definitions'] ?? [];
        }

        $latest = $this->config['revisions'][\count($this->config['revisions']) - 1];
        $deprecations = (new DeprecationReader())->read($this->read($latest.'/schema.ts'));

        $types = (new ModelBuilder($this->config, $definitions, $deprecations))->build();

        return (new PhpEmitter($types, $this->config['namespace'], $this->config['revisions'], $this->config['discriminator_property']))->emit();
    }

    /**
     * Replaces the previously generated files in $directory with $files, leaving hand-written ones alone.
     *
     * @param array<string, string> $files
     */
    public function write(string $directory, array $files): void
    {
        foreach ($this->generatedFiles($directory) as $path) {
            unlink($directory.'/'.$path);
        }

        foreach ($files as $path => $code) {
            $target = $directory.'/'.$path;
            if (!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0777, true)) {
                throw new RuntimeException(\sprintf('Cannot create directory for "%s".', $target));
            }
            file_put_contents($target, $code);
        }
    }

    /**
     * @return list<string> paths, relative to $directory, of files carrying the generated marker
     */
    public function generatedFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()
                && str_contains((string) file_get_contents($file->getPathname()), PhpEmitter::MARKER)) {
                $paths[] = substr($file->getPathname(), \strlen($directory) + 1);
            }
        }
        sort($paths);

        return $paths;
    }

    public function outputDirectory(): string
    {
        return $this->config['directory'];
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($this->config['spec_dir'].'/'.$path);
        if (false === $contents) {
            throw new RuntimeException(\sprintf('Cannot read "%s" from the vendored specification.', $path));
        }

        return $contents;
    }
}
