<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Psr\Http\Message\ResponseInterface;

/**
 * Reads the answer of a server the client doesn't trust, so a huge body can't exhaust the memory.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ResponseBody
{
    public const MAX_BYTES = 1048576;

    /**
     * @return string|null the body, or null if it is too large or can't be read
     */
    public static function read(ResponseInterface $response): ?string
    {
        $stream = $response->getBody();
        $body = '';

        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }

            while (\strlen($body) <= self::MAX_BYTES && !$stream->eof()) {
                $chunk = $stream->read(min(8192, self::MAX_BYTES + 1 - \strlen($body)));

                if ('' === $chunk) {
                    break;
                }

                $body .= $chunk;
            }
        } catch (\RuntimeException) {
            return null;
        }

        return \strlen($body) > self::MAX_BYTES ? null : $body;
    }
}
