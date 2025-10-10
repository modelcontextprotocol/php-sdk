<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler;

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Exception\HandlerNotFoundException;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Server\Handler\Notification\LoggingMessageNotificationHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Main notification handler that routes notification creation to specific handlers.
 *
 * This handler manages multiple notification handlers and routes notification
 * creation requests to the appropriate handler based on the method.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class NotificationHandler
{
    /**
     * @var array<string, NotificationHandlerInterface>
     */
    private readonly array $handlers;

    /**
     * @param array<string, NotificationHandlerInterface> $handlers Method-to-handler mapping
     */
    public function __construct(
        array $handlers,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->handlers = $handlers;
    }

    /**
     * Creates a NotificationHandler with default handlers.
     */
    public static function make(
        ReferenceRegistryInterface $registry,
        LoggerInterface $logger = new NullLogger(),
    ): self {
        return new self(
            handlers: [
                LoggingMessageNotification::getMethod() => new LoggingMessageNotificationHandler($registry, $logger),
            ],
            logger: $logger,
        );
    }

    /**
     * Processes a notification creation request.
     *
     * @param string               $method The notification method
     * @param array<string, mixed> $params Parameters for the notification
     *
     * @return string|null The serialized JSON notification, or null on failure
     *
     * @throws HandlerNotFoundException When no handler supports the method
     */
    public function process(string $method, array $params): ?string
    {
        $context = ['method' => $method, 'params' => $params];
        $this->logger->debug("Processing notification for method: {$method}", $context);

        $handler = $this->getHandlerFor($method);

        return $this->createAndEncodeNotification($handler, $method, $params);
    }

    /**
     * Gets the handler for a specific method.
     *
     * @throws HandlerNotFoundException When no handler supports the method
     */
    private function getHandlerFor(string $method): NotificationHandlerInterface
    {
        $handler = $this->handlers[$method] ?? null;

        if (!$handler) {
            throw new HandlerNotFoundException("No notification handler found for method: {$method}");
        }

        return $handler;
    }

    /**
     * Creates notification using handler and encodes it to JSON.
     *
     * @param array<string, mixed> $params
     */
    private function createAndEncodeNotification(
        NotificationHandlerInterface $handler,
        string $method,
        array $params,
    ): ?string {
        try {
            $notification = $handler->handle($method, $params);

            $this->logger->debug('Notification created successfully', [
                'method' => $method,
                'handler' => $handler::class,
                'notification_class' => $notification::class,
            ]);

            return $this->encodeNotification($notification);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create notification', [
                'method' => $method,
                'handler' => $handler::class,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * Encodes a notification to JSON, handling encoding errors gracefully.
     */
    private function encodeNotification(Notification $notification): ?string
    {
        $method = $notification->getMethod();

        $this->logger->debug('Encoding notification', [
            'method' => $method,
            'notification_class' => $notification::class,
        ]);

        try {
            return json_encode($notification, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('JSON encoding failed for notification', [
                'method' => $method,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }
}
