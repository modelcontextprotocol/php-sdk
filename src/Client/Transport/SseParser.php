<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Mcp\Exception\InvalidArgumentException;

/**
 * Incremental parser for a text/event-stream body.
 *
 * Per the SSE specification, lines are terminated by CRLF, LF or CR, so an
 * event is delimited by any pair of those. Servers built on sse-starlette
 * (the MCP Python SDK) use CRLF.
 *
 * @phpstan-type SseEvent array{data: string, id: ?string, retry: ?int}
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SseParser
{
    private string $buffer = '';

    /**
     * @param int $maxBufferBytes Maximum bytes buffered while waiting for a complete event. A server
     *                            that never sends the event delimiter would otherwise grow the buffer
     *                            without bound and exhaust client memory.
     */
    public function __construct(
        public readonly int $maxBufferBytes,
    ) {
        if ($maxBufferBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum SSE buffer size must be a positive number of bytes, got %d.', $maxBufferBytes));
        }
    }

    /**
     * Buffer a chunk, or return false and leave the buffer untouched when the chunk would exceed the limit.
     */
    public function append(string $chunk): bool
    {
        if (\strlen($this->buffer) + \strlen($chunk) > $this->maxBufferBytes) {
            return false;
        }

        $this->buffer .= $chunk;

        return true;
    }

    public function bufferedBytes(): int
    {
        return \strlen($this->buffer);
    }

    /**
     * Take the next complete event off the buffer, or null if none is complete yet.
     *
     * @return SseEvent|null
     */
    public function next(): ?array
    {
        while (null !== ($event = $this->extract())) {
            if ('' !== trim($event)) {
                return self::parse($event);
            }
        }

        return null;
    }

    /**
     * Take what is left once the stream ended: an event the server did not
     * terminate with a blank line, or one that was cut off.
     *
     * @return SseEvent|null
     */
    public function rest(): ?array
    {
        $rest = $this->buffer;
        $this->buffer = '';

        return '' === trim($rest) ? null : self::parse($rest);
    }

    public function reset(): void
    {
        $this->buffer = '';
    }

    private function extract(): ?string
    {
        $position = null;
        $length = 0;

        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $delimiter) {
            $found = strpos($this->buffer, $delimiter);

            if (false !== $found && (null === $position || $found < $position)) {
                $position = $found;
                $length = \strlen($delimiter);
            }
        }

        if (null === $position) {
            return null;
        }

        $event = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + $length);

        return $event;
    }

    /**
     * @return SseEvent
     */
    private static function parse(string $event): array
    {
        $dataLines = [];
        $id = null;
        $retry = null;

        foreach (preg_split("/\r\n|\r|\n/", $event) ?: [] as $line) {
            if ('' === $line || str_starts_with($line, ':')) {
                continue;
            }

            $separator = strpos($line, ':');
            if (false === $separator) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $separator);
                $value = substr($line, $separator + 1);

                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            }

            if ('data' === $field) {
                $dataLines[] = $value;
            } elseif ('id' === $field && !str_contains($value, "\0")) {
                $id = $value;
            } elseif ('retry' === $field && null !== ($retryMs = self::retryMilliseconds($value))) {
                $retry = $retryMs;
            }
        }

        return ['data' => implode("\n", $dataLines), 'id' => $id, 'retry' => $retry];
    }

    private static function retryMilliseconds(string $value): ?int
    {
        if (1 !== preg_match('/^[0-9]+$/D', $value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ('' === $normalized) {
            return 0;
        }

        $maximum = (string) \PHP_INT_MAX;
        if (\strlen($normalized) > \strlen($maximum) || (\strlen($normalized) === \strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            return null;
        }

        return (int) $normalized;
    }
}
