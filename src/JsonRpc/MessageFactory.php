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
     * Registry of all known notification classes.
     *
     * @var array<int, class-string<Notification>>
     */
    private const REGISTERED_NOTIFICATIONS = [
        Schema\Notification\CancelledNotification::class,
        Schema\Notification\InitializedNotification::class,
        Schema\Notification\LoggingMessageNotification::class,
        Schema\Notification\ProgressNotification::class,
        Schema\Notification\PromptListChangedNotification::class,
        Schema\Notification\ResourceListChangedNotification::class,
        Schema\Notification\ResourceUpdatedNotification::class,
        Schema\Notification\RootsListChangedNotification::class,
        Schema\Notification\ToolListChangedNotification::class,
    ];

    /**
     * Registry of all known request classes.
     *
     * @var array<int, class-string<Request>>
     */
    private const REGISTERED_REQUESTS = [
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
     * @param array<int, class-string<Notification>> $registeredNotifications
     * @param array<int, class-string<Request>>      $registeredRequests
     */
    public function __construct(
        private readonly array $registeredNotifications,
        private readonly array $registeredRequests,
    ) {
        foreach ($this->registeredNotifications as $notification) {
            if (!is_subclass_of($notification, Notification::class)) {
                throw new InvalidArgumentException(\sprintf('Notification classes must extend %s.', Notification::class));
            }
        }

        foreach ($this->registeredRequests as $request) {
            if (!is_subclass_of($request, Request::class)) {
                throw new InvalidArgumentException(\sprintf('Request classes must extend %s.', Request::class));
            }
        }
    }

    /**
     * Creates a new Factory instance with all the protocol's default notifications and requests.
     */
    public static function make(): self
    {
        return new self(self::REGISTERED_NOTIFICATIONS, self::REGISTERED_REQUESTS);
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
        if (!isset($data['jsonrpc']) || MessageInterface::JSONRPC_VERSION !== $data['jsonrpc']) {
            throw new InvalidInputMessageException('Invalid or missing "jsonrpc" version.');
        }

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

            return isset($data['id']) ? $this->createRequest($data) : $this->createNotification($data);
        } catch (InvalidArgumentException $e) {
            throw new InvalidInputMessageException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Creates a Request object by looking up the appropriate class by method name.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputMessageException
     */
    private function createRequest(array $data): Request
    {
        if (!\is_string($data['method'])) {
            throw new InvalidInputMessageException('Request "method" must be a string.');
        }

        $messageClass = $this->findRequestClassByMethod($data['method']);

        return $messageClass::fromArray($data);
    }

    /**
     * Creates a Notification object by looking up the appropriate class by method name.
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidInputMessageException
     */
    private function createNotification(array $data): Notification
    {
        if (!\is_string($data['method'])) {
            throw new InvalidInputMessageException('Notification "method" must be a string.');
        }

        $messageClass = $this->findNotificationClassByMethod($data['method']);

        return $messageClass::fromArray($data);
    }

    /**
     * Finds the registered request class for a given method name.
     *
     * @return class-string<Request>
     *
     * @throws InvalidInputMessageException
     */
    private function findRequestClassByMethod(string $method): string
    {
        foreach ($this->registeredRequests as $requestClass) {
            if ($requestClass::getMethod() === $method) {
                return $requestClass;
            }
        }

        throw new InvalidInputMessageException(\sprintf('Unknown request method "%s".', $method));
    }

    /**
     * Finds the registered notification class for a given method name.
     *
     * @return class-string<Notification>
     *
     * @throws InvalidInputMessageException
     */
    private function findNotificationClassByMethod(string $method): string
    {
        foreach ($this->registeredNotifications as $notificationClass) {
            if ($notificationClass::getMethod() === $method) {
                return $notificationClass;
            }
        }

        throw new InvalidInputMessageException(\sprintf('Unknown notification method "%s".', $method));
    }
}
