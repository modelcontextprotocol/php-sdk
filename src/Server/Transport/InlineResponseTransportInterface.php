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

/**
 * A transport that answers each request on the exchange that carried it.
 *
 * {@see \Mcp\Server\Protocol} hands such a transport its responses through
 * {@see TransportInterface::send()}, with the session in the `session_id`
 * context key, instead of queueing them in the session. The session is shared
 * by the concurrent requests of a client and written back whole, so a queued
 * response can be overwritten by another request, or taken by it.
 *
 * Server-initiated requests and notifications still go through the session queue.
 */
interface InlineResponseTransportInterface
{
}
