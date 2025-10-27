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

use Mcp\Schema\JsonRpc\Error;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements TransportInterface<int>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 * */
class StdioTransport extends BaseTransport implements TransportInterface
{
    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($logger);
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

        while (!feof($this->input)) {
            $this->processInput();
            $this->processFiber();
            $this->flushOutgoingMessages();
        }

        $this->logger->info('StdioTransport finished listening.');
        $this->handleSessionEnd($this->sessionId);

        return 0;
    }

    protected function processInput(): void
    {
        $line = fgets($this->input);
        if (false === $line) {
            usleep(50000); // 50ms

            return;
        }

        $trimmedLine = trim($line);
        if (!empty($trimmedLine)) {
            $this->handleMessage($trimmedLine, $this->sessionId);
        }
    }

    protected function processFiber(): void
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

    protected function handleFiberTermination(): void
    {
        $finalResult = $this->sessionFiber->getReturn();

        if (null !== $finalResult) {
            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                $this->writeLine($encoded);
            } catch (\JsonException $e) {
                $this->logger->error('STDIO: Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }

        $this->sessionFiber = null;
    }

    protected function flushOutgoingMessages(): void
    {
        $messages = $this->getOutgoingMessages($this->sessionId);

        foreach ($messages as $message) {
            $this->writeLine($message['message']);
        }
    }

    protected function writeLine(string $payload): void
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
