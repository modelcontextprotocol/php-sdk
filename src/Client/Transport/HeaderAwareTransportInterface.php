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

/**
 * A transport whose messages carry headers the protocol gets to fill in.
 *
 * From 2026-07-28 an HTTP client mirrors parts of the body into request headers
 * so intermediaries can route without parsing it (SEP-2243), and declares the
 * revision it is speaking on every POST (SEP-2575). Deriving those needs the
 * message, which the protocol has and the transport only sees encoded — hence a
 * callback rather than a setter: the transport asks about the exact payload it
 * is about to write, so the two can never drift apart.
 *
 * Separate from {@see TransportInterface} because it is meaningless over stdio,
 * and because an existing transport must keep working without it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
interface HeaderAwareTransportInterface extends TransportInterface
{
    /**
     * Register the source of per-message headers.
     *
     * @param callable(string $payload): array<string, string> $callback receives the encoded message
     */
    public function onHeaders(callable $callback): void;
}
