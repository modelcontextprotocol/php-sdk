<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\JsonRpc;

use Traversable;
use JsonException;
use DomainException;
use InvalidArgumentException;
use Throwable;
use Mcp\Capability\Registry;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\HandlerNotFoundException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\Exception\NotFoundExceptionInterface;
use Mcp\Schema\Implementation;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\MethodHandlerInterface;
use Mcp\Server\NotificationHandler\InitializedHandler;
use Mcp\Server\RequestHandler\CallToolHandler;
use Mcp\Server\RequestHandler\GetPromptHandler;
use Mcp\Server\RequestHandler\InitializeHandler;
use Mcp\Server\RequestHandler\ListPromptsHandler;
use Mcp\Server\RequestHandler\ListResourcesHandler;
use Mcp\Server\RequestHandler\ListToolsHandler;
use Mcp\Server\RequestHandler\PingHandler;
use Mcp\Server\RequestHandler\ReadResourceHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        iterable $methodHandlers,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->methodHandlers = $methodHandlers instanceof Traversable ? iterator_to_array($methodHandlers) : $methodHandlers;
    }

    public static function make(
        Registry $registry,
        Implementation $implementation,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        return new self(
            MessageFactory::make(),
            [
                new InitializedHandler(),
                new InitializeHandler($registry->getCapabilities(), $implementation),
                new PingHandler(),
                new ListPromptsHandler($registry),
                new GetPromptHandler($registry),
                new ListResourcesHandler($registry),
                new ReadResourceHandler($registry),
                new CallToolHandler($registry, $logger),
                new ListToolsHandler($registry),
            ],
            $logger,
        );
    }

    /**
     * @return iterable<string|null>
     *
     * @throws ExceptionInterface When a handler throws an exception during message processing
     * @throws JsonException When JSON encoding of the response fails
     */
    public function process(string $input): iterable
    {
        $this->logger->info('Received message to process.', ['message' => $input]);

        try {
            $messages = $this->messageFactory->create($input);
        } catch (JsonException $e) {
            $this->logger->warning('Failed to decode json message.', ['exception' => $e]);

            yield $this->encodeResponse(Error::forParseError($e->getMessage()));

            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof InvalidInputMessageException) {
                $this->logger->warning('Failed to create message.', ['exception' => $message]);
                yield $this->encodeResponse(Error::forInvalidRequest($message->getMessage(), 0));
                continue;
            }

            $this->logger->debug(\sprintf('Decoded incoming message "%s".', $message::class), [
                'method' => $message->getMethod(),
            ]);

            try {
                yield $this->encodeResponse($this->handle($message));
            } catch (DomainException) {
                yield null;
            } catch (NotFoundExceptionInterface $e) {
                $this->logger->warning(\sprintf('Failed to create response: %s', $e->getMessage()), ['exception' => $e]);

                yield $this->encodeResponse(Error::forMethodNotFound($e->getMessage()));
            } catch (InvalidArgumentException $e) {
                $this->logger->warning(\sprintf('Invalid argument: %s', $e->getMessage()), ['exception' => $e]);

                yield $this->encodeResponse(Error::forInvalidParams($e->getMessage()));
            } catch (Throwable $e) {
                $this->logger->critical(\sprintf('Uncaught exception: %s', $e->getMessage()), ['exception' => $e]);

                yield $this->encodeResponse(Error::forInternalError($e->getMessage()));
            }
        }
    }

    /**
     * @throws JsonException When JSON encoding fails
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
    private function handle(HasMethodInterface $message): Response|Error|null
    {
        $this->logger->info(\sprintf('Handling message for method "%s".', $message::getMethod()), [
            'message' => $message,
        ]);

        $handled = false;
        foreach ($this->methodHandlers as $handler) {
            if ($handler->supports($message)) {
                $return = $handler->handle($message);
                $handled = true;

                $this->logger->debug(\sprintf('Message handled by "%s".', $handler::class), [
                    'method' => $message::getMethod(),
                    'response' => $return,
                ]);

                if (null !== $return) {
                    return $return;
                }
            }
        }

        if ($handled) {
            return null;
        }

        throw new HandlerNotFoundException(\sprintf('No handler found for method "%s".', $message::getMethod()));
    }
}
