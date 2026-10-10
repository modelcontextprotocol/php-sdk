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

use Mcp\Event\ClientResponseEvent;
use Mcp\Event\ErrorEvent;
use Mcp\Event\NotificationEvent;
use Mcp\Event\RequestEvent;
use Mcp\Event\ResponseEvent;
use Mcp\Event\ServerRequestEvent;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\Exception\RuntimeException;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionManagerInterface;
use Mcp\Server\Stateless\InputContext;
use Mcp\Server\Stateless\RequestStateCodec;
use Mcp\Server\Suspension\NotificationSuspension;
use Mcp\Server\Suspension\RequestSuspension;
use Mcp\Server\Transport\TransportInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @final
 *
 * @phpstan-import-type McpFiber from TransportInterface
 * @phpstan-import-type FiberSuspend from TransportInterface
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

    public const SESSION_LOGGING_LEVEL = '_mcp.logging_level';

    /**
     * Deliberately generic: unexpected throwables carry internal details such as file paths, class
     * names and argument types, which must not be handed to the peer. The full exception is logged.
     */
    private const INTERNAL_ERROR_MESSAGE = 'Internal server error.';

    /**
     * The client request each transport's fiber is suspended on. Pending requests are stored in the
     * session, which concurrent streams share, so a stream must only poll the one its fiber sent.
     *
     * @var \WeakMap<TransportInterface<mixed>, int>
     */
    private \WeakMap $awaitedRequestIds;

    /**
     * @param array<int, RequestHandlerInterface<ResultInterface|array<string, mixed>>> $requestHandlers
     * @param array<int, NotificationHandlerInterface>                                  $notificationHandlers
     */
    public function __construct(
        private readonly array $requestHandlers,
        private readonly array $notificationHandlers,
        private readonly MessageFactory $messageFactory,
        private readonly SessionManagerInterface $sessionManager,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?InputRequiredShim $inputRequiredShim = null,
        private readonly ?RequestStateCodec $requestStateCodec = null,
    ) {
        $this->awaitedRequestIds = new \WeakMap();
    }

    /**
     * Connect this protocol to transport.
     *
     * The protocol takes ownership of the transport and sets up all callbacks.
     *
     * @param TransportInterface<mixed> $transport
     */
    public function connect(TransportInterface $transport): void
    {
        $transport->onMessage($this->processInput(...));

        $transport->onSessionEnd($this->destroySession(...));

        $transport->setOutgoingMessagesProvider($this->consumeOutgoingMessages(...));

        // The transport keeps these callbacks, so they reference it weakly to not keep it alive.
        $transportRef = \WeakReference::create($transport);

        $transport->setPendingRequestsProvider(fn (Uuid $sessionId): array => $this->getAwaitedPendingRequests($transportRef->get(), $sessionId));

        $transport->setResponseFinder($this->checkResponse(...));

        $transport->setFiberYieldHandler(function (mixed $yieldedValue, ?Uuid $sessionId) use ($transportRef): void {
            $requestId = $this->handleFiberYield($yieldedValue, $sessionId);

            if (null !== $transport = $transportRef->get()) {
                $this->trackAwaitedRequest($transport, $requestId);
            }
        });

        $this->logger->info('Protocol connected to transport', ['transport' => $transport::class]);
    }

    /**
     * Handle an incoming message from the transport.
     *
     * This is called by the transport whenever ANY message arrives.
     *
     * @param TransportInterface<mixed> $transport
     * @param AccessToken|null          $accessToken the token the input was authorized with, if the transport authorizes
     */
    public function processInput(TransportInterface $transport, string $input, ?Uuid $sessionId, ?AccessToken $accessToken = null): void
    {
        // Last line of defense: a malformed message must never escape as a PHP error and take the
        // server process down.
        try {
            $this->doProcessInput($transport, $input, $sessionId, $accessToken);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Uncaught exception while processing input: %s', $e->getMessage()), ['exception' => $e]);

            // Only a request may be answered. Replying to a notification would violate JSON-RPC,
            // and the failure has already been logged.
            if (null === $id = self::findResponseId($input)) {
                return;
            }

            try {
                $this->sendResponse($transport, Error::forInternalError(self::INTERNAL_ERROR_MESSAGE, $id), null);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Failed to send internal error response: %s', $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    /**
     * Determines the id an unprocessable input has to be answered under.
     *
     * Returns null when the input carries no request at all, in which case it consists of
     * notifications only and JSON-RPC forbids answering it. A batch resolves to the empty id
     * because its failure cannot be attributed to one of its requests.
     */
    private static function findResponseId(string $input): string|int|null
    {
        try {
            $data = json_decode($input, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($data)) {
            return null;
        }

        if (!array_is_list($data)) {
            $id = $data['id'] ?? null;

            return \is_string($id) || \is_int($id) ? $id : null;
        }

        foreach ($data as $message) {
            if (\is_array($message) && isset($message['id'])) {
                return '';
            }
        }

        return null;
    }

    /**
     * @param TransportInterface<mixed> $transport
     */
    private function doProcessInput(TransportInterface $transport, string $input, ?Uuid $sessionId, ?AccessToken $accessToken): void
    {
        $this->logger->info('Received message to process.');
        $this->logger->debug('Received message payload.', ['message' => $input]);

        $this->sessionManager->gc();

        try {
            $messages = $this->messageFactory->create($input);
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to decode json message.', ['exception' => $e]);
            $error = Error::forParseError($e->getMessage());
            $this->sendResponse($transport, $error, null);

            return;
        }

        $session = $this->resolveSession($transport, $sessionId, $messages);
        if (null === $session) {
            return;
        }

        foreach ($messages as $message) {
            // Guarded per message so one faulty message cannot suppress the rest of a batch.
            try {
                if ($message instanceof InvalidInputMessageException) {
                    $this->handleInvalidMessage($transport, $message, $session);
                } elseif ($message instanceof Request) {
                    $this->handleRequest($transport, $message, $session, $accessToken);
                } elseif ($message instanceof Response || $message instanceof Error) {
                    $this->handleResponse($message, $session);
                } elseif ($message instanceof Notification) {
                    $this->handleNotification($message, $session);
                }
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Uncaught exception while handling message: %s', $e->getMessage()), ['exception' => $e]);

                // Only a request may be answered; a notification or a response must not produce one.
                if ($message instanceof Request) {
                    $error = Error::forInternalError(self::INTERNAL_ERROR_MESSAGE, $message->getId());
                    $this->sendResponse($transport, $error, $session);
                }
            }
        }

        try {
            $session->save();
        } catch (\Throwable $e) {
            // The responses already reached the transport: an error for the same requests would contradict them.
            $this->logger->error(\sprintf('Failed to save session: %s', $e->getMessage()), ['exception' => $e]);
        }
    }

    /**
     * Handle an invalid message from the transport.
     *
     * @param TransportInterface<mixed> $transport
     */
    private function handleInvalidMessage(TransportInterface $transport, InvalidInputMessageException $exception, SessionInterface $session): void
    {
        $this->logger->warning('Failed to create message.', ['exception' => $exception]);

        $error = Error::forInvalidRequest($exception->getMessage(), $exception->getRequestId());
        $this->sendResponse($transport, $error, $session);
    }

    /**
     * Dispatches an event through the event dispatcher if available.
     *
     * @template T of object
     *
     * @param T $event
     *
     * @return T
     */
    private function dispatchEvent(object $event): object
    {
        if (null === $this->eventDispatcher) {
            return $event;
        }

        $dispatched = $this->eventDispatcher->dispatch($event);

        // PSR-14 dispatchers return the event they were given; a dispatcher that
        // swaps it for something else is not what the caller asked to dispatch.
        if (!$dispatched instanceof $event) {
            $this->logger->debug('Event dispatcher returned a different object than it was given; keeping the original.', [
                'event' => $event::class,
                'returned' => $dispatched::class,
            ]);

            return $event;
        }

        return $dispatched;
    }

    /**
     * Handle a request from the transport.
     *
     * @param TransportInterface<mixed> $transport
     */
    private function handleRequest(TransportInterface $transport, Request $request, SessionInterface $session, ?AccessToken $accessToken): void
    {
        $this->logger->info('Handling request.', ['method' => $request::getMethod(), 'request_id' => $request->getId()]);

        $session->set(self::SESSION_ACTIVE_REQUEST_META, $request->getMeta());

        // Overwritten on every request, so a token never carries over to a later
        // request of the same session; AccessToken serializes to null.
        $session->set(AccessToken::class, $accessToken);

        // A request starts with nothing behind it: the shim fills this in as it
        // collects answers, and clearing it here is what keeps one request's
        // round from being read as another's.
        $session->set(InputContext::class, null);

        if (null !== $this->requestStateCodec) {
            $session->set(RequestStateCodec::class, $this->requestStateCodec);
        }

        $event = $this->dispatchEvent(new RequestEvent($request, $session));
        $request = $event->getRequest();

        $handlerFound = false;

        foreach ($this->requestHandlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            $handlerFound = true;

            try {
                $shim = $this->inputRequiredShim;
                $codec = $this->requestStateCodec;
                $dispatchResult = $this->dispatchResult(...);
                $errorForThrowable = $this->errorForThrowable(...);

                // One fiber for the whole exchange: with the shim, the handler
                // re-enters inside it each round rather than needing a new one.
                // Handled in the fiber, as the transport resumes it outside the catch below.
                /** @var McpFiber $fiber */
                $fiber = new \Fiber(static function () use ($handler, $request, $session, $shim, $codec, $dispatchResult, $errorForThrowable): Response|Error {
                    try {
                        $result = $handler->handle($request, $session);
                        $result = $shim?->fulfill($result, $handler, $request, $session, $codec) ?? $result;
                    } catch (\Throwable $e) {
                        return $errorForThrowable($e, $request, $session);
                    }

                    return $dispatchResult($result, $request, $session);
                });

                $result = $fiber->start();

                if ($fiber->isSuspended()) {
                    $beforeSuspension = $session->all();

                    $awaitedRequestId = null;
                    if ($result instanceof NotificationSuspension) {
                        $this->sendNotification($result->notification, $session);
                    } elseif ($result instanceof RequestSuspension) {
                        $awaitedRequestId = $this->sendRequest($result->request, $result->timeout, $session);
                    }

                    // The transport resumes the fiber from what the session holds: it must
                    // not get the fiber if the request the fiber awaits was not stored. A
                    // later save must not store that request either, nobody would resume it.
                    try {
                        $saved = $session->save();
                    } catch (\Throwable $e) {
                        $session->hydrate($beforeSuspension);

                        throw $e;
                    }

                    if (!$saved) {
                        $session->hydrate($beforeSuspension);

                        throw new RuntimeException('Failed to save the session of a suspended request.');
                    }

                    $this->trackAwaitedRequest($transport, $awaitedRequestId);
                    $transport->attachFiberToSession($fiber, $session->getId());

                    return;
                }

                $this->sendResponse($transport, $fiber->getReturn(), $session);
            } catch (\Throwable $e) {
                $this->sendResponse($transport, $this->errorForThrowable($e, $request, $session), $session);
            }

            break;
        }

        if (!$handlerFound) {
            $error = Error::forMethodNotFound(\sprintf('No handler found for method "%s".', $request::getMethod()), $request->getId());
            $errorEvent = $this->dispatchEvent(new ErrorEvent($error, $request, $session, null));
            $error = $errorEvent->getError();

            $this->sendResponse($transport, $error, $session);
        }
    }

    /**
     * @param Response<array<string, mixed>>|Error $response
     */
    private function handleResponse(Response|Error $response, SessionInterface $session): void
    {
        $this->logger->info('Handling response from client.', ['message_id' => $response->getId()]);

        $messageId = $response->getId();

        if (null === $messageId) {
            $this->logger->warning('Received an id-less error response from client; cannot correlate it to a pending request.', ['response' => $response->jsonSerialize()]);

            return;
        }

        // Request IDs are ints: a string ID like "1000" would otherwise match through PHP's key coercion.
        $pending = \is_int($messageId) ? $session->get(self::SESSION_PENDING_REQUESTS, [])[$messageId] ?? null : null;
        if (!\is_array($pending) || $this->hasTimedOut($pending)) {
            $this->logger->warning('Received a client response for an unknown or timed out request ID.', ['message_id' => $messageId]);

            return;
        }

        $this->dispatchEvent(new ClientResponseEvent($response, $session));

        $session->set(self::SESSION_RESPONSES.".{$messageId}", $response->jsonSerialize());
        $session->forget(self::SESSION_ACTIVE_REQUEST_META);

        $this->logger->info('Client response stored in session', [
            'message_id' => $messageId,
        ]);
    }

    private function handleNotification(Notification $notification, SessionInterface $session): void
    {
        $this->logger->info('Handling notification.', ['method' => $notification::getMethod()]);

        $event = $this->dispatchEvent(new NotificationEvent($notification, $session));
        $notification = $event->getNotification();

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

        $this->dispatchEvent(new ServerRequestEvent($requestWithId, $timeout, $session));

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
     * Sends a response through the transport, on the exchange that carried its request.
     *
     * Responses never go through the session's outgoing queue: the session is shared by
     * the concurrent requests of a client and written back whole, so a queued response
     * could be overwritten by another request, or taken by it.
     *
     * @param TransportInterface<mixed>                            $transport
     * @param Response<ResultInterface|array<string, mixed>>|Error $response
     * @param array<string, mixed>                                 $context
     */
    private function sendResponse(TransportInterface $transport, Response|Error $response, ?SessionInterface $session, array $context = []): void
    {
        $this->logger->debug('Sending response', [
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
        if (null !== $session) {
            $context['session_id'] = $session->getId();
        }

        $transport->send($encoded, $context);
    }

    /**
     * Helper to queue outgoing messages in session.
     *
     * @param array<string, mixed> $context
     */
    private function queueOutgoing(Request|Notification $message, array $context, SessionInterface $session): void
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
        $session = $this->sessionManager->createWithId($sessionId);
        $queue = $session->get(self::SESSION_OUTGOING_QUEUE, []);

        // Saving an unchanged session would only overwrite what a concurrent request saved in the meantime.
        if ([] !== $queue) {
            $session->set(self::SESSION_OUTGOING_QUEUE, []);
            $session->save();
        }

        return $queue;
    }

    /**
     * Check for a response to a specific request ID.
     *
     * When a response is found, it is removed from the session, and the
     * corresponding pending request is also cleared. A request that got no
     * answer within its timeout is cleared the same way and reported as an
     * internal error, so its pending entry does not outlive the wait.
     *
     * @return Response<array<string, mixed>>|Error|null
     */
    public function checkResponse(int $requestId, Uuid $sessionId): Response|Error|null
    {
        $session = $this->sessionManager->createWithId($sessionId);
        $responseData = $session->get(self::SESSION_RESPONSES.".{$requestId}");

        if (null === $responseData) {
            $pending = $session->get(self::SESSION_PENDING_REQUESTS, [])[$requestId] ?? null;

            return \is_array($pending) && $this->hasTimedOut($pending) ? $this->expireRequest($requestId, $sessionId) : null;
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
     * Drops a timed out request from the pending ones and reports the timeout.
     *
     * The session is loaded again as the caller's copy may be as old as the last
     * poll, and saving it back would undo what other streams stored since.
     */
    private function expireRequest(int $requestId, Uuid $sessionId): ?Error
    {
        $session = $this->sessionManager->createWithId($sessionId);
        $pending = $session->get(self::SESSION_PENDING_REQUESTS, []);

        // Answered or expired by another stream in the meantime: the next poll sorts it out.
        if (!isset($pending[$requestId]) || null !== $session->get(self::SESSION_RESPONSES.".{$requestId}")) {
            return null;
        }

        $this->logger->warning('Client request timed out.', [
            'request_id' => $requestId,
            'session_id' => $sessionId->toRfc4122(),
        ]);

        unset($pending[$requestId]);
        $session->set(self::SESSION_PENDING_REQUESTS, $pending);
        $session->save();

        return Error::forInternalError('Request timed out', $requestId);
    }

    /**
     * @param array<mixed> $pending
     */
    private function hasTimedOut(array $pending): bool
    {
        return time() - (int) ($pending['timestamp'] ?? 0) >= (int) ($pending['timeout'] ?? 120);
    }

    /**
     * Get pending requests for a session.
     *
     * @return array<int, mixed> The pending requests
     */
    public function getPendingRequests(Uuid $sessionId): array
    {
        $session = $this->sessionManager->createWithId($sessionId);

        return $session->get(self::SESSION_PENDING_REQUESTS, []);
    }

    /**
     * Handle values yielded by Fibers during transport-managed resumes.
     *
     * @param FiberSuspend|null $yieldedValue
     *
     * @return int|null the ID of the request sent to the client, which the fiber now waits on
     */
    public function handleFiberYield(mixed $yieldedValue, ?Uuid $sessionId): ?int
    {
        if (!$sessionId) {
            $this->logger->warning('Fiber yielded value without associated session context.');

            return null;
        }

        if (!$yieldedValue instanceof NotificationSuspension && !$yieldedValue instanceof RequestSuspension) {
            $this->logger->warning('Fiber yielded unexpected payload.', [
                'payload' => $yieldedValue,
                'session_id' => $sessionId->toRfc4122(),
            ]);

            return null;
        }

        $session = $this->sessionManager->createWithId($sessionId);

        if ($yieldedValue->sessionId !== $sessionId->toRfc4122()) {
            $this->logger->warning('Fiber yielded payload with mismatched session ID.', [
                'payload_session_id' => $yieldedValue->sessionId,
                'expected_session_id' => $sessionId->toRfc4122(),
            ]);
        }

        try {
            if ($yieldedValue instanceof RequestSuspension) {
                return $this->sendRequest($yieldedValue->request, $yieldedValue->timeout, $session);
            }

            $this->sendNotification($yieldedValue->notification, $session);
        } finally {
            $session->save();
        }

        return null;
    }

    /**
     * @param TransportInterface<mixed> $transport
     */
    private function trackAwaitedRequest(TransportInterface $transport, ?int $requestId): void
    {
        if (null === $requestId) {
            unset($this->awaitedRequestIds[$transport]);

            return;
        }

        $this->awaitedRequestIds[$transport] = $requestId;
    }

    /**
     * @param TransportInterface<mixed>|null $transport
     *
     * @return array<int, mixed>
     */
    private function getAwaitedPendingRequests(?TransportInterface $transport, Uuid $sessionId): array
    {
        $requestId = null !== $transport ? $this->awaitedRequestIds[$transport] ?? null : null;
        if (null === $requestId) {
            return [];
        }

        return array_intersect_key($this->getPendingRequests($sessionId), [$requestId => true]);
    }

    /**
     * @param Response<mixed>|Error $result
     *
     * @return Response<mixed>|Error
     */
    private function dispatchResult(Response|Error $result, Request $request, SessionInterface $session): Response|Error
    {
        // A resumed fiber runs this outside handleRequest()'s catch.
        try {
            if ($result instanceof Response) {
                return $this->dispatchEvent(new ResponseEvent($result, $request, $session))->getResponse();
            }

            return $this->dispatchEvent(new ErrorEvent($result, $request, $session, null))->getError();
        } catch (\Throwable $e) {
            return $this->errorForThrowable($e, $request, $session);
        }
    }

    private function errorForThrowable(\Throwable $e, Request $request, SessionInterface $session): Error
    {
        if ($e instanceof \InvalidArgumentException) {
            $this->logger->warning(\sprintf('Invalid argument: %s', $e->getMessage()), ['exception' => $e]);
            $error = Error::forInvalidParams($e->getMessage(), $request->getId());
        } else {
            $this->logger->error(\sprintf('Uncaught exception: %s', $e->getMessage()), ['exception' => $e]);
            $error = Error::forInternalError(self::INTERNAL_ERROR_MESSAGE, $request->getId());
        }

        try {
            return $this->dispatchEvent(new ErrorEvent($error, $request, $session, $e))->getError();
        } catch (\Throwable $listenerError) {
            // Last resort: the error still has to reach the client.
            $this->logger->error('Error event listener failed.', ['exception' => $listenerError]);

            return $error;
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
     * @param TransportInterface<mixed> $transport
     * @param Uuid|null                 $sessionId The session ID from the transport
     * @param array<int,mixed>          $messages  The parsed messages
     */
    private function resolveSession(TransportInterface $transport, ?Uuid $sessionId, array $messages): ?SessionInterface
    {
        if ($this->hasInitializeRequest($messages)) {
            // Spec: An initialize request must not be part of a batch.
            if (\count($messages) > 1) {
                $error = Error::forInvalidRequest('The "initialize" request MUST NOT be part of a batch.');
                $this->sendResponse($transport, $error, null);

                return null;
            }

            // Spec: An initialize request must not have a session ID.
            if ($sessionId) {
                $error = Error::forInvalidRequest('A session ID MUST NOT be sent with an "initialize" request.');
                $this->sendResponse($transport, $error, null);

                return null;
            }

            $session = $this->sessionManager->create();
            $this->logger->debug('Created new session for initialize', [
                'session_id' => $session->getId()->toRfc4122(),
            ]);

            $transport->setSessionId($session->getId());

            return $session;
        }

        if (!$sessionId) {
            // Echo the id so a client probing with `server/discover` can correlate the refusal.
            $id = match (true) {
                1 !== \count($messages) => null,
                $messages[0] instanceof Request => $messages[0]->getId(),
                $messages[0] instanceof InvalidInputMessageException => $messages[0]->getRequestId(),
                default => null,
            };
            $error = Error::forInvalidRequest('A valid session id is REQUIRED for non-initialize requests.', $id);
            $this->sendResponse($transport, $error, null, ['status_code' => 400]);

            return null;
        }

        if (!$this->sessionManager->exists($sessionId)) {
            $error = Error::forInvalidRequest('Session not found or has expired.');
            $this->sendResponse($transport, $error, null, ['status_code' => 404]);

            return null;
        }

        return $this->sessionManager->createWithId($sessionId);
    }

    /**
     * Destroy a specific session.
     */
    public function destroySession(Uuid $sessionId): void
    {
        $this->sessionManager->destroy($sessionId);
        $this->logger->info('Session destroyed.', ['session_id' => $sessionId->toRfc4122()]);
    }
}
