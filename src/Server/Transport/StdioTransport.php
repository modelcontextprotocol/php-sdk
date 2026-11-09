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
use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Stateless\StatelessResult;
use Mcp\Server\Transport\Stdio\RunnerControl;
use Mcp\Server\Transport\Stdio\RunnerControlInterface;
use Mcp\Server\Transport\Stdio\RunnerState;
use Mcp\Server\Wire\InboundClassifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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
 * @phpstan-import-type McpFiber from TransportInterface
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

    /** @var positive-int */
    private readonly int $maxLineBytes;

    private ?StatelessProtocol $stateless = null;

    /** @var non-empty-list<ProtocolVersion>|null null names every handshake revision */
    private ?array $handshakeVersions = null;

    private readonly InboundClassifier $classifier;

    private bool $handlingNotification = false;

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
     * Handshake-era handlers suspended on the client, by object id: the
     * client pipelines its requests, so several can be in flight at once.
     *
     * @var array<int, McpFiber>
     */
    private array $fibers = [];

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
        int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
    ) {
        parent::__construct($logger);

        $this->classifier = new InboundClassifier();

        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException(\sprintf('The maximum line size must be a positive number of bytes, got %d.', $maxLineBytes));
        }

        $this->maxLineBytes = $maxLineBytes;
    }

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
    }

    public function setHandshakeVersions(array $versions): void
    {
        $this->handshakeVersions = $versions;
    }

    public function send(string $data, array $context): void
    {
        if (isset($context['session_id'])) {
            $this->sessionId = $context['session_id'];
        }

        // Over HTTP a refused notification gets an error status; stdio has nothing to answer it with.
        if ($this->handlingNotification && 'response' === ($context['type'] ?? null)) {
            $this->logger->warning('StdioTransport did not answer a notification it refused.', ['error' => $data]);

            return;
        }

        $this->writeLine($data);
    }

    public function attachFiberToSession(\Fiber $fiber, Uuid $sessionId): void
    {
        $this->fibers[spl_object_id($fiber)] = $fiber;
        $this->sessionId = $sessionId;
    }

    public function listen(): int
    {
        $this->logger->info('StdioTransport is listening for messages on STDIN...');
        stream_set_blocking($this->input, false);

        while (!feof($this->input) && RunnerState::RUNNING === $this->runnerControl->getState()) {
            $this->processInput();
            $this->processFibers();
            $this->processStreams();
            $this->flushOutgoingMessages();
        }

        $this->logger->info('StdioTransport finished listening.');
        if (\in_array($this->runnerControl->getState(), [RunnerState::RUNNING, RunnerState::STOP_AND_END_SESSION], true)) {
            $this->logger->info('StdioTransport end session.');
            $this->endSessionOnShutdown($this->sessionId);
        }

        return 0;
    }

    protected function processInput(): void
    {
        // fgets() on an idle Windows pipe blocks even in non-blocking mode, then reports a false EOF.
        if (!$this->waitForInput()) {
            return;
        }

        $line = fgets($this->input, $this->maxLineBytes);
        if (false === $line) {
            // Selecting may have failed, and then nothing else keeps an idle run loop from spinning.
            if (!feof($this->input)) {
                usleep(50000);
            }

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
     * Idles until input arrives, for at most 50ms, so the run loop still gets
     * to suspended fibers, open streams and queued messages in between.
     */
    private function waitForInput(): bool
    {
        $read = [$this->input];
        $write = $except = null;

        // A stream that cannot be selected, like php://memory, warns and throws, so it is just read.
        try {
            return 0 !== @stream_select($read, $write, $except, 0, 50000);
        } catch (\ValueError) {
            return true;
        }
    }

    /**
     * Hands one message to the era it belongs to, settling the connection's
     * era on the first request.
     */
    private function route(string $message): void
    {
        $classification = $this->classifier->classify('POST', $message);
        $decoded = json_decode($message, true);

        if ($classification->isRejected()) {
            \assert(null !== $classification->error);

            if (self::isNotification($decoded)) {
                $this->logger->warning('StdioTransport did not answer a notification it refused.', ['error' => $classification->error]);

                return;
            }

            $this->writeError($classification->error);

            return;
        }

        // Only a request with a usable id may settle the era; anything else is left to the dispatcher.
        $request = \is_array($decoded) && \is_string($decoded['method'] ?? null)
            && (\is_string($decoded['id'] ?? null) || \is_int($decoded['id'] ?? null)) ? $decoded : null;

        // Only the handshake era sends requests to the client, so elsewhere a response answers nothing.
        if (false !== $this->modern && \is_array($decoded) && !isset($decoded['method'])
            && (\array_key_exists('result', $decoded) || \array_key_exists('error', $decoded))) {
            $this->logger->warning('StdioTransport ignored a response outside the handshake era.', [
                'id' => $decoded['id'] ?? null,
            ]);

            return;
        }

        if (null === $this->modern && null !== $request) {
            if ($classification->modern && null === $this->stateless) {
                // Handshake only: name its revisions, like the HTTP entry, and leave the era open.
                $this->writeError(Error::forUnsupportedProtocolVersion((string) $classification->claimedVersion, $this->handshakeVersions ?? ProtocolVersion::handshakeVersions(), $request['id']));

                return;
            }

            if ($classification->modern && !\in_array(ProtocolVersion::tryFrom((string) $classification->claimedVersion), $this->stateless->supportedVersions(), true)) {
                // Refused by the modern leg; the era stays open for a fallback to the handshake.
                $this->routeModern($message, $decoded, $request);

                return;
            }

            $this->modern = $classification->modern;

            $this->logger->info('StdioTransport settled the connection era.', [
                'era' => $this->modern ? 'modern' : 'handshake',
                'opened_with' => $request['method'],
            ]);
        }

        if (true === $this->modern || (null === $this->modern && $classification->modern && null !== $this->stateless)) {
            $this->routeModern($message, $decoded, $request);

            return;
        }

        if (false === $this->modern && $classification->modern && null !== $request) {
            $this->writeError(Error::forInvalidRequest('This connection opened with the "initialize" handshake; a request carrying a per-request protocol version cannot follow it.', $request['id']));

            return;
        }

        $this->handlingNotification = self::isNotification($decoded);

        try {
            $this->handleMessage($message, $this->sessionId);
        } finally {
            $this->handlingNotification = false;
        }
    }

    /**
     * @param mixed                     $decoded the message, decoded
     * @param array<string, mixed>|null $request the message when it is a request
     */
    private function routeModern(string $message, mixed $decoded, ?array $request): void
    {
        \assert(null !== $this->stateless);

        // stdio has no stream to close, so this is how a client ends a subscriptions/listen.
        if (\is_array($decoded) && self::CANCELLED_NOTIFICATION === ($decoded['method'] ?? null) && !isset($decoded['id'])) {
            try {
                $cancellation = MessageFactory::make()->create($message);
            } catch (InvalidInputMessageException) {
                $cancellation = null;
            }

            if (!$cancellation instanceof CancelledNotification) {
                $this->logger->debug('StdioTransport ignored a malformed cancellation.');

                return;
            }

            $requestId = $cancellation->requestId;

            if (isset($this->streams[$key = self::streamKey($requestId)])) {
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

                    // Written before resuming: resuming runs the handler on to its next frame.
                    if (null !== $frame) {
                        $this->writeLine(StatelessResult::encodeFrame($frame));
                    }

                    $frames->next();

                    if (null === $frame) {
                        break;
                    }
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

    private static function isNotification(mixed $decoded): bool
    {
        if (!\is_array($decoded) || MessageInterface::JSONRPC_VERSION !== ($decoded['jsonrpc'] ?? null)
            || !\is_string($decoded['method'] ?? null) || \array_key_exists('id', $decoded)) {
            return false;
        }

        return null === ($decoded['params'] ?? null) || \is_array($decoded['params']);
    }

    private static function streamKey(string|int $id): string
    {
        return (\is_int($id) ? 'i:' : 's:').$id;
    }

    private function writeError(Error $error): void
    {
        $this->writeLine(json_encode($error, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * Resumes every suspended handler that can go on: one that sent a
     * notification right away, one that sent a request once its answer is in
     * or it timed out.
     */
    private function processFibers(): void
    {
        foreach ($this->fibers as $key => $fiber) {
            if ($fiber->isSuspended() && null !== $error = $this->resumeFiber($fiber)) {
                unset($this->fibers[$key]);
                $this->flushOutgoingMessages();
                $this->writeError($error);

                continue;
            }

            if ($fiber->isTerminated()) {
                unset($this->fibers[$key]);
                $this->handleFiberTermination($fiber);
            }
        }
    }

    /**
     * @param McpFiber $fiber
     */
    private function resumeFiber(\Fiber $fiber): ?Error
    {
        $pendingRequests = $this->getPendingRequests($this->sessionId, $fiber);

        if (empty($pendingRequests)) {
            return $this->resumeHandlerFiber($fiber, $this->sessionId);
        }

        foreach ($pendingRequests as $pending) {
            $requestId = Uuid::fromString($pending['request_id']);

            $response = $this->checkForResponse($requestId, $this->sessionId);

            if (null !== $response) {
                return $this->resumeHandlerFiber($fiber, $this->sessionId, $response);
            }
        }

        return null;
    }

    /**
     * @param McpFiber $fiber
     */
    private function handleFiberTermination(\Fiber $fiber): void
    {
        $finalResult = $fiber->getReturn();

        // A client may stop reading once it has its answer, so what the call sent goes out first.
        $this->flushOutgoingMessages();

        if (null !== $finalResult) {
            try {
                $encoded = json_encode($finalResult, \JSON_THROW_ON_ERROR);
                $this->writeLine($encoded);
            } catch (\JsonException $e) {
                $this->logger->error('STDIO: Failed to encode final Fiber result.', ['exception' => $e]);
            }
        }
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
        $this->endSessionOnShutdown($this->sessionId);
        if (\is_resource($this->input)) {
            fclose($this->input);
        }
        if (\is_resource($this->output)) {
            fclose($this->output);
        }
    }
}
