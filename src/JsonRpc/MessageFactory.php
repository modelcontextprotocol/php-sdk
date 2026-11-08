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
     * @var list<class-string<Request>|class-string<Notification>>
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
        Schema\Request\ElicitRequest::class,
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
     * @param list<class-string<Request>|class-string<Notification>> $registeredMessages
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
     *
     * @param list<class-string<Request>|class-string<Notification>> $additional message classes an extension defines
     */
    public static function make(array $additional = []): self
    {
        return new self([...self::REGISTERED_MESSAGES, ...$additional]);
    }

    /**
     * Creates a message object from JSON input.
     *
     * @throws \JsonException               When the input string is not valid JSON
     * @throws InvalidInputMessageException When the input is not a valid message
     */
    public function create(string $input): MessageInterface
    {
        try {
            $data = json_decode($input, false, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if (\JSON_ERROR_INVALID_PROPERTY_NAME !== $e->getCode()) {
                throw $e;
            }

            throw self::invalidMemberName($input);
        }

        if (!$data instanceof \stdClass) {
            throw new InvalidInputMessageException('A JSON-RPC message must be a single JSON object.');
        }

        try {
            return $this->createMessage($data);
        } catch (InvalidInputMessageException $e) {
            $id = $data->id ?? null;
            if (\is_string($id) || \is_int($id)) {
                $e->setRequestId($id);
            }

            throw $e;
        }
    }

    /**
     * Valid JSON, but a PHP object cannot hold a property whose name starts with NUL.
     */
    private static function invalidMemberName(string $input): InvalidInputMessageException
    {
        $exception = new InvalidInputMessageException('A JSON-RPC message must not have a member name starting with a NUL byte.');
        $id = json_decode($input, true)['id'] ?? null;

        if (\is_string($id) || \is_int($id)) {
            $exception->setRequestId($id);
        }

        return $exception;
    }

    /**
     * Creates a single message object from parsed JSON data.
     *
     * @throws InvalidInputMessageException
     */
    private function createMessage(\stdClass $message): MessageInterface
    {
        /** @var array<string, mixed> $data */
        $data = Json::objectsToArrays($message);

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

            if (!\is_string($data['method'])) {
                throw new InvalidInputMessageException('Invalid JSON-RPC message: "method" must be a string.');
            }

            $messageClass = $this->findMessageClassByMethod($data['method']);

            // Each message knows which of its params are free-form JSON, so it gets them as decoded.
            if (isset($message->params)) {
                $data['params'] = $message->params;
            }

            return $messageClass::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new InvalidInputMessageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Finds the registered message class for a given method name.
     *
     * @return class-string<Request>|class-string<Notification>
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
