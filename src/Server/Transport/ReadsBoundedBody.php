<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Psr\Http\Message\StreamInterface;

/**
 * Reads a request body whole, bounded by a byte cap.
 *
 * Shared because a single `read($cap)` is not enough: PSR-7 promises only *up
 * to* the requested length, and a stream over `php://input` or a chunked
 * transfer routinely returns less — which truncates the payload into a parse
 * error the caller cannot act on.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait ReadsBoundedBody
{
    /**
     * Returns the body contents, or `null` when the payload exceeds $maxBytes.
     *
     * A stream advertising its size is rejected up front; otherwise the read is
     * incremental and stops at the cap, so an unbounded stream cannot exhaust
     * memory.
     */
    private function readBoundedBody(StreamInterface $body, int $maxBytes): ?string
    {
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $size = $body->getSize();
        if (null !== $size && $size > $maxBytes) {
            return null;
        }

        $contents = '';
        while (!$body->eof()) {
            $chunk = $body->read(8192);
            if ('' === $chunk) {
                break;
            }

            $contents .= $chunk;
            if (\strlen($contents) > $maxBytes) {
                return null;
            }
        }

        return $contents;
    }
}
