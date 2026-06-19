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
use Mcp\Server\Skill\SkillArchiver;
use PHPUnit\Framework\TestCase;

class SkillArchiverTest extends TestCase
{
    public function testPackProducesReadableGzippedTar(): void
    {
        $files = $this->extract((new SkillArchiver())->pack([
            'SKILL.md' => "# Skill\n",
            'references/SECURITY.md' => "# Security\n",
        ], 'application/gzip'));

        $this->assertSame("# Skill\n", $files['SKILL.md'] ?? null);
        $this->assertSame("# Security\n", $files['references/SECURITY.md'] ?? null);
    }

    public function testSkillMdSitsAtArchiveRoot(): void
    {
        $files = $this->extract((new SkillArchiver())->pack(['SKILL.md' => 'x'], 'application/gzip'));

        $this->assertArrayHasKey('SKILL.md', $files);
    }

    public function testPackIsDeterministic(): void
    {
        $archiver = new SkillArchiver();
        $files = ['SKILL.md' => 'hello', 'a/b.txt' => 'world'];

        $this->assertSame(
            $archiver->pack($files, 'application/gzip'),
            $archiver->pack($files, 'application/gzip'),
        );
    }

    public function testExtensionForSupportedFormat(): void
    {
        $this->assertSame('tar.gz', (new SkillArchiver())->extension('application/gzip'));
    }

    public function testPackRejectsUnsupportedFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SkillArchiver())->pack(['SKILL.md' => 'x'], 'application/zip');
    }

    /**
     * @return array<string, string> relative path => content
     */
    private function extract(string $gzippedTar): array
    {
        $base = sys_get_temp_dir().'/skill-archiver-'.bin2hex(random_bytes(6));
        $archivePath = $base.'.tar.gz';
        $extractDir = $base.'.d';
        file_put_contents($archivePath, $gzippedTar);

        try {
            $phar = new \PharData($archivePath);
            $phar->extractTo($extractDir);
            unset($phar);

            $files = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($extractDir) + 1));
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }

            return $files;
        } finally {
            $this->removeDirectory($extractDir);
            @unlink($archivePath);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
