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

use Mcp\Client\Transport\StdioTransport;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Tests\Interop\NodeTool;

final class StdioEverythingServerTest extends EverythingServerTestCase
{
    protected static function transport(): TransportInterface
    {
        return new StdioTransport(NodeTool::path('mcp-server-everything'), ['stdio']);
    }
}
