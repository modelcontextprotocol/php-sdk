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

use Mcp\Tests\Integration\ClientExamples\ClientExampleTestCase;

/**
 * An example that spawns its server itself, over STDIO.
 */
abstract class StdioClientExampleTestCase extends ClientExampleTestCase
{
    protected function getSnapshotDirectory(): string
    {
        return __DIR__;
    }
}
