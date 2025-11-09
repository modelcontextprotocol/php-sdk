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

use Mcp\Schema\Enum\LoggingLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

abstract class InspectorSnapshotTestCase extends TestCase
{
    private const INSPECTOR_VERSION = '0.17.2';

    /** @param array<string, mixed> $options */
    #[DataProvider('provideMethods')]
    public function testOutputMatchesSnapshot(
        string $method,
        array $options = [],
        ?string $testName = null,
    ): void {
        $inspector = \sprintf('@modelcontextprotocol/inspector@%s', self::INSPECTOR_VERSION);

        $args = [
            'npx',
            $inspector,
            '--cli',
            ...$this->getServerConnectionArgs(),
            '--transport',
            $this->getTransport(),
            '--method',
            $method,
        ];

        // Options for tools/call
        if (isset($options['toolName'])) {
            $args[] = '--tool-name';
            $args[] = $options['toolName'];

            foreach ($options['toolArgs'] ?? [] as $key => $value) {
                $args[] = '--tool-arg';
                if (\is_array($value)) {
                    $args[] = \sprintf('%s=%s', $key, json_encode($value));
                } elseif (\is_bool($value)) {
                    $args[] = \sprintf('%s=%s', $key, $value ? '1' : '0');
                } else {
                    $args[] = \sprintf('%s=%s', $key, $value);
                }
            }
        }

        // Options for resources/read
        if (isset($options['uri'])) {
            $args[] = '--uri';
            $args[] = $options['uri'];
        }

        // Options for prompts/get
        if (isset($options['promptName'])) {
            $args[] = '--prompt-name';
            $args[] = $options['promptName'];

            foreach ($options['promptArgs'] ?? [] as $key => $value) {
                $args[] = '--prompt-args';
                if (\is_array($value)) {
                    $args[] = \sprintf('%s=%s', $key, json_encode($value));
                } elseif (\is_bool($value)) {
                    $args[] = \sprintf('%s=%s', $key, $value ? '1' : '0');
                } else {
                    $args[] = \sprintf('%s=%s', $key, $value);
                }
            }
        }

        // Options for logging/setLevel
        if (isset($options['logLevel'])) {
            $args[] = '--log-level';
            $args[] = $options['logLevel'] instanceof LoggingLevel ? $options['logLevel']->value : $options['logLevel'];
        }

        // Options for env variables
        if (isset($options['envVars'])) {
            foreach ($options['envVars'] as $key => $value) {
                $args[] = '-e';
                $args[] = \sprintf('%s=%s', $key, $value);
            }
        }

        $output = (new Process(command: $args))
            ->mustRun()
            ->getOutput();

        $snapshotFile = $this->getSnapshotFilePath($method, $testName);

        $normalizedOutput = $this->normalizeTestOutput($output, $testName);

        if (!file_exists($snapshotFile)) {
            file_put_contents($snapshotFile, $normalizedOutput.\PHP_EOL);
            $this->markTestIncomplete("Snapshot created at $snapshotFile, please re-run tests.");
        }

        $expected = file_get_contents($snapshotFile);

        $message = \sprintf('Output does not match snapshot "%s".', $snapshotFile);
        $this->assertJsonStringEqualsJsonString($expected, $normalizedOutput, $message);
    }

    protected function normalizeTestOutput(string $output, ?string $testName = null): string
    {
        return $output;
    }

    /** @return array<string, array<string, mixed>> */
    public static function provideMethods(): array
    {
        return [
            'Prompt Listing' => ['method' => 'prompts/list'],
            'Resource Listing' => ['method' => 'resources/list'],
            'Resource Template Listing' => ['method' => 'resources/templates/list'],
            'Tool Listing' => ['method' => 'tools/list'],
        ];
    }

    abstract protected function getSnapshotFilePath(string $method, ?string $testName = null): string;

    /** @return array<string> */
    abstract protected function getServerConnectionArgs(): array;

    abstract protected function getTransport(): string;
}
