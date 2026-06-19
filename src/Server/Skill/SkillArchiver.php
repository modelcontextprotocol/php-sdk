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
use Mcp\Exception\RuntimeException;

/**
 * Packs a skill directory into an archive whose content is the skill directory directly —
 * `SKILL.md` at the archive root, supporting files at their relative paths — per the SEP-2640
 * archive layout. Produced without temp files and with zeroed file metadata, so the same input
 * yields the same bytes (and therefore a stable digest).
 *
 * Currently emits gzip-compressed tar (`application/gzip`), which the SEP lists as one of the two
 * formats every conforming host is expected to support.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillArchiver
{
    /**
     * Supported archive MIME type => conventional file extension.
     */
    public const FORMATS = [
        'application/gzip' => 'tar.gz',
    ];

    /**
     * @param array<string, string> $files relative path (forward slashes) => raw file content
     *
     * @throws InvalidArgumentException if the format is unsupported or a path is too long
     * @throws RuntimeException         if compression fails
     */
    public function pack(array $files, string $mimeType): string
    {
        if (!isset(self::FORMATS[$mimeType])) {
            throw new InvalidArgumentException(\sprintf('Unsupported skill archive format "%s". Supported: %s.', $mimeType, implode(', ', array_keys(self::FORMATS))));
        }

        $gzip = gzencode($this->tar($files), 9);
        if (false === $gzip) {
            throw new RuntimeException('Failed to gzip skill archive.');
        }

        return $gzip;
    }

    /**
     * @throws InvalidArgumentException if the format is unsupported
     */
    public function extension(string $mimeType): string
    {
        if (!isset(self::FORMATS[$mimeType])) {
            throw new InvalidArgumentException(\sprintf('Unsupported skill archive format "%s". Supported: %s.', $mimeType, implode(', ', array_keys(self::FORMATS))));
        }

        return self::FORMATS[$mimeType];
    }

    /**
     * Builds a deterministic ustar archive (sorted entries, zeroed mtime/owner).
     *
     * @param array<string, string> $files
     */
    private function tar(array $files): string
    {
        ksort($files);

        $tar = '';
        foreach ($files as $path => $content) {
            $tar .= $this->header($path, \strlen($content));
            $tar .= $content;
            $remainder = \strlen($content) % 512;
            if (0 !== $remainder) {
                $tar .= str_repeat("\0", 512 - $remainder);
            }
        }

        // Two zero-filled blocks mark the end of the archive.
        return $tar.str_repeat("\0", 1024);
    }

    /**
     * Builds a 512-byte ustar header with a valid checksum.
     */
    private function header(string $name, int $size): string
    {
        if (\strlen($name) > 100) {
            throw new InvalidArgumentException(\sprintf('Skill file path "%s" is too long to archive (max 100 bytes).', $name));
        }

        $header = str_pad($name, 100, "\0");            // name
        $header .= '0000644'."\0";                       // mode
        $header .= '0000000'."\0";                       // uid
        $header .= '0000000'."\0";                       // gid
        $header .= \sprintf('%011o', $size).' ';         // size
        $header .= '00000000000 ';                    // mtime (zeroed for determinism)
        $header .= '        ';                            // checksum placeholder (8 spaces)
        $header .= '0';                                  // typeflag: regular file
        $header .= str_repeat("\0", 100);                // linkname
        $header .= "ustar\0";                            // magic
        $header .= '00';                                 // version
        $header .= str_repeat("\0", 32);                 // uname
        $header .= str_repeat("\0", 32);                 // gname
        $header .= str_repeat("\0", 8);                  // devmajor
        $header .= str_repeat("\0", 8);                  // devminor
        $header .= str_repeat("\0", 155);                // prefix
        $header .= str_repeat("\0", 12);                 // pad to 512

        $checksum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $checksum += \ord($header[$i]);
        }

        return substr_replace($header, \sprintf('%06o', $checksum)."\0 ", 148, 8);
    }
}
