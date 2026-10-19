<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Transport;

use Mcp\Exception\InvalidArgumentException;

/**
 * How HttpTransport resumes an SSE stream that closed before its response arrived.
 *
 * A server-provided SSE `retry` value sets the delay. Without one, the delay
 * starts at the initial delay and doubles after each failed attempt, up to the
 * maximum delay.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ReconnectPolicy
{
    /**
     * @param int $maxAttempts    Consecutive failed GET attempts before the request fails. Zero disables resumption.
     * @param int $initialDelayMs Delay before the first attempt when the server sent no `retry` field
     * @param int $maxDelayMs     Cap for the client-selected delay. A server-provided `retry` value is not capped.
     */
    public function __construct(
        public readonly int $maxAttempts = 5,
        public readonly int $initialDelayMs = 1000,
        public readonly int $maxDelayMs = 10000,
    ) {
        if ($maxAttempts < 0) {
            throw new InvalidArgumentException(\sprintf('The maximum number of SSE reconnect attempts must be zero or greater, got %d.', $maxAttempts));
        }

        if ($initialDelayMs < 0) {
            throw new InvalidArgumentException(\sprintf('The initial SSE reconnect delay must be zero or greater, got %d milliseconds.', $initialDelayMs));
        }

        if ($maxDelayMs < $initialDelayMs) {
            throw new InvalidArgumentException(\sprintf('The maximum SSE reconnect delay must be at least the initial delay of %d milliseconds, got %d.', $initialDelayMs, $maxDelayMs));
        }
    }

    /**
     * Delay before the next attempt after the given number of consecutive failed ones.
     */
    public function delayMs(int $failedAttempts, ?int $serverRetryMs = null): int
    {
        if (null !== $serverRetryMs) {
            return $serverRetryMs;
        }

        $delay = $this->initialDelayMs;

        for ($attempt = 0; $attempt < $failedAttempts && $delay < $this->maxDelayMs; ++$attempt) {
            $delay = $delay > intdiv($this->maxDelayMs, 2) ? $this->maxDelayMs : $delay * 2;
        }

        return $delay;
    }
}
