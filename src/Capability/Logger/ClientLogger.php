<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Logger;

use Mcp\Schema\Enum\LoggingLevel;
use Mcp\Server\NotificationSender;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * MCP-aware PSR-3 logger that sends log messages as MCP notifications.
 *
 * This logger implements the standard PSR-3 LoggerInterface and forwards
 * log messages to the NotificationSender. The NotificationHandler will
 * decide whether to actually send the notification based on capabilities.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 */
final class ClientLogger extends AbstractLogger
{
    public function __construct(
        private readonly NotificationSender $notificationSender,
        private readonly ?LoggerInterface $fallbackLogger = null,
    ) {
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        // Always log to fallback logger if provided (for local debugging)
        $this->fallbackLogger?->log($level, $message, $context);

        // Convert PSR-3 level to MCP LoggingLevel
        $mcpLevel = $this->convertToMcpLevel($level);
        if (null === $mcpLevel) {
            return; // Unknown level, skip MCP notification
        }

        // Send MCP logging notification - let NotificationHandler decide if it should be sent
        try {
            $this->notificationSender->send('notifications/message', [
                'level' => $mcpLevel->value,
                'data' => (string) $message,
                'logger' => $context['logger'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // If MCP notification fails, at least log to fallback
            $this->fallbackLogger?->error('Failed to send MCP log notification', [
                'original_level' => $level,
                'original_message' => $message,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Converts PSR-3 log level to MCP LoggingLevel.
     *
     * @param mixed $level PSR-3 level
     *
     * @return LoggingLevel|null MCP level or null if unknown
     */
    private function convertToMcpLevel($level): ?LoggingLevel
    {
        return match (strtolower((string) $level)) {
            'emergency' => LoggingLevel::Emergency,
            'alert' => LoggingLevel::Alert,
            'critical' => LoggingLevel::Critical,
            'error' => LoggingLevel::Error,
            'warning' => LoggingLevel::Warning,
            'notice' => LoggingLevel::Notice,
            'info' => LoggingLevel::Info,
            'debug' => LoggingLevel::Debug,
            default => null,
        };
    }
}
