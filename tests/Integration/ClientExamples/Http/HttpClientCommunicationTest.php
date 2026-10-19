<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\ClientExamples\Http;

final class HttpClientCommunicationTest extends HttpClientExampleTestCase
{
    protected function getExampleScript(): string
    {
        return 'http_client_communication';
    }

    protected function getServerExample(): string
    {
        return 'client-communication';
    }

    protected function getServerWorkers(): int
    {
        // The server samples mid-call; see the parent.
        return 4;
    }
}
