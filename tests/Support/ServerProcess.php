<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Helpers for tests that run a server as a separate process.
 */
final class ServerProcess
{
    /**
     * A port nothing listens on, so a server left over from an earlier run can
     * never answer in place of the one a test starts.
     */
    public static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0') ?: throw new \RuntimeException('Could not open a socket to find a free port.');
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * Stop a process and everything it started.
     *
     * Stopping the process alone is not enough: `php -S` on some PHP versions
     * leaves the workers it forked running, and `npx` leaves the server it ran
     * through `sh -c`. Both are reparented and keep their port. The tree is
     * collected first, as a child can no longer be found by its parent once
     * the parent is gone.
     */
    public static function stop(?Process $process): void
    {
        if (null === $process) {
            return;
        }

        $pid = $process->getPid();
        $descendants = null === $pid ? [] : self::descendants($pid);

        if ([] !== $descendants) {
            (new Process(['kill', '-TERM', ...array_map('strval', $descendants)]))->run();
        }

        $process->stop(1);
    }

    /**
     * @return list<int>
     */
    private static function descendants(int $pid): array
    {
        $pgrep = new Process(['pgrep', '-P', (string) $pid]);
        $pgrep->run();

        $descendants = [];
        foreach (preg_split('/\s+/', trim($pgrep->getOutput())) ?: [] as $child) {
            if (ctype_digit($child)) {
                $descendants[] = (int) $child;
                array_push($descendants, ...self::descendants((int) $child));
            }
        }

        return $descendants;
    }
}
