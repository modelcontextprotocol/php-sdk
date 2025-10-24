<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioLoggingShowcase;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\ClientLogger;

/**
 * Example handlers showcasing auto-injected MCP logging capabilities.
 *
 * This demonstrates how handlers can receive ClientLogger automatically
 * without any manual configuration - just declare it as a parameter!
 */
final class LoggingShowcaseHandlers
{
    /**
     * Tool that demonstrates different logging levels with auto-injected ClientLogger.
     *
     * @param string       $message The message to log
     * @param string       $level   The logging level (debug, info, warning, error)
     * @param ClientLogger $logger  Auto-injected MCP logger
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'log_message', description: 'Demonstrates MCP logging with different levels')]
    public function logMessage(string $message, string $level, ClientLogger $logger): array
    {
        $logger->info('🚀 Starting log_message tool', [
            'requested_level' => $level,
            'message_length' => \strlen($message),
        ]);

        switch (strtolower($level)) {
            case 'debug':
                $logger->debug("Debug: $message", ['tool' => 'log_message']);
                break;
            case 'info':
                $logger->info("Info: $message", ['tool' => 'log_message']);
                break;
            case 'notice':
                $logger->notice("Notice: $message", ['tool' => 'log_message']);
                break;
            case 'warning':
                $logger->warning("Warning: $message", ['tool' => 'log_message']);
                break;
            case 'error':
                $logger->error("Error: $message", ['tool' => 'log_message']);
                break;
            case 'critical':
                $logger->critical("Critical: $message", ['tool' => 'log_message']);
                break;
            case 'alert':
                $logger->alert("Alert: $message", ['tool' => 'log_message']);
                break;
            case 'emergency':
                $logger->emergency("Emergency: $message", ['tool' => 'log_message']);
                break;
            default:
                $logger->warning("Unknown level '$level', defaulting to info");
                $logger->info("Info: $message", ['tool' => 'log_message']);
        }

        $logger->debug('log_message tool completed successfully');

        return [
            'message' => "Logged message with level: $level",
            'logged_at' => date('Y-m-d H:i:s'),
            'level_used' => $level,
        ];
    }
}
