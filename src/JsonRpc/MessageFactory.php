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
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\JsonRpc\MessageInterface;
use Mcp\Schema\Notification;
use Mcp\Schema\Request;

/**
 * @phpstan-type RequestData array{
 *      id: string|int,
 *      params?: array<string, mixed>,
 *  }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class MessageFactory
{
    /**
     * Registry of all known messages.
     *
     * @var array<int, class-string<HasMethodInterface>>
     */
    private const REGISTERED_MESSAGES = [
        Notification\CancelledNotification::class,
        Notification\InitializedNotification::class,
        Notification\LoggingMessageNotification::class,
        Notification\ProgressNotification::class,
        Notification\PromptListChangedNotification::class,
        Notification\ResourceListChangedNotification::class,
        Notification\ResourceUpdatedNotification::class,
        Notification\RootsListChangedNotification::class,
        Notification\ToolListChangedNotification::class,
        Request\CallToolRequest::class,
        Request\CompletionCompleteRequest::class,
        Request\CreateSamplingMessageRequest::class,
        Request\GetPromptRequest::class,
        Request\InitializeRequest::class,
        Request\ListPromptsRequest::class,
        Request\ListResourcesRequest::class,
        Request\ListResourceTemplatesRequest::class,
        Request\ListRootsRequest::class,
        Request\ListToolsRequest::class,
        Request\PingRequest::class,
        Request\ReadResourceRequest::class,
        Request\ResourceSubscribeRequest::class,
        Request\ResourceUnsubscribeRequest::class,
        Request\SetLogLevelRequest::class,
    ];

    /**
     * @param array<int, class-string<HasMethodInterface>> $registeredMessages
     */
    public function __construct(
        private readonly array $registeredMessages,
    ) {
        foreach ($this->registeredMessages as $message) {
            if (!is_subclass_of($message, HasMethodInterface::class)) {
                throw new InvalidArgumentException(\sprintf('Message classes must implement %s.', HasMethodInterface::class));
            }
        }
    }

    /**
     * Creates a new Factory instance with the all the protocol's default notifications and requests.
     */
    public static function make(): self
    {
        return new self(self::REGISTERED_MESSAGES);
    }

    /**
     * @return iterable<HasMethodInterface|InvalidInputMessageException>
     *
     * @throws \JsonException When the input string is not valid JSON
     */
    public function create(string $input): iterable
    {
        $data = json_decode($input, true, flags: \JSON_THROW_ON_ERROR);

        if ('{' === $input[0]) {
            $data = [$data];
        }

        foreach ($data as $message) {
            if (!isset($message['method']) || !\is_string($message['method'])) {
                yield new InvalidInputMessageException('Invalid JSON-RPC request, missing valid "method".');
                continue;
            }

            try {
                yield $this->getType($message['method'])::fromArray($message);
            } catch (InvalidInputMessageException $e) {
                yield $e;
                continue;
            }
        }
    }

    /**
     * Creates a message by its type and parameters.
     *
     * @template T of value-of<self::REGISTERED_MESSAGES>
     *
     * @param class-string<T> $messageType
     * @param RequestData     $data
     *
     * @phpstan-return T
     */
    public function createByType(string $messageType, array $data): HasMethodInterface
    {
        if (\in_array($messageType, $this->registeredMessages, true)) {
            $data['jsonrpc'] = MessageInterface::JSONRPC_VERSION;
            $data['method'] = $messageType::getMethod();
            return $messageType::fromArray($data);
        }

        throw new InvalidArgumentException(\sprintf('Message type "%s" is not registered.', $messageType));
    }

    /**
     * @return class-string<HasMethodInterface>
     */
    private function getType(string $method): string
    {
        foreach (self::REGISTERED_MESSAGES as $type) {
            if ($type::getMethod() === $method) {
                return $type;
            }
        }

        throw new InvalidInputMessageException(\sprintf('Invalid JSON-RPC request, unknown method "%s".', $method));
    }
}
