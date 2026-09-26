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

use Mcp\Exception\ConnectionException;
use Mcp\Exception\InvalidArgumentException;
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

    private string $stderrBuffer = '';

    private ?int $processExitCode = null;

    /**
     * Default cap on the bytes buffered while waiting for a complete line.
     */
    public const DEFAULT_MAX_BUFFER_SIZE = 4 * 1024 * 1024;

    /**
     * Bytes of the most recent stderr kept to explain a failed start.
     */
    public const MAX_STDERR_BUFFER_SIZE = 8 * 1024;

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
        // A transport may be respawned on a retry, so clear anything the
        // previous process left behind — otherwise a fresh child would inherit
        // the old one's exit code and stderr.
        $this->processExitCode = null;
        $this->stderrBuffer = '';
        $this->inputBuffer = '';

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

        // Drain everything currently available, not just one 8 KiB chunk, so a
        // response larger than the read size is fully read within the tick.
        // Otherwise a process that emits a big frame and exits could be seen as
        // dead by processFiber() before its response has finished arriving.
        // Complete frames are dispatched after each chunk so the buffer cap
        // still bounds a single unterminated frame, not a burst of whole ones.
        while (false !== ($data = fread($this->stdout, 8192)) && '' !== $data) {
            if (\strlen($this->inputBuffer) + \strlen($data) > $this->maxBufferSize) {
                $this->abortInput(\sprintf('buffered %d bytes without a newline, exceeding the %d byte limit', \strlen($this->inputBuffer) + \strlen($data), $this->maxBufferSize));

                return;
            }

            $this->inputBuffer .= $data;
            $this->dispatchCompleteFrames();
        }
    }

    /**
     * Hand every newline-delimited frame currently in the buffer to the message
     * handler, leaving any trailing partial frame behind.
     */
    private function dispatchCompleteFrames(): void
    {
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

            // Fail fast if the server process is already gone: no response can
            // arrive from a dead child, so waiting out the timeout only hides
            // why it died.
            $exitCode = $this->checkProcessExit();
            if (null !== $exitCode) {
                $this->logger->warning('Server process exited before responding', [
                    'request_id' => $requestId,
                    'exit_code' => $exitCode,
                ]);
                // A dead child will never answer this request, so drop it: a
                // reused transport must not carry it into the next attempt.
                $this->state->removePendingRequest($requestId);
                $error = Error::forInternalError($this->processExitMessage($exitCode), $requestId);
                $this->activeFiber->resume($error);

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
        $this->drainStderr();
    }

    /**
     * Read whatever stderr is currently available, log it, and keep a bounded
     * tail so a failed start can be explained. Non-blocking, so it returns as
     * soon as the pipe is drained.
     */
    private function drainStderr(): void
    {
        if (null === $this->stderr || !\is_resource($this->stderr)) {
            return;
        }

        while (false !== ($chunk = fread($this->stderr, 8192)) && '' !== $chunk) {
            $this->logger->debug('Server stderr', ['output' => trim($chunk)]);

            $this->stderrBuffer .= $chunk;
            if (\strlen($this->stderrBuffer) > self::MAX_STDERR_BUFFER_SIZE) {
                $this->stderrBuffer = substr($this->stderrBuffer, -self::MAX_STDERR_BUFFER_SIZE);
            }
        }
    }

    /**
     * Return the child's exit code once it has terminated, or null while it is
     * still running. proc_get_status() only reports a real exit code the first
     * time it is called after the process ends, so it is captured and cached
     * here, together with the final stderr.
     */
    private function checkProcessExit(): ?int
    {
        if (null !== $this->processExitCode) {
            return $this->processExitCode;
        }

        if (null === $this->process || !\is_resource($this->process)) {
            return null;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            return null;
        }

        $this->drainStderr();

        return $this->processExitCode = $status['exitcode'];
    }

    private function processExitMessage(int $exitCode): string
    {
        $message = \sprintf('Server process exited with code %d before responding', $exitCode);

        $stderr = trim($this->stderrBuffer);

        return '' !== $stderr ? $message.': '.$stderr : $message.'.';
    }
}
