<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server;

use Mcp\Exception\InvalidInputMessageException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @final
 *
 * @phpstan-import-type McpFiber from \Mcp\Server\Transport\TransportInterface
 * @phpstan-import-type FiberSuspend from \Mcp\Server\Transport\TransportInterface
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Protocol
{
    /** Session key for request ID counter */
    private const SESSION_REQUEST_ID_COUNTER = '_mcp.request_id_counter';

    /** Session key for pending outgoing requests */
    private const SESSION_PENDING_REQUESTS = '_mcp.pending_requests';

    /** Session key for incoming client responses */
    private const SESSION_RESPONSES = '_mcp.responses';

    /** Session key for outgoing message queue */
    private const SESSION_OUTGOING_QUEUE = '_mcp.outgoing_queue';

    /** Session key for active request meta */
    public const SESSION_ACTIVE_REQUEST_META = '_mcp.active_request_meta';

    /** @var TransportInterface<mixed>|null */
    private ?TransportInterface $transport = null;

    /**
     * @param array<int, RequestHandlerInterface<ResultInterface|array<string, mixed>>> $requestHandlers
     * @param array<int, NotificationHandlerInterface>                                  $notificationHandlers
     */
    public function __construct(
        private readonly array $requestHandlers,
        private readonly array $notificationHandlers,
        private readonly MessageFactory $messageFactory,
        private readonly SessionFactoryInterface $sessionFactory,
        private readonly SessionStoreInterface $sessionStore,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return TransportInterface<mixed>
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Connect this protocol to a transport.
     *
     * The protocol takes ownership of the transport and sets up all callbacks.
     *
     * @param TransportInterface<mixed> $transport
     */
    public function connect(TransportInterface $transport): void
    {
        if ($this->transport) {
            throw new \RuntimeException('Protocol already connected to a transport');
        }

        $this->transport = $transport;

        $this->transport->onMessage([$this, 'processInput']);

        $this->transport->onSessionEnd([$this, 'destroySession']);

        $this->transport->setOutgoingMessagesProvider([$this, 'consumeOutgoingMessages']);

        $this->transport->setPendingRequestsProvider([$this, 'getPendingRequests']);

        $this->transport->setResponseFinder([$this, 'checkResponse']);

        $this->transport->setFiberYieldHandler([$this, 'handleFiberYield']);

        $this->logger->info('Protocol connected to transport', ['transport' => $transport::class]);
    }

    /**
     * Handle an incoming message from the transport.
     *
     * This is called by the transport whenever ANY message arrives.
     */
    public function processInput(string $input, ?Uuid $sessionId): void
    {
        $this->logger->info('Received message to process.', ['message' => $input]);

        $this->gcSessions();

        try {
            $messages = $this->messageFactory->create($input);
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to decode json message.', ['exception' => $e]);
            $error = Error::forParseError($e->getMessage());
            $this->sendResponse($error, null);

            return;
        }

        $session = $this->resolveSession($sessionId, $messages);
        if (null === $session) {
            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof InvalidInputMessageException) {
                $this->handleInvalidMessage($message, $session);
            } elseif ($message instanceof Request) {
                $this->handleRequest($message, $session);
            } elseif ($message instanceof Response || $message instanceof Error) {
                $this->handleResponse($message, $session);
            } elseif ($message instanceof Notification) {
                $this->handleNotification($message, $session);
            }
        }

        $session->save();
    }

    private function handleInvalidMessage(InvalidInputMessageException $exception, SessionInterface $session): void
    {
        $this->logger->warning('Failed to create message.', ['exception' => $exception]);

        $error = Error::forInvalidRequest($exception->getMessage());
        $this->sendResponse($error, $session);
    }

    private function handleRequest(Request $request, SessionInterface $session): void
    {
        $this->logger->info('Handling request.', ['request' => $request]);

        $session->set(self::SESSION_ACTIVE_REQUEST_META, $request->getMeta());

        $handlerFound = false;

        foreach ($this->requestHandlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            $handlerFound = true;

            try {
                /** @var McpFiber $fiber */
                $fiber = new \Fiber(fn () => $handler->handle($request, $session));

                $result = $fiber->start();

                if ($fiber->isSuspended()) {
                    if (\is_array($result) && isset($result['type'])) {
                        if ('notification' === $result['type']) {
                            $notification = $result['notification'];
                            $this->sendNotification($notification, $session);
                        } elseif ('request' === $result['type']) {
                            $request = $result['request'];
                            $timeout = $result['timeout'] ?? 120;
                            $this->sendRequest($request, $timeout, $session);
                        }
                    }

                    $this->transport->attachFiberToSession($fiber, $session->getId());

                    return;
                } else {
                    $finalResult = $fiber->getReturn();

                    $this->sendResponse($finalResult, $session);
                }
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning(\sprintf('Invalid argument: %s', $e->getMessage()), ['exception' => $e]);

                $error = Error::forInvalidParams($e->getMessage(), $request->getId());
                $this->sendResponse($error, $session);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Uncaught exception: %s', $e->getMessage()), ['exception' => $e]);

                $error = Error::forInternalError($e->getMessage(), $request->getId());
                $this->sendResponse($error, $session);
            }

            break;
        }

        if (!$handlerFound) {
            $error = Error::forMethodNotFound(\sprintf('No handler found for method "%s".', $request::getMethod()), $request->getId());
            $this->sendResponse($error, $session);
        }
    }

    /**
     * @param Response<array<string, mixed>>|Error $response
     */
    private function handleResponse(Response|Error $response, SessionInterface $session): void
    {
        $this->logger->info('Handling response from client.', ['response' => $response]);

        $messageId = $response->getId();

        $session->set(self::SESSION_RESPONSES.".{$messageId}", $response->jsonSerialize());
        $session->forget(self::SESSION_ACTIVE_REQUEST_META);

        $this->logger->info('Client response stored in session', [
            'message_id' => $messageId,
        ]);
    }

    private function handleNotification(Notification $notification, SessionInterface $session): void
    {
        $this->logger->info('Handling notification.', ['notification' => $notification]);

        foreach ($this->notificationHandlers as $handler) {
            if (!$handler->supports($notification)) {
                continue;
            }

            try {
                $handler->handle($notification, $session);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Error while handling notification: %s', $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    /**
     * Sends a request to the client and returns the request ID.
     */
    public function sendRequest(Request $request, int $timeout, SessionInterface $session): int
    {
        $counter = $session->get(self::SESSION_REQUEST_ID_COUNTER, 1000);
        $requestId = $counter++;
        $session->set(self::SESSION_REQUEST_ID_COUNTER, $counter);

        $requestWithId = $request->withId($requestId);

        $this->logger->info('Queueing server request to client', [
            'request_id' => $requestId,
            'method' => $request::getMethod(),
        ]);

        $pending = $session->get(self::SESSION_PENDING_REQUESTS, []);
        $pending[$requestId] = [
            'request_id' => $requestId,
            'timeout' => $timeout,
            'timestamp' => time(),
        ];
        $session->set(self::SESSION_PENDING_REQUESTS, $pending);

        $this->queueOutgoing($requestWithId, ['type' => 'request'], $session);

        return $requestId;
    }

    /**
     * Queues a notification for later delivery.
     */
    public function sendNotification(Notification $notification, SessionInterface $session): void
    {
        $this->logger->info('Queueing server notification to client', [
            'method' => $notification::getMethod(),
        ]);

        $this->queueOutgoing($notification, ['type' => 'notification'], $session);
    }

    /**
     * Sends a response either immediately or queued for later delivery.
     *
     * @param Response<ResultInterface|array<string, mixed>>|Error $response
     * @param array<string, mixed>                                 $context
     */
    private function sendResponse(Response|Error $response, ?SessionInterface $session, array $context = []): void
    {
        if (null === $session) {
            $this->logger->info('Sending immediate response', [
                'response_id' => $response->getId(),
            ]);

            try {
                $encoded = json_encode($response, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $this->logger->error('Failed to encode response to JSON.', [
                    'message_id' => $response->getId(),
                    'exception' => $e,
                ]);

                $fallbackError = new Error(
                    id: $response->getId(),
                    code: Error::INTERNAL_ERROR,
                    message: 'Response could not be encoded to JSON'
                );

                $encoded = json_encode($fallbackError, \JSON_THROW_ON_ERROR);
            }

            $context['type'] = 'response';
            $this->transport->send($encoded, $context);
        } else {
            $this->logger->info('Queueing server response', [
                'response_id' => $response->getId(),
            ]);

            $this->queueOutgoing($response, ['type' => 'response'], $session);
        }
    }

    /**
     * Helper to queue outgoing messages in session.
     *
     * @param Request|Notification|Response<ResultInterface|array<string, mixed>>|Error $message
     * @param array<string, mixed>                                                      $context
     */
    private function queueOutgoing(Request|Notification|Response|Error $message, array $context, SessionInterface $session): void
    {
        try {
            $encoded = json_encode($message, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('Failed to encode message to JSON.', [
                'exception' => $e,
            ]);

            return;
        }

        $queue = $session->get(self::SESSION_OUTGOING_QUEUE, []);
        $queue[] = [
            'message' => $encoded,
            'context' => $context,
        ];
        $session->set(self::SESSION_OUTGOING_QUEUE, $queue);
    }

    /**
     * Consume (get and clear) all outgoing messages for a session.
     *
     * @return array<int, array{message: string, context: array<string, mixed>}>
     */
    public function consumeOutgoingMessages(Uuid $sessionId): array
    {
        $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);
        $queue = $session->get(self::SESSION_OUTGOING_QUEUE, []);
        $session->set(self::SESSION_OUTGOING_QUEUE, []);
        $session->save();

        return $queue;
    }

    /**
     * Check for a response to a specific request ID.
     *
     * When a response is found, it is removed from the session, and the
     * corresponding pending request is also cleared.
     */
    /**
     * @return Response<array<string, mixed>>|Error|null
     */
    public function checkResponse(int $requestId, Uuid $sessionId): Response|Error|null
    {
        $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);
        $responseData = $session->get(self::SESSION_RESPONSES.".{$requestId}");

        if (null === $responseData) {
            return null;
        }

        $this->logger->debug('Found and consuming client response.', [
            'request_id' => $requestId,
            'session_id' => $sessionId->toRfc4122(),
        ]);

        $session->set(self::SESSION_RESPONSES.".{$requestId}", null);
        $pending = $session->get(self::SESSION_PENDING_REQUESTS, []);
        unset($pending[$requestId]);
        $session->set(self::SESSION_PENDING_REQUESTS, $pending);
        $session->save();

        try {
            if (isset($responseData['error'])) {
                return Error::fromArray($responseData);
            }

            return Response::fromArray($responseData);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to reconstruct client response from session.', [
                'request_id' => $requestId,
                'exception' => $e,
                'response_data' => $responseData,
            ]);

            return null;
        }
    }

    /**
     * Get pending requests for a session.
     *
     * @return array<int, mixed> The pending requests
     */
    public function getPendingRequests(Uuid $sessionId): array
    {
        $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);

        return $session->get(self::SESSION_PENDING_REQUESTS, []);
    }

    /**
     * Handle values yielded by Fibers during transport-managed resumes.
     *
     * @param FiberSuspend|null $yieldedValue
     */
    public function handleFiberYield(mixed $yieldedValue, ?Uuid $sessionId): void
    {
        if (!$sessionId) {
            $this->logger->warning('Fiber yielded value without associated session context.');

            return;
        }

        if (!\is_array($yieldedValue) || !isset($yieldedValue['type'])) {
            $this->logger->warning('Fiber yielded unexpected payload.', [
                'payload' => $yieldedValue,
                'session_id' => $sessionId->toRfc4122(),
            ]);

            return;
        }

        $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);

        $payloadSessionId = $yieldedValue['session_id'] ?? null;
        if (\is_string($payloadSessionId) && $payloadSessionId !== $sessionId->toRfc4122()) {
            $this->logger->warning('Fiber yielded payload with mismatched session ID.', [
                'payload_session_id' => $payloadSessionId,
                'expected_session_id' => $sessionId->toRfc4122(),
            ]);
        }

        try {
            if ('notification' === $yieldedValue['type']) {
                $notification = $yieldedValue['notification'] ?? null;
                if (!$notification instanceof Notification) {
                    $this->logger->warning('Fiber yielded notification without Notification instance.', [
                        'payload' => $yieldedValue,
                    ]);

                    return;
                }

                $this->sendNotification($notification, $session);
            } elseif ('request' === $yieldedValue['type']) {
                $request = $yieldedValue['request'] ?? null;
                if (!$request instanceof Request) {
                    $this->logger->warning('Fiber yielded request without Request instance.', [
                        'payload' => $yieldedValue,
                    ]);

                    return;
                }

                $timeout = isset($yieldedValue['timeout']) ? (int) $yieldedValue['timeout'] : 120;
                $this->sendRequest($request, $timeout, $session);
            } else {
                $this->logger->warning('Fiber yielded unknown operation type.', [
                    'type' => $yieldedValue['type'],
                ]);
            }
        } finally {
            $session->save();
        }
    }

    /**
     * @param array<int, mixed> $messages
     */
    private function hasInitializeRequest(array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message instanceof InitializeRequest) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves and validates the session based on the request context.
     *
     * @param Uuid|null        $sessionId The session ID from the transport
     * @param array<int,mixed> $messages  The parsed messages
     */
    private function resolveSession(?Uuid $sessionId, array $messages): ?SessionInterface
    {
        if ($this->hasInitializeRequest($messages)) {
            // Spec: An initialize request must not be part of a batch.
            if (\count($messages) > 1) {
                $error = Error::forInvalidRequest('The "initialize" request MUST NOT be part of a batch.');
                $this->sendResponse($error, null);

                return null;
            }

            // Spec: An initialize request must not have a session ID.
            if ($sessionId) {
                $error = Error::forInvalidRequest('A session ID MUST NOT be sent with an "initialize" request.');
                $this->sendResponse($error, null);

                return null;
            }

            $session = $this->sessionFactory->create($this->sessionStore);
            $this->logger->debug('Created new session for initialize', [
                'session_id' => $session->getId()->toString(),
            ]);

            $this->transport->setSessionId($session->getId());

            return $session;
        }

        if (!$sessionId) {
            $error = Error::forInvalidRequest('A valid session id is REQUIRED for non-initialize requests.');
            $this->sendResponse($error, null, ['status_code' => 400]);

            return null;
        }

        if (!$this->sessionStore->exists($sessionId)) {
            $error = Error::forInvalidRequest('Session not found or has expired.');
            $this->sendResponse($error, null, ['status_code' => 404]);

            return null;
        }

        return $this->sessionFactory->createWithId($sessionId, $this->sessionStore);
    }

    /**
     * Run garbage collection on expired sessions.
     * Uses the session store's internal TTL configuration.
     */
    private function gcSessions(): void
    {
        if (random_int(0, 100) > 1) {
            return;
        }

        $deletedSessions = $this->sessionStore->gc();
        if (!empty($deletedSessions)) {
            $this->logger->debug('Garbage collected expired sessions.', [
                'count' => \count($deletedSessions),
                'session_ids' => array_map(fn (Uuid $id) => $id->toRfc4122(), $deletedSessions),
            ]);
        }
    }

    /**
     * Destroy a specific session.
     */
    public function destroySession(Uuid $sessionId): void
    {
        $this->sessionStore->destroy($sessionId);
        $this->logger->info('Session destroyed.', ['session_id' => $sessionId->toRfc4122()]);
    }
}
