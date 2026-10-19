<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\ClientExamples\Stdio;

final class StdioDiscoveryCalculatorTest extends StdioClientExampleTestCase
{
    protected function getExampleScript(): string
    {
        return 'stdio_discovery_calculator';
    }
}
