<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Interop\Client;

use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;

/**
 * The transport listening on the standalone GET stream, on which the server
 * asks for roots outside of any request. Responses are then read without
 * blocking, so every scenario runs on this path too.
 */
final class HttpListeningEverythingServerTest extends HttpEverythingServerTestCase
{
    protected static function transport(): TransportInterface
    {
        return new HttpTransport(self::$endpoint, listen: true);
    }
}
