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

final class HttpStatelessLifecycleTest extends HttpClientExampleTestCase
{
    protected function getExampleScript(): string
    {
        return 'stateless_lifecycle_client';
    }

    protected function getServerExample(): string
    {
        return 'stateless-lifecycle';
    }
}
