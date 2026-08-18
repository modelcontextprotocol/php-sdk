<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client;

use Mcp\Client\Handler\Notification\NotificationHandlerInterface;
use Mcp\Client\Handler\Notification\ProgressNotificationHandler;
use Mcp\Client\Handler\Request\RequestHandlerInterface;
use Mcp\Client\State\ClientState;
use Mcp\Client\State\ClientStateInterface;
use Mcp\Client\Stateless\HeaderFactory;
use Mcp\Client\Stateless\InputRequestResolver;
use Mcp\Client\Stateless\RequestEnvelope;
use Mcp\Client\Stateless\ToolCatalog;
use Mcp\Client\Transport\HeaderAwareTransportInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\Exception\ConnectionException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\DiscoverRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Server\Stateless\RequestMeta;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Client protocol handler for MCP communication.
 *
 * Handles message routing, request/response correlation, and the initialization handshake.
 * All blocking operations are delegated to the transport.
 *
 * @phpstan-import-type FiberSuspend from TransportInterface
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Protocol
{
    /**
     * How many times a request may be re-sent before the client gives up.
     *
     * Both loops that re-send are bounded by it: a server that keeps asking for
     * input, and one that keeps rejecting the offered revision. Neither is
     * expected to run more than a round or two, so the cap is only there to
     * stop a broken or hostile server from spinning the client forever.
     */
    private const MAX_ROUND_TRIPS = 10;

    private ?TransportInterface $transport = null;
    private ClientStateInterface $state;
    private MessageFactory $messageFactory;
    private LoggerInterface $logger;

    /** @var NotificationHandlerInterface[] */
    private array $notificationHandlers;

    /** Set only when the configured revision has no handshake. */
    private ?RequestEnvelope $envelope = null;

    private ?HeaderFactory $headers = null;

    private readonly ToolCatalog $tools;

    private readonly InputRequestResolver $inputRequests;

    /**
     * Progress tokens are only required to be unique within a connection, and
     * a retry keeps the caller's one — the work being reported on is the same.
     */
    private int $progressTokens = 0;

    /**
     * @param RequestHandlerInterface<mixed>[] $requestHandlers
     * @param NotificationHandlerInterface[]   $notificationHandlers
     */
    public function __construct(
        private readonly array $requestHandlers = [],
        array $notificationHandlers = [],
        ?MessageFactory $messageFactory = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->state = new ClientState();
        $this->messageFactory = $messageFactory ?? MessageFactory::make();
        $this->logger = $logger ?? new NullLogger();

        $this->notificationHandlers = [
            new ProgressNotificationHandler($this->state),
            ...$notificationHandlers,
        ];

        $this->tools = new ToolCatalog($this->logger);
        $this->inputRequests = new InputRequestResolver($this->requestHandlers, $this->logger);
    }

    /**
     * What the client knows about the server's tools, from `tools/list`.
     *
     * Kept on the protocol rather than the facade because it is what makes the
     * SEP-2243 headers derivable at send time.
     */
    public function getToolCatalog(): ToolCatalog
    {
        return $this->tools;
    }

    /**
     * Connect this protocol to a transport.
     *
     * Sets up message handling callbacks.
     *
     * @param TransportInterface $transport The transport to connect
     * @param Configuration      $config    The client configuration for initialization
     */
    public function connect(TransportInterface $transport, Configuration $config): void
    {
        $this->transport = $transport;

        if ($config->protocolVersion->isModern()) {
            $this->envelope = new RequestEnvelope(
                $config->protocolVersion,
                $config->capabilities,
                $config->clientInfo,
            );
            $this->headers = new HeaderFactory($this->tools);
        }

        $transport->setState($this->state);
        $transport->onInitialize(fn () => $this->initialize($config));
        $transport->onMessage($this->processMessage(...));
        $transport->onError(fn (\Throwable $e) => $this->logger->error('Transport error', ['exception' => $e]));

        if ($transport instanceof HeaderAwareTransportInterface) {
            $transport->onHeaders($this->headersFor(...));
        }

        $this->logger->info('Protocol connected to transport', ['transport' => $transport::class]);
    }

    /**
     * The headers belonging to an encoded message, for a transport that has any.
     *
     * @return array<string, string>
     */
    private function headersFor(string $payload): array
    {
        if (null === $this->headers || null === $this->envelope) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return \is_array($decoded)
            ? $this->headers->forMessage($decoded, $this->envelope->protocolVersion())
            : [];
    }

    /**
     * Ready the connection for use.
     *
     * Up to 2025-11-25 that means the `initialize` handshake: offer a revision,
     * take the server's answer, confirm with `notifications/initialized`. From
     * 2026-07-28 there is no handshake at all — see {@see self::discover()}.
     *
     * @param Configuration $config The client configuration
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function initialize(Configuration $config): Response|Error
    {
        if (null !== $this->envelope) {
            return $this->discover($config);
        }

        $offered = $config->protocolVersion;

        $request = new InitializeRequest(
            $offered->value,
            $config->capabilities,
            $config->clientInfo,
        );

        $response = $this->request($request, $config->initTimeout);

        if ($response instanceof Response) {
            $initResult = InitializeResult::fromArray($response->result);

            // A counter-offer this SDK cannot speak leaves nothing to fall back to,
            // so the handshake fails rather than continuing on a revision neither
            // side agrees on.
            $negotiated = $initResult->protocolVersion;
            if (null === $negotiated || $negotiated->isModern()) {
                // fromArray() above already rejected a missing or non-string revision.
                $counterOffer = (string) $response->result['protocolVersion'];

                return Error::forInvalidParams(\sprintf(
                    'Server responded with unsupported protocol version "%s". Supported versions: %s.',
                    $counterOffer,
                    implode(', ', array_map(
                        static fn (ProtocolVersion $v): string => $v->value,
                        ProtocolVersion::handshakeVersions(),
                    )),
                ), $response->id);
            }

            $this->state->setProtocolVersion($negotiated);
            $this->state->setServerInfo($initResult->serverInfo);
            $this->state->setInstructions($initResult->instructions);
            $this->state->setInitialized(true);

            $this->sendNotification(new InitializedNotification());

            $this->logger->info('Initialization complete', [
                'server' => $initResult->serverInfo->name,
                'protocolVersion' => $negotiated->value,
            ]);
        }

        return $response;
    }

    /**
     * Stand in for the handshake in the modern era.
     *
     * There is nothing to negotiate: the revision travels on every request, so
     * the connection is usable the moment the transport is. `server/discover`
     * is only asked because the facade exposes `getServerInfo()`, and a server
     * that will not answer it still serves every other method — so a failure
     * here is logged and the connection proceeds.
     *
     * @return Response<array<string, mixed>>
     */
    private function discover(Configuration $config): Response
    {
        $this->state->setProtocolVersion($config->protocolVersion);
        $this->state->setInitialized(true);

        $response = $this->request(new DiscoverRequest(), $config->initTimeout);

        if ($response instanceof Error) {
            $this->logger->info('Server did not answer "server/discover"; continuing without its metadata.', [
                'code' => $response->code,
                'message' => $response->message,
            ]);

            return new Response(0, []);
        }

        $this->readDiscovery($response->result);

        return $response;
    }

    /**
     * Read defensively: `server/discover` is optional, so a server may answer
     * with something that is not a DiscoverResult at all, and none of it is
     * load-bearing for the requests that follow.
     *
     * @param array<string, mixed> $result
     */
    private function readDiscovery(array $result): void
    {
        // Identity is wire vocabulary in this revision, so it rides in `_meta`
        // rather than the result body. The top level is read as a fallback
        // because that is where the handshake era put it.
        $meta = \is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
        $serverInfo = $meta[RequestMeta::SERVER_INFO] ?? $result['serverInfo'] ?? null;

        if (\is_array($serverInfo)) {
            try {
                $this->state->setServerInfo(Implementation::fromArray($serverInfo));
            } catch (\Throwable $e) {
                $this->logger->debug('Ignoring unreadable serverInfo from "server/discover".', ['exception' => $e]);
            }
        }

        if (\is_string($result['instructions'] ?? null)) {
            $this->state->setInstructions($result['instructions']);
        }

        $this->reconcileVersion($result['supportedVersions'] ?? null);

        $this->logger->info('Discovery complete', [
            'supportedVersions' => $result['supportedVersions'] ?? null,
        ]);
    }

    /**
     * Move to a revision the server actually speaks, if it said which.
     *
     * `server/discover` reports rather than negotiates, so a client that asked
     * for something the server does not list learns it here — and learning it
     * now is far better than a stream of refusals later. A server that stays
     * silent about its versions is left alone; the method is optional and
     * saying nothing is not the same as saying no.
     */
    private function reconcileVersion(mixed $supportedVersions): void
    {
        if (!\is_array($supportedVersions) || [] === $supportedVersions || null === $this->envelope) {
            return;
        }

        $current = $this->envelope->protocolVersion();

        if (\in_array($current->value, $supportedVersions, true)) {
            return;
        }

        foreach ($supportedVersions as $candidate) {
            $version = \is_string($candidate) ? ProtocolVersion::tryFrom($candidate) : null;

            if (null === $version || !$version->isModern()) {
                continue;
            }

            $this->logger->warning('Server does not speak the configured revision; continuing on one it advertises.', [
                'configured' => $current->value,
                'using' => $version->value,
            ]);

            $this->envelope = $this->envelope->withProtocolVersion($version);
            $this->state->setProtocolVersion($version);

            return;
        }

        // Everything it offers is handshake era, which this connection cannot
        // reach — it has already skipped the handshake.
        throw new ConnectionException(\sprintf('Server does not support any modern protocol revision (it advertises %s); the configured "%s" cannot be used against it.', implode(', ', array_map(strval(...), $supportedVersions)), $current->value));
    }

    /**
     * Send a request to the server and wait for response.
     *
     * If a response is immediately available (sync HTTP), returns it.
     * Otherwise, suspends the Fiber and waits for the transport to resume it.
     *
     * In the modern era this is also where the two loops that re-send live:
     * answering a server's request for input (SEP-2322), and retrying under a
     * revision the server accepts (SEP-2575). Both re-send the same call, so
     * they belong together and above the single exchange.
     *
     * @param Request $request      The request to send
     * @param int     $timeout      The timeout in seconds
     * @param bool    $withProgress Whether to attach a progress token to the request
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function request(Request $request, int $timeout, bool $withProgress = false): Response|Error
    {
        $payload = $request->withId(0)->jsonSerialize();
        unset($payload['id']);

        if ($withProgress) {
            $payload = self::withMeta($payload, ['progressToken' => 'prog-'.++$this->progressTokens]);
        }

        if (null === $this->envelope) {
            return $this->exchange($payload, $timeout);
        }

        for ($attempt = 0; $attempt < self::MAX_ROUND_TRIPS; ++$attempt) {
            $response = $this->exchange($payload, $timeout);

            if ($response instanceof Error) {
                $retry = $this->withAcceptedVersion($response);

                if (null === $retry) {
                    return $response;
                }

                continue;
            }

            $asked = InputRequestResolver::asked($response->result);

            if (null === $asked) {
                return $response;
            }

            // A fresh `inputResponses`/`requestState` pair every round, never
            // merged with the last: the answers belong to the ask that just
            // arrived, and carrying an old one forward is how state leaks
            // between rounds.
            $payload['params'] = [
                ...($payload['params'] ?? []),
                'inputResponses' => $this->inputRequests->resolve($asked),
            ];

            unset($payload['params']['requestState']);

            // Echoed byte-for-byte, and only when the server sent one: the
            // value is the server's to read, and inventing or reshaping it
            // would break whatever it encodes.
            if (\is_string($response->result['requestState'] ?? null)) {
                $payload['params']['requestState'] = $response->result['requestState'];
            }

            $this->logger->debug('Retrying request with resolved input', [
                'method' => $payload['method'] ?? null,
                'round' => $attempt + 1,
            ]);
        }

        return Error::forInternalError(\sprintf('Server asked for input more than %d times without completing the request.', self::MAX_ROUND_TRIPS));
    }

    /**
     * Switches the offered revision when the server refuses the current one,
     * or null when there is nothing to retry with.
     *
     * @param Error $error the server's refusal
     */
    private function withAcceptedVersion(Error $error): ?ProtocolVersion
    {
        if (Error::UNSUPPORTED_PROTOCOL_VERSION !== $error->code || null === $this->envelope) {
            return null;
        }

        $data = \is_array($error->data) ? $error->data : [];
        $supported = \is_array($data['supported'] ?? null) ? $data['supported'] : [];
        $current = $this->envelope->protocolVersion();

        foreach ($supported as $candidate) {
            $version = \is_string($candidate) ? ProtocolVersion::tryFrom($candidate) : null;

            // Only another modern revision is reachable from here: falling back
            // to a handshake era one would mean opening a connection this
            // transport already decided it was not going to open.
            if (null === $version || !$version->isModern() || $version === $current) {
                continue;
            }

            $this->logger->info('Server rejected the offered protocol revision, retrying with one it supports.', [
                'offered' => $current->value,
                'retrying' => $version->value,
            ]);

            $this->envelope = $this->envelope->withProtocolVersion($version);
            $this->state->setProtocolVersion($version);

            return $version;
        }

        return null;
    }

    /**
     * One request on the wire: assign an id, send, and wait for its answer.
     *
     * A retry gets a new id, because the previous one is spent — the server has
     * already answered it, and reusing it would make the two indistinguishable.
     *
     * @param array<string, mixed> $payload
     *
     * @return Response<array<string, mixed>>|Error
     */
    private function exchange(array $payload, int $timeout): Response|Error
    {
        $requestId = $this->state->nextRequestId();
        $payload['id'] = $requestId;

        $this->state->addPendingRequest($requestId, $timeout);

        try {
            $this->send($payload, 'request');

            $immediate = $this->state->consumeResponse($requestId);
            if (null !== $immediate) {
                $this->logger->debug('Received immediate response', ['id' => $requestId]);

                return $immediate;
            }

            $this->logger->debug('Suspending fiber for response', ['id' => $requestId]);

            return \Fiber::suspend([
                'type' => 'await_response',
                'request_id' => $requestId,
                'timeout' => $timeout,
            ]);
        } finally {
            // Only the response path clears it, so a request that timed out or
            // whose send() threw would stay pending and fail every later one.
            $this->state->removePendingRequest($requestId);
        }
    }

    /**
     * Send a notification to the server (fire and forget).
     */
    public function sendNotification(Notification $notification): void
    {
        $this->send($notification->jsonSerialize(), 'notification');
    }

    /**
     * Encode and hand a message to the transport, stamping the per-request
     * envelope on the way out when the revision calls for one.
     *
     * @param array<string, mixed> $payload
     */
    private function send(array $payload, string $kind): void
    {
        if (null !== $this->envelope) {
            $payload = $this->envelope->stamp($payload);
        }

        $this->logger->debug('Sending '.$kind, [
            'id' => $payload['id'] ?? null,
            'method' => $payload['method'] ?? null,
        ]);

        $this->transport?->send(json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private static function withMeta(array $payload, array $meta): array
    {
        $params = \is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $existing = \is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $params['_meta'] = [...$existing, ...$meta];
        $payload['params'] = $params;

        return $payload;
    }

    /**
     * Send a response back to the server (for server-initiated requests).
     *
     * @param Response<mixed>|Error $response
     */
    private function sendResponse(Response|Error $response): void
    {
        $this->logger->debug('Sending response', ['id' => $response->getId()]);

        $encoded = json_encode($response, \JSON_THROW_ON_ERROR);
        $this->transport?->send($encoded);
    }

    /**
     * Process an incoming message from the server.
     *
     * Routes to appropriate handler based on message type.
     */
    public function processMessage(string $input): void
    {
        $this->logger->debug('Received message', ['input' => $input]);

        try {
            $messages = $this->messageFactory->create($input);
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse message', ['exception' => $e]);

            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof Response || $message instanceof Error) {
                $this->handleResponse($message);
            } elseif ($message instanceof Request) {
                $this->handleRequest($message);
            } elseif ($message instanceof Notification) {
                $this->handleNotification($message);
            }
        }
    }

    /**
     * Handle a response from the server.
     *
     * This stores it in session. The transport will pick it up and resume the Fiber.
     *
     * @param Response<mixed>|Error $response
     */
    private function handleResponse(Response|Error $response): void
    {
        $requestId = $response->getId();

        if (null === $requestId) {
            $this->logger->warning('Received an id-less error response; cannot correlate it to a request.', ['response' => $response->jsonSerialize()]);

            return;
        }

        $this->logger->debug('Handling response', ['id' => $requestId]);

        $this->state->storeResponse($requestId, $response->jsonSerialize());
    }

    /**
     * Handle a request from the server (e.g., sampling request).
     */
    private function handleRequest(Request $request): void
    {
        $method = $request::getMethod();

        $this->logger->debug('Received server request', [
            'method' => $method,
            'id' => $request->getId(),
        ]);

        foreach ($this->requestHandlers as $handler) {
            if ($handler->supports($request)) {
                try {
                    $response = $handler->handle($request);
                } catch (\Throwable $e) {
                    $this->logger->error('Unexpected error while handling request', [
                        'method' => $method,
                        'exception' => $e,
                    ]);

                    $response = Error::forInternalError(
                        \sprintf('Unexpected error while handling "%s" request', $method),
                        $request->getId()
                    );
                }

                $this->sendResponse($response);

                return;
            }
        }

        $error = Error::forMethodNotFound(
            \sprintf('Client does not handle "%s" requests.', $method),
            $request->getId()
        );

        $this->sendResponse($error);
    }

    /**
     * Handle a notification from the server.
     */
    private function handleNotification(Notification $notification): void
    {
        $method = $notification::getMethod();

        $this->logger->debug('Received server notification', [
            'method' => $method,
        ]);

        foreach ($this->notificationHandlers as $handler) {
            if ($handler->supports($notification)) {
                try {
                    $handler->handle($notification);
                } catch (\Throwable $e) {
                    $this->logger->warning('Notification handler failed', ['exception' => $e]);
                }

                return;
            }
        }
    }

    public function getState(): ClientStateInterface
    {
        return $this->state;
    }
}
