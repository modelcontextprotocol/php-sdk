<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;
use Psr\Log\LoggerInterface;

/**
 * @extends BaseTransport<int>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StdioTransport extends BaseTransport
{
    /**
     * Default cap on the bytes read for a single input line.
     */
    public const DEFAULT_MAX_LINE_BYTES = 4 * 1024 * 1024;

    /** Whether the current over-length line is still being drained and discarded. */
    private bool $discardingLine = false;

    /**
     * @param resource $input
     * @param resource $output
     * @param int      $maxLineBytes Maximum bytes read for a single input line. fgets() with no length reads until a
     *                               newline or EOF, so a peer that never sends a newline would buffer the whole stream
     *                               into one allocation and exhaust memory; a line exceeding this cap is discarded
     *                               instead.
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        ?LoggerInterface $logger = null,
        private readonly RunnerControlInterface $runnerControl = new RunnerControl(),
        private readonly int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
    ) {
        parent::__construct($logger);

        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum line size must be a positive number of bytes, got %d.', $maxLineBytes));
        }
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }

        $this->writeLine($data);
    }

    public function listen(): int
    {
        $this->logger->info('StdioTransport is listening for messages on STDIN...');
        stream_set_blocking($this->input, false);

        while (!feof($this->input) && RunnerState::RUNNING === $this->runnerControl->getState()) {
            $this->processInput();
            $this->processFiber();
            $this->flushOutgoingMessages();
        }

        $this->logger->info('StdioTransport finished listening.');
        if (\in_array($this->runnerControl->getState(), [RunnerState::RUNNING, RunnerState::STOP_AND_END_SESSION], true)) {
            $this->logger->info('StdioTransport end session.');
            $this->handleSessionEnd($this->sessionId);
        }

        return 0;
    }

    protected function processInput(): void
    {
        $line = fgets($this->input, $this->maxLineBytes);
        if (false === $line) {
            usleep(50000); // 50ms

            return;
        }

        $lineComplete = str_ends_with($line, "\n");

        // A previous over-length line is still being drained: keep discarding
        // one bounded chunk per tick until its terminating newline is reached,
        // so the run loop stays responsive instead of blocking on a drain loop.
        if ($this->discardingLine) {
            $this->discardingLine = !$lineComplete;

            return;
        }

        // fgets() reads at most maxLineBytes - 1 bytes; a full read with no
        // trailing newline means the line exceeds the cap. Discard it rather
        // than buffering it, and keep discarding the remainder on later ticks.
        if (!$lineComplete && \strlen($line) >= $this->maxLineBytes - 1) {
            $this->discardingLine = true;
            $this->logger->warning('StdioTransport discarded an input line exceeding the maximum length.', [
                'max_line_bytes' => $this->maxLineBytes,
            ]);

            return;
        }

        $trimmedLine = trim($line);
        if (!empty($trimmedLine)) {
            $this->handleMessage($trimmedLine, $this->sessionId);
        }
    }

    private function processFiber(): void
    {
        if (null === $this->sessionFiber) {
            return;
        }

        if ($this->sessionFiber->isTerminated()) {
            $this->handleFiberTermination();

            return;
        }

        if (!$this->sessionFiber->isSuspended()) {
            return;
        }

        $pendingRequests = $this->getPendingRequests($this->sessionId);

        if (empty($pendingRequests)) {
            $yielded = $this->sessionFiber->resume();
            $this->handleFiberYield($yielded, $this->sessionId);

            return;
        }

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'] ?? 120;

            $response = $this->checkForResponse($requestId, $this->sessionId);

            if (null !== $response) {
                $yielded = $this->sessionFiber->resume($response);
                $this->handleFiberYield($yielded, $this->sessionId);

                return;
            }

            if (time() - $timestamp >= $timeout) {
                $error = Error::forInternalError('Request timed out', $requestId);
                $yielded = $this->sessionFiber->resume($error);
                $this->handleFiberYield($yielded, $this->sessionId);

                return;
            }
        }
    }

    private function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber->getReturn();

        if (null !== $finalResult) {
            $finalResult = $this->handleFiberTerminationResult($finalResult);

            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                $this->writeLine($encoded);
            } catch (\JsonException $e) {
                $this->logger->error('STDIO: Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }

        $this->sessionFiber = null;
    }

    private function flushOutgoingMessages(): void
    {
        $messages = $this->getOutgoingMessages($this->sessionId);

        foreach ($messages as $message) {
            $this->writeLine($message['message']);
        }
    }

    private function writeLine(string $payload): void
    {
        fwrite($this->output, $payload.\PHP_EOL);
    }

    public function close(): void
    {
        $this->handleSessionEnd($this->sessionId);
        if (\is_resource($this->input)) {
            fclose($this->input);
        }
        if (\is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
