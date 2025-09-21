<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\JsonRpc;

use Mcp\Capability\Prompt\PromptGetterInterface;
use Mcp\Capability\Registry\ReferenceProviderInterface;
use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Capability\Resource\ResourceReaderInterface;
use Mcp\Capability\Tool\ToolCallerInterface;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\HandlerNotFoundException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\Exception\NotFoundExceptionInterface;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\NotificationHandler;
use Mcp\Server\RequestHandler;
use Mcp\Server\Session\SessionFactoryInterface;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

/**
 * @final
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class Handler
{
    /**
     * @var array<int, MethodHandlerInterface>
     */
    private readonly array $methodHandlers;

    /**
     * @param iterable<int, MethodHandlerInterface> $methodHandlers
     */
    public function __construct(
        private readonly MessageFactory $messageFactory,
        private readonly SessionFactoryInterface $sessionFactory,
        private readonly SessionStoreInterface $sessionStore,
        iterable $methodHandlers,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->methodHandlers = $methodHandlers instanceof \Traversable ? iterator_to_array(
            $methodHandlers,
        ) : $methodHandlers;
    }

    public static function make(
        ReferenceRegistryInterface $registry,
        ReferenceProviderInterface $referenceProvider,
        Implementation $implementation,
        ToolCallerInterface $toolCaller,
        ResourceReaderInterface $resourceReader,
        PromptGetterInterface $promptGetter,
        SessionStoreInterface $sessionStore,
        SessionFactoryInterface $sessionFactory,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        return new self(
            messageFactory: MessageFactory::make(),
            sessionFactory: $sessionFactory,
            sessionStore: $sessionStore,
            methodHandlers: [
                new NotificationHandler\InitializedHandler(),
                new RequestHandler\InitializeHandler($registry->getCapabilities(), $implementation),
                new RequestHandler\PingHandler(),
                new RequestHandler\ListPromptsHandler($referenceProvider),
                new RequestHandler\GetPromptHandler($promptGetter),
                new RequestHandler\ListResourcesHandler($referenceProvider),
                new RequestHandler\ReadResourceHandler($resourceReader),
                new RequestHandler\CallToolHandler($toolCaller, $logger),
                new RequestHandler\ListToolsHandler($referenceProvider),
            ],
            logger: $logger,
        );
    }

    /**
     * @return iterable<array{string|null, array<string, mixed>}>
     *
     * @throws ExceptionInterface When a handler throws an exception during message processing
     * @throws \JsonException     When JSON encoding of the response fails
     */
    public function process(string $input, ?Uuid $sessionId): iterable
    {
        $this->logger->info('Received message to process.', ['message' => $input]);

        $this->runGarbageCollection();

        try {
            $messages = iterator_to_array($this->messageFactory->create($input));
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to decode json message.', ['exception' => $e]);
            $error = Error::forParseError($e->getMessage());
            yield [$this->encodeResponse($error), []];

            return;
        }

        $hasInitializeRequest = false;
        foreach ($messages as $message) {
            if ($message instanceof InitializeRequest) {
                $hasInitializeRequest = true;
                break;
            }
        }

        $session = null;

        if ($hasInitializeRequest) {
            // Spec: An initialize request must not be part of a batch.
            if (\count($messages) > 1) {
                $error = Error::forInvalidRequest('The "initialize" request MUST NOT be part of a batch.');
                yield [$this->encodeResponse($error), []];

                return;
            }

            // Spec: An initialize request must not have a session ID.
            if ($sessionId) {
                $error = Error::forInvalidRequest('A session ID MUST NOT be sent with an "initialize" request.');
                yield [$this->encodeResponse($error), []];

                return;
            }

            $session = $this->sessionFactory->create($this->sessionStore);
        } else {
            if (!$sessionId) {
                $error = Error::forInvalidRequest('A valid session id is REQUIRED for non-initialize requests.');
                yield [$this->encodeResponse($error), ['status_code' => 400]];

                return;
            }

            if (!$this->sessionStore->exists($sessionId)) {
                $error = Error::forInvalidRequest('Session not found or has expired.');
                yield [$this->encodeResponse($error), ['status_code' => 404]];

                return;
            }

            $session = $this->sessionFactory->createWithId($sessionId, $this->sessionStore);
        }

        foreach ($messages as $message) {
            if ($message instanceof InvalidInputMessageException) {
                $this->logger->warning('Failed to create message.', ['exception' => $message]);
                $error = Error::forInvalidRequest($message->getMessage(), 0);
                yield [$this->encodeResponse($error), []];
                continue;
            }

            $this->logger->debug(\sprintf('Decoded incoming message "%s".', $message::class), [
                'method' => $message->getMethod(),
            ]);

            try {
                $response = $this->handle($message, $session);
                yield [$this->encodeResponse($response), ['session_id' => $session->getId()]];
            } catch (\DomainException) {
                yield [null, []];
            } catch (NotFoundExceptionInterface $e) {
                $this->logger->warning(
                    \sprintf('Failed to create response: %s', $e->getMessage()),
                    ['exception' => $e],
                );

                $error = Error::forMethodNotFound($e->getMessage());
                yield [$this->encodeResponse($error), []];
            } catch (\InvalidArgumentException $e) {
                $this->logger->warning(\sprintf('Invalid argument: %s', $e->getMessage()), ['exception' => $e]);

                $error = Error::forInvalidParams($e->getMessage());
                yield [$this->encodeResponse($error), []];
            } catch (\Throwable $e) {
                $this->logger->critical(\sprintf('Uncaught exception: %s', $e->getMessage()), ['exception' => $e]);

                $error = Error::forInternalError($e->getMessage());
                yield [$this->encodeResponse($error), []];
            }
        }

        $session->save();
    }

    /**
     * @throws \JsonException When JSON encoding fails
     */
    private function encodeResponse(Response|Error|null $response): ?string
    {
        if (null === $response) {
            $this->logger->info('The handler created an empty response.');

            return null;
        }

        $this->logger->info('Encoding response.', ['response' => $response]);

        if ($response instanceof Response && [] === $response->result) {
            return json_encode($response, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT);
        }

        return json_encode($response, \JSON_THROW_ON_ERROR);
    }

    /**
     * If the handler does support the message, but does not create a response, other handlers will be tried.
     *
     * @throws NotFoundExceptionInterface When no handler is found for the request method
     * @throws ExceptionInterface         When a request handler throws an exception
     */
    private function handle(HasMethodInterface $message, SessionInterface $session): Response|Error|null
    {
        $this->logger->info(\sprintf('Handling message for method "%s".', $message::getMethod()), [
            'message' => $message,
        ]);

        $handled = false;
        foreach ($this->methodHandlers as $handler) {
            if (!$handler->supports($message)) {
                continue;
            }

            $return = $handler->handle($message, $session);
            $handled = true;

            $this->logger->debug(\sprintf('Message handled by "%s".', $handler::class), [
                'method' => $message::getMethod(),
                'response' => $return,
            ]);

            if (null !== $return) {
                return $return;
            }
        }

        if ($handled) {
            return null;
        }

        throw new HandlerNotFoundException(\sprintf('No handler found for method "%s".', $message::getMethod()));
    }

    /**
     * Run garbage collection on expired sessions.
     * Uses the session store's internal TTL configuration.
     */
    private function runGarbageCollection(): void
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
