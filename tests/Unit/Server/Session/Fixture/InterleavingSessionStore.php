<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Server\Session\Fixture;

use Mcp\Server\Session\InMemorySessionStore;
use Symfony\Component\Uid\Uuid;

/**
 * A session store that runs another request in the middle of one reading or saving its session.
 *
 * Replays, in one process and in a fixed order, what two PHP workers serving the
 * same session do when their requests overlap.
 */
final class InterleavingSessionStore extends InMemorySessionStore
{
    private ?\Closure $afterNextRead = null;
    private ?\Closure $afterNextWrite = null;
    private bool $readBeforeWrite = false;
    private string|false|null $staleRead = null;

    /**
     * Runs $interleaved right after the next read, before the reader can write the session back.
     */
    public function interleaveAfterNextRead(\Closure $interleaved): void
    {
        $this->afterNextRead = $interleaved;
    }

    /**
     * Runs $interleaved right after the next write.
     *
     * With $readBeforeWrite, the interleaved request reads the session as it was
     * before that write, as if it had loaded it before the first request saved.
     */
    public function interleaveOnNextWrite(\Closure $interleaved, bool $readBeforeWrite = false): void
    {
        $this->afterNextWrite = $interleaved;
        $this->readBeforeWrite = $readBeforeWrite;
    }

    public function read(Uuid $id): string|false
    {
        if (null !== $data = $this->staleRead) {
            $this->staleRead = null;

            return $data;
        }

        $data = parent::read($id);

        if (null !== $interleaved = $this->afterNextRead) {
            $this->afterNextRead = null;
            $interleaved();
        }

        return $data;
    }

    public function write(Uuid $id, string $data): bool
    {
        $before = parent::read($id);
        $written = parent::write($id, $data);

        if (null !== $interleaved = $this->afterNextWrite) {
            $this->afterNextWrite = null;
            if ($this->readBeforeWrite) {
                $this->staleRead = $before;
            }

            $interleaved();
        }

        return $written;
    }
}
