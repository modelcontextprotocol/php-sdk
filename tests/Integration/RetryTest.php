<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Integration;

use Mcp\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Connection retries, counted in processes rather than calls.
 *
 * A retry is only worth anything if it starts a fresh server, which is what the
 * spawn counter pins down: the fixture bumps it on every start, so the test can
 * tell a genuine second process from a second call into the same one.
 *
 * A failed attempt costs the init timeout, so these are the slowest tests here.
 *
 * @see Fixture/retry.php for the server under test
 */
final class RetryTest extends IntegrationTestCase
{
    private string $counter;

    protected function setUp(): void
    {
        $counter = tempnam(sys_get_temp_dir(), 'mcp-spawns');

        if (false === $counter) {
            $this->fail('Could not create the spawn counter file.');
        }

        $this->counter = $counter;
        file_put_contents($this->counter, '0');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        @unlink($this->counter);
    }

    #[TestDox('a failed attempt is retried against a newly spawned server')]
    public function testRetrySpawnsAnotherServer(): void
    {
        $client = $this->connect(
            'retry',
            $this->clientBuilder()->setInitTimeout(2)->setMaxRetries(1),
            $this->environment(failing: 1),
        );

        $this->assertSame(2, $this->spawns());
        $this->assertTrue($client->isConnected());
        $this->assertSame('quick', $client->callTool('fast')->content[0]->text ?? null);
    }

    #[TestDox('no retries means the first failure is the last word')]
    public function testRetriesCanBeDisabled(): void
    {
        $client = $this->clientBuilder()->setInitTimeout(2)->setMaxRetries(0)->build();

        try {
            $client->connect($this->transport('retry', $this->environment(failing: 1)));
            $this->fail('Connecting should not have succeeded.');
        } catch (ConnectionException) {
            $this->assertSame(1, $this->spawns());
        }
    }

    /**
     * @return array<string, string>
     */
    private function environment(int $failing): array
    {
        return [
            'MCP_SPAWN_COUNTER' => $this->counter,
            'MCP_FAIL_SPAWNS' => (string) $failing,
        ];
    }

    private function spawns(): int
    {
        return (int) file_get_contents($this->counter);
    }
}
