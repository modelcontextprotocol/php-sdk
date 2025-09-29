<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Inspector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class InspectorSnapshotTestCase extends TestCase
{
    #[DataProvider('provideMethods')]
    public function testResourcesListOutputMatchesSnapshot(string $method): void
    {
        $process = Process::fromShellCommandline(
            \sprintf('npx @modelcontextprotocol/inspector --cli php %s --method %s', $this->getServerScript(), $method)
        )->mustRun();

        $output = $process->getOutput();
        $snapshotFile = $this->getSnapshotFilePath($method);

        if (!file_exists($snapshotFile)) {
            file_put_contents($snapshotFile, $output.\PHP_EOL);
            $this->markTestIncomplete("Snapshot created at $snapshotFile, please re-run tests.");
        }

        $expected = file_get_contents($snapshotFile);

        $this->assertJsonStringEqualsJsonString($expected, $output);
    }

    /**
     * List of methods to test.
     *
     * @return array<string, array{method: string}>
     */
    abstract public static function provideMethods(): array;

    abstract protected function getServerScript(): string;

    /**
     * @return array<string, array{method: string}>
     */
    protected static function provideListMethods(): array
    {
        return [
            'Prompt Listing' => ['method' => 'prompts/list'],
            'Resource Listing' => ['method' => 'resources/list'],
            'Resource Template Listing' => ['method' => 'resources/templates/list'],
            'Tool Listing' => ['method' => 'tools/list'],
        ];
    }

    private function getSnapshotFilePath(string $method): string
    {
        $className = substr(static::class, strrpos(static::class, '\\') + 1);

        return __DIR__.'/snapshots/'.$className.'-'.str_replace('/', '_', $method).'.json';
    }
}
