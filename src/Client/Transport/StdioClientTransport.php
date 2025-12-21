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
use Mcp\Exception\TimeoutException;
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
 * @extends BaseClientTransport
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StdioClientTransport extends BaseClientTransport
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private string $inputBuffer = '';
    private bool $running = false;

    private ?\Fiber $activeFiber = null;

    /** @var (callable(float, ?float, ?string): void)|null */
    private $activeProgressCallback = null;

    /**
     * @param string               $command The command to run
     * @param array<int, string>   $args    Command arguments
     * @param string|null          $cwd     Working directory
     * @param array<string, string>|null $env  Environment variables
     */
    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly ?string $cwd = null,
        private readonly ?array $env = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($logger);
    }

    public function connectAndInitialize(int $timeout): void
    {
        $this->spawnProcess();

        $this->activeFiber = new \Fiber(fn() => $this->handleInitialize());

        $deadline = time() + $timeout;
        $this->activeFiber->start();

        while (!$this->activeFiber->isTerminated()) {
            if (time() >= $deadline) {
                $this->close();
                throw new TimeoutException('Initialization timed out after ' . $timeout . ' seconds');
            }
            $this->tick();
        }

        $result = $this->activeFiber->getReturn();
        $this->activeFiber = null;

        if ($result instanceof Error) {
            $this->close();
            throw new ConnectionException('Initialization failed: ' . $result->message);
        }

        $this->logger->info('Client connected and initialized');
    }

    public function send(string $data, array $context): void
    {
        if (null === $this->stdin || !\is_resource($this->stdin)) {
            throw new ConnectionException('Process stdin not available');
        }

        fwrite($this->stdin, $data . "\n");
        fflush($this->stdin);

        $this->logger->debug('Sent message to server', ['data' => $data]);
    }

    /**
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
        $this->running = false;

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
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $this->process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->cwd,
            $this->env
        );

        if (!\is_resource($this->process)) {
            throw new ConnectionException('Failed to start process: ' . $cmd);
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set non-blocking mode for reading
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->running = true;
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
        if (null === $this->activeProgressCallback || null === $this->session) {
            return;
        }

        $updates = $this->session->consumeProgressUpdates();

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

    private function processFiber(): void
    {
        if (null === $this->activeFiber || !$this->activeFiber->isSuspended()) {
            return;
        }

        if (null === $this->session) {
            return;
        }

        $pendingRequests = $this->session->getPendingRequests();

        foreach ($pendingRequests as $pending) {
            $requestId = $pending['request_id'];
            $timestamp = $pending['timestamp'];
            $timeout = $pending['timeout'];

            // Check if response arrived
            $response = $this->session->consumeResponse($requestId);

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
