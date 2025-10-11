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
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Protocol
{
    /** @var TransportInterface<mixed>|null */
    private ?TransportInterface $transport = null;

    /**
     * @param array<int, RequestHandlerInterface>      $requestHandlers
     * @param array<int, NotificationHandlerInterface> $notificationHandlers
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
            $this->sendResponse($error, ['session_id' => $sessionId]);

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

    private function handleInvalidMessage(InvalidInputMessageException $message, SessionInterface $session): void
    {
        $this->logger->warning('Failed to create message.', ['exception' => $message]);

        $error = Error::forInvalidRequest($message->getMessage());
        $this->sendResponse($error, ['session_id' => $session->getId()]);
    }

    private function handleRequest(Request $request, SessionInterface $session): void
    {
        $this->logger->info('Handling request.', ['request' => $request]);

        $handlerFound = false;

        foreach ($this->requestHandlers as $handler) {
            if (!$handler->supports($request)) {
                continue;
            }

            $handlerFound = true;

            try {
                $response = $handler->handle($request, $session);
                $this->sendResponse($response, ['session_id' => $session->getId()]);
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning(\sprintf('Invalid argument: %s', $e->getMessage()), ['exception' => $e]);
                $error = Error::forInvalidParams($e->getMessage(), $request->getId());
                $this->sendResponse($error, ['session_id' => $session->getId()]);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Uncaught exception: %s', $e->getMessage()), ['exception' => $e]);
                $error = Error::forInternalError($e->getMessage(), $request->getId());
                $this->sendResponse($error, ['session_id' => $session->getId()]);
            }

            break;
        }

        if (!$handlerFound) {
            $error = Error::forMethodNotFound(\sprintf('No handler found for method "%s".', $request::getMethod()), $request->getId());
            $this->sendResponse($error, ['session_id' => $session->getId()]);
        }
    }

    private function handleResponse(Response|Error $response, SessionInterface $session): void
    {
        $this->logger->info('Handling response.', ['response' => $response]);
        // TODO: Implement response handling
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
     * @param array<string, mixed> $context
     */
    public function sendRequest(Request $request, array $context = []): void
    {
        $this->logger->info('Sending request.', ['request' => $request, 'context' => $context]);
        // TODO: Implement request sending
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sendResponse(Response|Error $response, array $context = []): void
    {
        $this->logger->info('Sending response.', ['response' => $response, 'context' => $context]);

        $encoded = null;

        try {
            if ($response instanceof Response && [] === $response->result) {
                $encoded = json_encode($response, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT);
            }

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
    }

    /**
     * @param array<string, mixed> $context
     */
    public function sendNotification(Notification $notification, array $context = []): void
    {
        $this->logger->info('Sending notification.', ['notification' => $notification, 'context' => $context]);
        $context['type'] = 'notification';
        // TODO: Implement notification sending
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
                $this->sendResponse($error, ['session_id' => $sessionId]);

                return null;
            }

            // Spec: An initialize request must not have a session ID.
            if ($sessionId) {
                $error = Error::forInvalidRequest('A session ID MUST NOT be sent with an "initialize" request.');
                $this->sendResponse($error);

                return null;
            }

            return $this->sessionFactory->create($this->sessionStore);
        }

        if (!$sessionId) {
            $error = Error::forInvalidRequest('A valid session id is REQUIRED for non-initialize requests.');
            $this->sendResponse($error, ['status_code' => 400]);

            return null;
        }

        if (!$this->sessionStore->exists($sessionId)) {
            $error = Error::forInvalidRequest('Session not found or has expired.');
            $this->sendResponse($error, ['status_code' => 404]);

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
