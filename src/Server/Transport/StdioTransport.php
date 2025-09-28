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

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StdioTransport implements TransportInterface
{
    /** @var callable(string, ?Uuid): void */
    private $messageListener;

    /** @var callable(Uuid): void */
    private $sessionEndListener;

    private ?Uuid $sessionId = null;

    /**
     * @param resource $input
     * @param resource $output
     */
    public function __construct(
        private $input = \STDIN,
        private $output = \STDOUT,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function initialize(): void
    {
    }

    public function onMessage(callable $listener): void
    {
        $this->messageListener = $listener;
    }

    public function send(string $data, array $context): void
    {
        $this->logger->debug('Sending data to client via StdioTransport.', ['data' => $data]);

        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }

        fwrite($this->output, $data.\PHP_EOL);
    }

    public function listen(): mixed
    {
        $this->logger->info('StdioTransport is listening for messages on STDIN...');

        while (!feof($this->input)) {
            $line = fgets($this->input);
            if (false === $line) {
                break;
            }

            $trimmedLine = trim($line);
            if (!empty($trimmedLine)) {
                $this->logger->debug('Received message on StdioTransport.', ['line' => $trimmedLine]);
                if (\is_callable($this->messageListener)) {
                    \call_user_func($this->messageListener, $trimmedLine, $this->sessionId);
                }
            }
        }

        $this->logger->info('StdioTransport finished listening.');

        if (\is_callable($this->sessionEndListener) && null !== $this->sessionId) {
            \call_user_func($this->sessionEndListener, $this->sessionId);
        }

        return null;
    }

    public function onSessionEnd(callable $listener): void
    {
        $this->sessionEndListener = $listener;
    }

    public function close(): void
    {
        if (\is_callable($this->sessionEndListener) && null !== $this->sessionId) {
            \call_user_func($this->sessionEndListener, $this->sessionId);
        }

        if (\is_resource($this->input)) {
            fclose($this->input);
        }

        if (\is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
