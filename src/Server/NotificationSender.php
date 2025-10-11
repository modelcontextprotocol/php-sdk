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

use Mcp\Exception\RuntimeException;
use Mcp\Server\Handler\NotificationHandler;
use Mcp\Server\Transport\TransportInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service responsible for sending notifications to the client.
 *
 * This class handles the transport of notifications by coordinating between
 * the NotificationHandler (for creation/serialization) and TransportInterface
 * (for actual transmission).
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class NotificationSender
{
    /**
     * @param TransportInterface<mixed>|null $transport
     */
    public function __construct(
        private readonly NotificationHandler $notificationHandler,
        private ?TransportInterface $transport = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Sets the transport interface for sending notifications.
     *
     * @param TransportInterface<mixed> $transport
     */
    public function setTransport(TransportInterface $transport): void
    {
        $this->transport = $transport;
    }

    /**
     * Sends a notification to the client.
     *
     * @param string               $method The notification method
     * @param array<string, mixed> $params Parameters for the notification
     *
     * @throws RuntimeException If no transport is available
     */
    public function send(string $method, array $params): void
    {
        $this->ensureTransportAvailable();

        try {
            $encodedNotification = $this->notificationHandler->process($method, $params);

            if (null !== $encodedNotification) {
                $this->transport->send($encodedNotification, []);
                $this->logger->debug('Notification sent successfully', [
                    'method' => $method,
                    'transport' => $this->transport::class,
                ]);
            } else {
                $this->logger->warning('Failed to create notification', [
                    'method' => $method,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send notification', [
                'method' => $method,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            // Re-throw as RuntimeException to maintain API contract
            throw new RuntimeException("Failed to send notification: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Ensures transport is available before attempting operations.
     *
     * @throws RuntimeException If no transport is available
     */
    private function ensureTransportAvailable(): void
    {
        if (null === $this->transport) {
            throw new RuntimeException('No transport configured for notification sending');
        }
    }
}
