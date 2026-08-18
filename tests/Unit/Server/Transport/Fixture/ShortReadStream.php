<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Transport\Fixture;

use Psr\Http\Message\StreamInterface;

/**
 * A non-seekable stream that hands back at most $chunkSize bytes per read.
 *
 * Models what PSR-7 actually promises — *up to* the requested length — which a
 * stream over `php://input` or a chunked transfer delivers routinely, and which
 * a single `read($cap)` therefore truncates.
 */
final class ShortReadStream implements StreamInterface
{
    private int $offset = 0;

    public function __construct(
        private readonly string $contents,
        private readonly int $chunkSize = 64,
        private readonly bool $advertiseSize = true,
    ) {
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->offset, min($length, $this->chunkSize));
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function eof(): bool
    {
        return $this->offset >= \strlen($this->contents);
    }

    public function getSize(): ?int
    {
        return $this->advertiseSize ? \strlen($this->contents) : null;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function __toString(): string
    {
        return $this->contents;
    }

    public function getContents(): string
    {
        return substr($this->contents, $this->offset);
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function tell(): int
    {
        return $this->offset;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null)
    {
        return null === $key ? [] : null;
    }
}
