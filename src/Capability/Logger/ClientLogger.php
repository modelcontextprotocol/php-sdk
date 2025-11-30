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
use Mcp\Server\ClientGateway;
use Mcp\Server\Protocol;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\AbstractLogger;

/**
 * MCP-aware PSR-3 logger that sends log messages as MCP notifications.
 *
 * @author Adam Jamiu <jamiuadam120@gmail.com>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ClientLogger extends AbstractLogger
{
    public function __construct(
        private ClientGateway $client,
        private SessionInterface $session,
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
        // Convert PSR-3 level to MCP LoggingLevel
        $mcpLevel = $this->convertToMcpLevel($level);
        if (null === $mcpLevel) {
            return; // Unknown level, skip MCP notification
        }

        $minimumLevel = $this->session->get(Protocol::SESSION_LOGGING_LEVEL, '');
        $minimumLevel = LoggingLevel::tryFrom($minimumLevel) ?? LoggingLevel::Warning;

        if ($this->getSeverityIndex($minimumLevel) > $this->getSeverityIndex($mcpLevel)) {
            return;
        }

        $this->client->log($mcpLevel, $message);
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

    /**
     * Gets the severity index for this log level.
     * Higher values indicate more severe log levels.
     *
     * @return int Severity index (0-7, where 7 is most severe)
     */
    private function getSeverityIndex(LoggingLevel $level): int
    {
        return match ($level) {
            LoggingLevel::Debug => 0,
            LoggingLevel::Info => 1,
            LoggingLevel::Notice => 2,
            LoggingLevel::Warning => 3,
            LoggingLevel::Error => 4,
            LoggingLevel::Critical => 5,
            LoggingLevel::Alert => 6,
            LoggingLevel::Emergency => 7,
        };
    }
}
