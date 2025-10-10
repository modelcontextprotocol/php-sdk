<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Handler\Notification;

use Mcp\Capability\Registry\ReferenceRegistryInterface;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Schema\JsonRpc\Notification;
use Mcp\Schema\Notification\LoggingMessageNotification;
use Mcp\Server\Handler\NotificationHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for creating logging message notifications.
 *
 * Creates LoggingMessageNotification instances that can be sent to clients
 * to provide structured log messages according to the MCP specification.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class LoggingMessageNotificationHandler implements NotificationHandlerInterface
{
    public function __construct(
        private readonly ReferenceRegistryInterface $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(string $method, array $params): Notification
    {
        if (!$this->supports($method)) {
            throw new InvalidArgumentException("Handler does not support method: {$method}");
        }

        $this->validateRequiredParameter($params);

        $level = $this->getLoggingLevel($params);

        if (!$this->registry->isLoggingEnabled()) {
            $this->logger->debug('Logging is disabled, skipping log message');
            throw new InvalidArgumentException('Logging capability is not enabled');
        }

        $this->validateLogLevelThreshold($level);

        return new LoggingMessageNotification(
            level: $level,
            data: $params['data'],
            logger: $params['logger'] ?? null
        );
    }

    private function supports(string $method): bool
    {
        return $method === LoggingMessageNotification::getMethod();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateRequiredParameter(array $params): void
    {
        if (!isset($params['level'])) {
            throw new InvalidArgumentException('Missing required parameter "level" for logging notification');
        }

        if (!isset($params['data'])) {
            throw new InvalidArgumentException('Missing required parameter "data" for logging notification');
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getLoggingLevel(array $params): LoggingLevel
    {
        return $params['level'] instanceof LoggingLevel
            ? $params['level']
            : LoggingLevel::from($params['level']);
    }

    private function validateLogLevelThreshold(LoggingLevel $level): void
    {
        $currentLogLevel = $this->registry->getLoggingLevel();

        if ($this->shouldSendLogLevel($level, $currentLogLevel)) {
            return;
        }

        $this->logger->debug(
            "Log level {$level->value} is below current threshold {$currentLogLevel->value}, skipping"
        );
        throw new InvalidArgumentException('Log level is below current threshold');
    }

    private function shouldSendLogLevel(LoggingLevel $messageLevel, LoggingLevel $currentLevel): bool
    {
        return $messageLevel->getSeverityIndex() >= $currentLevel->getSeverityIndex();
    }
}
