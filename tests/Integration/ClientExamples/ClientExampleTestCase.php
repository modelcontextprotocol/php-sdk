<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration\ClientExamples;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs a script from `examples/client` and compares what it prints to a snapshot.
 *
 * Each example runs as its own process, exactly as the README tells users to run
 * it. Interactive examples get their "user input" piped to STDIN, one data set
 * per conversation.
 *
 * A missing snapshot is written on first run and the test marked incomplete;
 * delete a snapshot to re-record it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
abstract class ClientExampleTestCase extends TestCase
{
    private const TIMEOUT = 30;

    /** Basename of the script in `examples/client`, without `.php`. */
    abstract protected function getExampleScript(): string;

    /** Directory the snapshots of this transport live in. */
    abstract protected function getSnapshotDirectory(): string;

    /**
     * What the user types, one data set per run. Examples that ask nothing
     * run once, without input.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideInputs(): iterable
    {
        yield 'default' => [''];
    }

    #[DataProvider('provideInputs')]
    public function testOutputMatchesSnapshot(string $input): void
    {
        $process = new Process(
            [\PHP_BINARY, \dirname(__DIR__, 3).'/examples/client/'.$this->getExampleScript().'.php'],
            env: $this->getEnv(),
            input: $input,
            timeout: self::TIMEOUT,
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), \sprintf(
            "Example \"%s\" failed.\n\nSTDOUT:\n%s\nSTDERR:\n%s",
            $this->getExampleScript(),
            $process->getOutput(),
            $process->getErrorOutput(),
        ));

        $this->assertMatchesSnapshot($this->normalizeOutput($process->getOutput()));
    }

    /**
     * @return array<string, string> added to the example's environment
     */
    protected function getEnv(): array
    {
        return [];
    }

    protected function normalizeOutput(string $output): string
    {
        return $output;
    }

    private function assertMatchesSnapshot(string $output): void
    {
        $className = substr(static::class, strrpos(static::class, '\\') + 1);
        $suffix = 'default' === $this->dataName() ? '' : '-'.preg_replace('/\W+/', '_', (string) $this->dataName());
        $file = $this->getSnapshotDirectory().'/snapshots/'.$className.$suffix.'.txt';

        if (!file_exists($file)) {
            @mkdir(\dirname($file), 0777, true);
            file_put_contents($file, $output);
            $this->markTestIncomplete(\sprintf('Snapshot created at %s, please re-run tests.', $file));
        }

        $this->assertSame(file_get_contents($file), $output, \sprintf('Output does not match snapshot "%s".', $file));
    }
}
