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

use JsonException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\InvalidInputMessageException;
use Mcp\Schema\JsonRpc\HasMethodInterface;
use Mcp\Schema\Notification\CancelledNotification;
use Mcp\Schema\Notification\InitializedNotification;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Schema\Notification\ProgressNotification;
use Mcp\Schema\Notification\PromptListChangedNotification;
use Mcp\Schema\Notification\ResourceListChangedNotification;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Notification\RootsListChangedNotification;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Request\CreateSamplingMessageRequest;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListResourcesRequest;
use Mcp\Schema\Request\ListResourceTemplatesRequest;
use Mcp\Schema\Request\ListRootsRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Request\PingRequest;
use Mcp\Schema\Request\ReadResourceRequest;
use Mcp\Schema\Request\ResourceSubscribeRequest;
use Mcp\Schema\Request\ResourceUnsubscribeRequest;
use Mcp\Schema\Request\SetLogLevelRequest;

/**
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
        CancelledNotification::class,
        InitializedNotification::class,
        LoggingMessageNotification::class,
        ProgressNotification::class,
        PromptListChangedNotification::class,
        ResourceListChangedNotification::class,
        ResourceUpdatedNotification::class,
        RootsListChangedNotification::class,
        ToolListChangedNotification::class,
        CallToolRequest::class,
        CompletionCompleteRequest::class,
        CreateSamplingMessageRequest::class,
        GetPromptRequest::class,
        InitializeRequest::class,
        ListPromptsRequest::class,
        ListResourcesRequest::class,
        ListResourceTemplatesRequest::class,
        ListRootsRequest::class,
        ListToolsRequest::class,
        PingRequest::class,
        ReadResourceRequest::class,
        ResourceSubscribeRequest::class,
        ResourceUnsubscribeRequest::class,
        SetLogLevelRequest::class,
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
     * @throws JsonException When the input string is not valid JSON
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
