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
use Mcp\Client\Session\ClientSession;
use Mcp\Client\Session\ClientSessionInterface;
use Mcp\Client\Transport\TransportInterface;
use Mcp\JsonRpc\MessageFactory;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
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
    private ?TransportInterface $transport = null;
    private ClientSessionInterface $session;
    private MessageFactory $messageFactory;
    private LoggerInterface $logger;

    /** @var NotificationHandlerInterface[] */
    private array $notificationHandlers;

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
        $this->session = new ClientSession();
        $this->messageFactory = $messageFactory ?? MessageFactory::make();
        $this->logger = $logger ?? new NullLogger();

        $this->notificationHandlers = [
            new ProgressNotificationHandler($this->session),
            ...$notificationHandlers,
        ];
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
        $transport->setSession($this->session);
        $transport->onInitialize(fn () => $this->initialize($config));
        $transport->onMessage($this->processMessage(...));
        $transport->onError(fn (\Throwable $e) => $this->logger->error('Transport error', ['exception' => $e]));

        $this->logger->info('Protocol connected to transport', ['transport' => $transport::class]);
    }

    /**
     * Perform the MCP initialization handshake.
     *
     * Sends InitializeRequest and waits for response, then sends InitializedNotification.
     *
     * @param Configuration $config The client configuration
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function initialize(Configuration $config): Response|Error
    {
        $request = new InitializeRequest(
            $config->protocolVersion->value,
            $config->capabilities,
            $config->clientInfo,
        );

        $response = $this->request($request, $config->initTimeout);

        if ($response instanceof Response) {
            $initResult = InitializeResult::fromArray($response->result);
            $this->session->setServerInfo($initResult->serverInfo);
            $this->session->setInstructions($initResult->instructions);
            $this->session->setInitialized(true);

            $this->sendNotification(new InitializedNotification());

            $this->logger->info('Initialization complete', [
                'server' => $initResult->serverInfo->name,
            ]);
        }

        return $response;
    }

    /**
     * Send a request to the server.
     *
     * If a response is immediately available (sync HTTP), returns it.
     * Otherwise, suspends the Fiber and waits for the transport to resume it.
     *
     * @param Request $request      The request to send
     * @param int     $timeout      The timeout in seconds
     * @param bool    $withProgress Whether to attach a progress token to the request
     *
     * @return Response<array<string, mixed>>|Error
     */
    public function request(Request $request, int $timeout, bool $withProgress = false): Response|Error
    {
        $requestId = $this->session->nextRequestId();
        $request = $request->withId($requestId);

        if ($withProgress) {
            $progressToken = "prog-{$requestId}";
            $request = $request->withMeta(['progressToken' => $progressToken]);
        }

        $this->logger->debug('Sending request', [
            'id' => $requestId,
            'method' => $request::getMethod(),
        ]);

        $encoded = json_encode($request, \JSON_THROW_ON_ERROR);
        $this->session->queueOutgoing($encoded, ['type' => 'request']);
        $this->session->addPendingRequest($requestId, $timeout);

        $this->flushOutgoing();

        $immediate = $this->session->consumeResponse($requestId);
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
    }

    /**
     * Send a notification to the server (fire and forget).
     */
    public function sendNotification(Notification $notification): void
    {
        $this->logger->debug('Sending notification', ['method' => $notification::getMethod()]);

        $encoded = json_encode($notification, \JSON_THROW_ON_ERROR);
        $this->session->queueOutgoing($encoded, ['type' => 'notification']);
        $this->flushOutgoing();
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
                $this->handleServerRequest($message);
            } elseif ($message instanceof Notification) {
                $this->handleServerNotification($message);
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

        $this->logger->debug('Handling response', ['id' => $requestId]);

        if ($response instanceof Response) {
            $this->session->storeResponse($requestId, $response->jsonSerialize());
        } else {
            $this->session->storeResponse($requestId, $response->jsonSerialize());
        }
    }

    /**
     * Handle a request from the server (e.g., sampling request).
     */
    private function handleServerRequest(Request $request): void
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

                $encoded = json_encode($response, \JSON_THROW_ON_ERROR);
                $this->session->queueOutgoing($encoded, ['type' => $response instanceof Response ? 'response' : 'error']);
                $this->flushOutgoing();

                return;
            }
        }

        $error = Error::forMethodNotFound(
            \sprintf('Client does not handle "%s" requests.', $method),
            $request->getId()
        );

        $encoded = json_encode($error, \JSON_THROW_ON_ERROR);
        $this->session->queueOutgoing($encoded, ['type' => 'error']);
        $this->flushOutgoing();
    }

    /**
     * Handle a notification from the server.
     */
    private function handleServerNotification(Notification $notification): void
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

    /**
     * Flush any queued outgoing messages.
     */
    private function flushOutgoing(): void
    {
        if (null === $this->transport) {
            return;
        }

        $messages = $this->session->consumeOutgoingMessages();
        foreach ($messages as $item) {
            $this->transport->send($item['message'], $item['context']);
        }
    }

    public function getSession(): ClientSessionInterface
    {
        return $this->session;
    }
}
