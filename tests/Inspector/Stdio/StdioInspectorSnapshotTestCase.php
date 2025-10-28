<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector\Stdio;

use Mcp\Tests\Inspector\InspectorSnapshotTestCase;

abstract class StdioInspectorSnapshotTestCase extends InspectorSnapshotTestCase
{
    abstract protected function getServerScript(): string;

    protected function getServerConnectionArgs(): array
    {
        return ['php', $this->getServerScript()];
    }

    protected function getTransport(): string
    {
        return 'stdio';
    }

    protected function getSnapshotFilePath(string $method, ?string $testName = null): string
    {
        $className = substr(static::class, strrpos(static::class, '\\') + 1);
        $suffix = $testName ? '-'.preg_replace('/[^a-zA-Z0-9_]/', '_', $testName) : '';

        return __DIR__.'/snapshots/'.$className.'-'.str_replace('/', '_', $method).$suffix.'.json';
    }
}
