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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\Schema;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;

/**
 * Factory for creating JSON-RPC message objects from raw input.
 *
 * Handles all types of JSON-RPC messages:
 * - Requests (have method + id)
 * - Notifications (have method, no id)
 * - Responses (have result + id)
 * - Errors (have error + id)
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class MessageFactory
{
    /**
     * Registry of all known message classes that have methods.
     *
     * @var array<int, class-string<Request|Notification>>
     */
    private const REGISTERED_MESSAGES = [
        Schema\Notification\CancelledNotification::class,
        Schema\Notification\InitializedNotification::class,
        Schema\Notification\LoggingMessageNotification::class,
        Schema\Notification\ProgressNotification::class,
        Schema\Notification\PromptListChangedNotification::class,
        Schema\Notification\ResourceListChangedNotification::class,
        Schema\Notification\ResourceUpdatedNotification::class,
        Schema\Notification\RootsListChangedNotification::class,
        Schema\Notification\ToolListChangedNotification::class,

        Schema\Request\CallToolRequest::class,
        Schema\Request\CompletionCompleteRequest::class,
        Schema\Request\CreateSamplingMessageRequest::class,
        Schema\Request\GetPromptRequest::class,
        Schema\Request\InitializeRequest::class,
        Schema\Request\ListPromptsRequest::class,
        Schema\Request\ListResourcesRequest::class,
        Schema\Request\ListResourceTemplatesRequest::class,
        Schema\Request\ListRootsRequest::class,
        Schema\Request\ListToolsRequest::class,
        Schema\Request\PingRequest::class,
        Schema\Request\ReadResourceRequest::class,
        Schema\Request\ResourceSubscribeRequest::class,
        Schema\Request\ResourceUnsubscribeRequest::class,
        Schema\Request\SetLogLevelRequest::class,
    ];

    /**
     * @param array<int, class-string<Request|Notification>> $registeredMessages
     */
    public function __construct(
        private readonly array $registeredMessages,
    ) {
        foreach ($this->registeredMessages as $messageClass) {
            if (!is_subclass_of($messageClass, Request::class) && !is_subclass_of($messageClass, Notification::class)) {
                throw new InvalidArgumentException(\sprintf('Message classes must extend %s or %s.', Request::class, Notification::class));
            }
        }
    }

    /**
     * Creates a new Factory instance with all the protocol's default messages.
     */
    public static function make(): self
    {
        return new self(self::REGISTERED_MESSAGES);
    }

    /**
     * Creates message objects from JSON input.
     *
     * Supports both single messages and batch requests. Returns an array containing
     * MessageInterface objects or InvalidInputMessageException instances for invalid messages.
     *
     * @return array<MessageInterface|InvalidInputMessageException>
     *
     * @throws \JsonException When the input string is not valid JSON
     */
    public function create(string $input): array
    {
        $data = json_decode($input, true, flags: \JSON_THROW_ON_ERROR);

        if ('{' === $input[0]) {
            $data = [$data];
        }

        $messages = [];
        foreach ($data as $message) {
            try {
                $messages[] = $this->createMessage($message);
            } catch (InvalidInputMessageException $e) {
                $messages[] = $e;
            }
        }

        return $messages;
    }

    /**
     * Creates a single message object from parsed JSON data.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputMessageException
     */
    private function createMessage(array $data): MessageInterface
    {
        try {
            if (isset($data['error'])) {
                return Error::fromArray($data);
            }

            if (isset($data['result'])) {
                return Response::fromArray($data);
            }

            if (!isset($data['method'])) {
                throw new InvalidInputMessageException('Invalid JSON-RPC message: missing "method", "result", or "error" field.');
            }

            $messageClass = $this->findMessageClassByMethod($data['method']);

            return $messageClass::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new InvalidInputMessageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Finds the registered message class for a given method name.
     *
     * @return class-string<Request|Notification>
     *
     * @throws InvalidInputMessageException
     */
    private function findMessageClassByMethod(string $method): string
    {
        foreach ($this->registeredMessages as $messageClass) {
            if ($messageClass::getMethod() === $method) {
                return $messageClass;
            }
        }

        throw new InvalidInputMessageException(\sprintf('Unknown method "%s".', $method));
    }
}
