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
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;
use Mcp\Server\Wire\InboundClassifier;
use Psr\Log\LoggerInterface;

/**
 * Serves one client over the standard streams, in whichever protocol era it
 * opens with.
 *
 * The client's first request decides, once, for the life of the process: a
 * request carrying the 2026-07-28 per-request `_meta` envelope opens a modern
 * connection, anything else — the `initialize` handshake above all — a
 * handshake-era one. A later request from the other era is refused rather than
 * served, so a client that probed with `server/discover`, timed out and fell
 * back to the handshake learns the connection is already modern.
 *
 * @see https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning#backward-compatibility-with-initialization-based-versions
 *
 * @extends BaseTransport<int>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class StdioTransport extends BaseTransport implements StatelessAwareTransportInterface
{
    /**
     * Default cap on the bytes read for a single input line.
     */
    public const DEFAULT_MAX_LINE_BYTES = 4 * 1024 * 1024;

    private const CANCELLED_NOTIFICATION = 'notifications/cancelled';

    /** Whether the current over-length line is still being drained and discarded. */
    private bool $discardingLine = false;

    private ?StatelessProtocol $stateless = null;

    private readonly InboundClassifier $classifier;

    /** Null until the client's first request settles the era. */
    private ?bool $modern = null;

    /**
     * Modern-era answers still being written, by the id of the request they
     * answer: a `subscriptions/listen` for as long as it lasts, and a request
     * whose handler streams notifications until its result is in.
     *
     * Keyed by {@see self::streamKey()}, since PHP would fold the ids `"5"`
     * and `5` into one key, and JSON-RPC tells them apart.
     *
     * @var array<string, \Generator<mixed>>
     */
    private array $streams = [];

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

        $this->classifier = new InboundClassifier();

        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum line size must be a positive number of bytes, got %d.', $maxLineBytes));
        }
    }

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
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
            $this->processStreams();
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
            $this->route($trimmedLine);
        }
    }

    /**
     * Hands one message to the era it belongs to, settling the connection's
     * era on the first request.
     */
    private function route(string $message): void
    {
        $classification = $this->classifier->classify('POST', $message);

        if ($classification->isRejected()) {
            \assert(null !== $classification->error);
            $this->writeError($classification->error);

            return;
        }

        $decoded = json_decode($message, true);
        $request = \is_array($decoded) && !array_is_list($decoded) && isset($decoded['id']) ? $decoded : null;

        if (null === $this->modern && null !== $request) {
            if ($classification->modern && null === $this->stateless) {
                // Served nothing but the handshake: say which revisions that
                // is, the way the HTTP entry does, and leave the era open.
                $this->writeError(Error::forUnsupportedProtocolVersion((string) $classification->claimedVersion, ProtocolVersion::handshakeVersions(), $request['id']));

                return;
            }

            $this->modern = $classification->modern;

            $this->logger->info('StdioTransport settled the connection era.', [
                'era' => $this->modern ? 'modern' : 'handshake',
                'opened_with' => $request['method'] ?? null,
            ]);
        }

        if (true === $this->modern) {
            $this->routeModern($message, $decoded, $request);

            return;
        }

        if (false === $this->modern && $classification->modern && null !== $request) {
            $this->writeError(Error::forInvalidRequest('This connection opened with the "initialize" handshake; a request carrying a per-request protocol version cannot follow it.', $request['id']));

            return;
        }

        $this->handleMessage($message, $this->sessionId);
    }

    /**
     * @param mixed                     $decoded the message, decoded
     * @param array<string, mixed>|null $request the message when it is a request
     */
    private function routeModern(string $message, mixed $decoded, ?array $request): void
    {
        \assert(null !== $this->stateless);

        // stdio has no per-request stream to close, so this notification is
        // how a client stops one; nothing more may be sent for it.
        if (\is_array($decoded) && self::CANCELLED_NOTIFICATION === ($decoded['method'] ?? null) && !isset($decoded['id'])) {
            $requestId = $decoded['params']['requestId'] ?? null;

            if ((\is_string($requestId) || \is_int($requestId)) && isset($this->streams[$key = self::streamKey($requestId)])) {
                unset($this->streams[$key]);
                $this->logger->debug('StdioTransport dropped a cancelled request.', ['request_id' => $requestId]);
            }

            return;
        }

        $result = $this->stateless->handleInline($message);

        if ($result->isEmpty()) {
            return;
        }

        if ($result->isStream()) {
            \assert(null !== $result->frames && null !== $request);
            $this->streams[self::streamKey($request['id'])] = ($result->frames)();

            return;
        }

        $this->writeLine($result->toJson());
    }

    /**
     * Writes what each open stream has ready: every frame up to its next idle
     * poll, so a listen stream polls once per tick and a handler's
     * notifications go out as it emits them.
     */
    private function processStreams(): void
    {
        foreach ($this->streams as $id => $frames) {
            try {
                while ($frames->valid()) {
                    $frame = $frames->current();
                    $frames->next();

                    if (null === $frame) {
                        break;
                    }

                    $this->writeLine(json_encode($frame, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
                }
            } catch (\Throwable $e) {
                $this->logger->error('StdioTransport ended a stream that failed.', ['stream' => $id, 'exception' => $e]);
                unset($this->streams[$id]);

                continue;
            }

            if (!$frames->valid()) {
                unset($this->streams[$id]);
            }
        }
    }

    private static function streamKey(string|int $id): string
    {
        return (\is_int($id) ? 'i:' : 's:').$id;
    }

    private function writeError(Error $error): void
    {
        $this->writeLine(json_encode($error, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
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
