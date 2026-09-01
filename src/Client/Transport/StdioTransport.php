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

use Mcp\Client\Exception\ConnectionException;
use Mcp\Client\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Psr\Log\LoggerInterface;

/**
 * Client transport that spawns a child process and communicates via stdio.
 *
 * This transport handles all blocking operations:
 * - Spawning the server process
 * - Reading from stdout in a polling loop
 * - Writing to stdin
 * - Managing Fibers waiting for responses
 *
 * @phpstan-import-type McpFiber from TransportInterface
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StdioTransport extends BaseTransport
{
    /** @var resource|null */
    private $process;

    /** @var resource|null */
    private $stdin;

    /** @var resource|null */
    private $stdout;

    /** @var resource|null */
    private $stderr;

    private string $inputBuffer = '';

    /**
     * Default cap on the bytes buffered while waiting for a complete line.
     */
    public const DEFAULT_MAX_BUFFER_SIZE = 4 * 1024 * 1024;

    /** @var McpFiber|null */
    private ?\Fiber $activeFiber = null;

    /** @var (callable(float, ?float, ?string): void)|null */
    private $activeProgressCallback;

    /**
     * @param string                     $command       The command to run
     * @param array<int, string>         $args          Command arguments
     * @param string|null                $cwd           Working directory
     * @param array<string, string>|null $env           Environment variables
     * @param int                        $maxBufferSize Maximum bytes buffered while waiting for a complete line. The
     *                                                  buffer is only drained on a "\n"; a spawned server that streams
     *                                                  stdout without ever emitting a newline would otherwise grow it
     *                                                  without bound and exhaust client memory. Reaching the cap aborts
     *                                                  the read instead. Raise it for servers that emit single frames
     *                                                  larger than the default.
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly ?string $cwd = null,
        private readonly ?array $env = null,
        ?LoggerInterface $logger = null,
        private readonly int $maxBufferSize = self::DEFAULT_MAX_BUFFER_SIZE,
    ) {
        parent::__construct($logger);

        if ($maxBufferSize < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum buffer size must be a positive number of bytes, got %d.', $maxBufferSize));
        }
    }

    public function connect(): void
    {
        $this->spawnProcess();

        $this->activeFiber = new \Fiber(fn () => $this->handleInitialize());

        $this->activeFiber->start();

        while (!$this->activeFiber->isTerminated()) {
            $this->tick();
        }

        $result = $this->activeFiber->getReturn();
        $this->activeFiber = null;

        if ($result instanceof Error) {
            $this->close();
            throw new ConnectionException('Initialization failed: '.$result->message);
        }

        $this->logger->info('Client connected and initialized');
    }

    public function send(string $data): void
    {
        if (null === $this->stdin || !\is_resource($this->stdin)) {
            throw new ConnectionException('Process stdin not available');
        }

        fwrite($this->stdin, $data."\n");
        fflush($this->stdin);

        $this->logger->debug('Sent message to server', ['data' => $data]);
    }

    /**
     * @param McpFiber                                                                $fiber
     * @param (callable(float $progress, ?float $total, ?string $message): void)|null $onProgress
     */
    public function runRequest(\Fiber $fiber, ?callable $onProgress = null): Response|Error
    {
        $this->activeFiber = $fiber;
        $this->activeProgressCallback = $onProgress;
        $fiber->start();

        while (!$fiber->isTerminated()) {
            $this->tick();
        }

        $this->activeFiber = null;
        $this->activeProgressCallback = null;

        return $fiber->getReturn();
    }

    public function close(): void
    {
        if (\is_resource($this->stdin)) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if (\is_resource($this->stdout)) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if (\is_resource($this->stderr)) {
            fclose($this->stderr);
            $this->stderr = null;
        }
        if (\is_resource($this->process)) {
            proc_terminate($this->process, 15); // SIGTERM
            proc_close($this->process);
            $this->process = null;
        }

        $this->handleClose('Transport closed');
    }

    private function spawnProcess(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $cmd = escapeshellcmd($this->command);
        foreach ($this->args as $arg) {
            $cmd .= ' '.escapeshellarg($arg);
        }

        $this->process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->cwd,
            $this->env
        );

        if (!\is_resource($this->process)) {
            throw new ConnectionException('Failed to start process: '.$cmd);
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set non-blocking mode for reading
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->logger->info('Started MCP server process', ['command' => $cmd]);
    }

    private function tick(): void
    {
        $this->processInput();
        $this->processProgress();
        $this->processFiber();
        $this->processStderr();

        usleep(1000); // 1ms
    }

    /**
     * Process pending progress updates from session and execute callback.
     */
    private function processProgress(): void
    {
        if (null === $this->activeProgressCallback || null === $this->state) {
            return;
        }

        $updates = $this->state->consumeProgressUpdates();

        foreach ($updates as $update) {
            try {
                ($this->activeProgressCallback)(
                    $update['progress'],
                    $update['total'],
                    $update['message'],
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Progress callback failed', ['exception' => $e]);
            }
        }
    }

    private function processInput(): void
    {
        if (null === $this->stdout || !\is_resource($this->stdout)) {
            return;
        }

        $data = fread($this->stdout, 8192);
        if (false !== $data && '' !== $data) {
            if (\strlen($this->inputBuffer) + \strlen($data) > $this->maxBufferSize) {
                $this->abortInput(\sprintf('buffered %d bytes without a newline, exceeding the %d byte limit', \strlen($this->inputBuffer) + \strlen($data), $this->maxBufferSize));

                return;
            }

            $this->inputBuffer .= $data;
        }

        while (false !== ($pos = strpos($this->inputBuffer, "\n"))) {
            $line = substr($this->inputBuffer, 0, $pos);
            $this->inputBuffer = substr($this->inputBuffer, $pos + 1);

            $trimmed = trim($line);
            if (!empty($trimmed)) {
                $this->handleMessage($trimmed);
            }
        }
    }

    /**
     * Discard the input buffer and fail any in-flight request.
     *
     * The waiting fiber is resolved with an error immediately so the caller
     * fails fast, rather than spinning until the request timeout elapses.
     */
    private function abortInput(string $reason): void
    {
        $bufferedBytes = \strlen($this->inputBuffer);
        $this->inputBuffer = '';

        $this->logger->warning('Aborting stdio input: '.$reason, [
            'buffered_bytes' => $bufferedBytes,
            'max_buffer_size' => $this->maxBufferSize,
        ]);

        if (null === $this->state) {
            return;
        }

        foreach ($this->state->getPendingRequests() as $pending) {
            $requestId = $pending['request_id'];
            $error = Error::forInternalError('stdio input aborted: '.$reason, $requestId);
            $this->state->storeResponse($requestId, $error->jsonSerialize());
        }
    }

    private function processFiber(): void
    {
        if (null === $this->activeFiber || !$this->activeFiber->isSuspended()) {
            return;
        }

        if (null === $this->state) {
            return;
        }

        $pendingRequests = $this->state->getPendingRequests();

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'];

            // Check if response arrived
            $response = $this->state->consumeResponse($requestId);

            if (null !== $response) {
                $this->logger->debug('Resuming fiber with response', ['request_id' => $requestId]);
                $this->activeFiber->resume($response);

                return;
            }

            // Check timeout
            if (time() - $timestamp >= $timeout) {
                $this->logger->warning('Request timed out', ['request_id' => $requestId]);
                $error = Error::forInternalError('Request timed out', $requestId);
                $this->activeFiber->resume($error);

                return;
            }
        }
    }

    private function processStderr(): void
    {
        if (null === $this->stderr || !\is_resource($this->stderr)) {
            return;
        }

        $stderr = fread($this->stderr, 8192);
        if (false !== $stderr && '' !== $stderr) {
            $this->logger->debug('Server stderr', ['output' => trim($stderr)]);
        }
    }
}
