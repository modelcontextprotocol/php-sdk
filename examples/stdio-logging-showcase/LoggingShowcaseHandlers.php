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

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\McpLogger;
use Psr\Log\LoggerInterface;

/**
 * Example handlers showcasing auto-injected MCP logging capabilities.
 *
 * This demonstrates how handlers can receive McpLogger automatically
 * without any manual configuration - just declare it as a parameter!
 */
final class LoggingShowcaseHandlers
{
    /**
     * Tool that demonstrates different logging levels with auto-injected McpLogger.
     *
     * @param string    $message The message to log
     * @param string    $level   The logging level (debug, info, warning, error)
     * @param McpLogger $logger  Auto-injected MCP logger
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'log_message', description: 'Demonstrates MCP logging with different levels')]
    public function logMessage(string $message, string $level, McpLogger $logger): array
    {
        $logger->info('🚀 Starting log_message tool', [
            'requested_level' => $level,
            'message_length' => \strlen($message),
        ]);

        switch (strtolower($level)) {
            case 'debug':
                $logger->debug("🔍 Debug: $message", ['tool' => 'log_message']);
                break;
            case 'info':
                $logger->info("ℹ️ Info: $message", ['tool' => 'log_message']);
                break;
            case 'warning':
                $logger->warning("⚠️ Warning: $message", ['tool' => 'log_message']);
                break;
            case 'error':
                $logger->error("❌ Error: $message", ['tool' => 'log_message']);
                break;
            default:
                $logger->warning("Unknown level '$level', defaulting to info");
                $logger->info("📝 $message", ['tool' => 'log_message']);
        }

        $logger->debug('✅ log_message tool completed successfully');

        return [
            'message' => "Logged message with level: $level",
            'logged_at' => date('Y-m-d H:i:s'),
            'level_used' => $level,
        ];
    }

    /**
     * Tool that simulates a complex operation with detailed logging.
     *
     * @param array<mixed>    $data   Input data to process
     * @param LoggerInterface $logger Auto-injected logger (will be McpLogger)
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: 'process_data', description: 'Processes data with comprehensive logging')]
    public function processData(array $data, LoggerInterface $logger): array
    {
        $logger->info('🔄 Starting data processing', ['input_count' => \count($data)]);

        $results = [];
        $errors = [];

        foreach ($data as $index => $item) {
            $logger->debug("Processing item $index", ['item' => $item]);

            try {
                if (!\is_string($item) && !is_numeric($item)) {
                    throw new \InvalidArgumentException('Item must be string or numeric');
                }

                $processed = strtoupper((string) $item);
                $results[] = $processed;

                $logger->debug("✅ Successfully processed item $index", [
                    'original' => $item,
                    'processed' => $processed,
                ]);
            } catch (\Exception $e) {
                $logger->error("❌ Failed to process item $index", [
                    'item' => $item,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = "Item $index: ".$e->getMessage();
            }
        }

        if (empty($errors)) {
            $logger->info('🎉 Data processing completed successfully', [
                'processed_count' => \count($results),
            ]);
        } else {
            $logger->warning('⚠️ Data processing completed with errors', [
                'processed_count' => \count($results),
                'error_count' => \count($errors),
            ]);
        }

        return [
            'processed_items' => $results,
            'errors' => $errors,
            'summary' => [
                'total_input' => \count($data),
                'successful' => \count($results),
                'failed' => \count($errors),
            ],
        ];
    }

    /**
     * Resource that provides logging configuration with auto-injected logger.
     *
     * @param McpLogger $logger Auto-injected MCP logger
     *
     * @return array<string, mixed>
     */
    #[McpResource(
        uri: 'config://logging/settings',
        name: 'logging_config',
        description: 'Current logging configuration and auto-injection status.',
        mimeType: 'application/json'
    )]
    public function getLoggingConfig(McpLogger $logger): array
    {
        $logger->info('📋 Retrieving logging configuration');

        $config = [
            'auto_injection' => 'enabled',
            'supported_types' => ['McpLogger', 'LoggerInterface'],
            'levels' => ['debug', 'info', 'warning', 'error'],
            'features' => [
                'auto_injection',
                'mcp_transport',
                'fallback_logging',
                'structured_data',
            ],
        ];

        $logger->debug('Configuration retrieved', $config);

        return $config;
    }

    /**
     * Prompt that generates logging examples with auto-injected logger.
     *
     * @param string          $example_type Type of logging example to generate
     * @param LoggerInterface $logger       Auto-injected logger
     *
     * @return array<string, mixed>
     */
    #[McpPrompt(name: 'logging_examples', description: 'Generates logging code examples')]
    public function generateLoggingExamples(string $example_type, LoggerInterface $logger): array
    {
        $logger->info('📝 Generating logging examples', ['type' => $example_type]);

        $examples = match (strtolower($example_type)) {
            'tool' => [
                'title' => 'Tool Handler with Auto-Injected Logger',
                'code' => '
#[McpTool(name: "my_tool")]
public function myTool(string $input, McpLogger $logger): array
{
    $logger->info("Tool called", ["input" => $input]);
    // Your tool logic here
    return ["result" => "processed"];
}',
                'description' => 'McpLogger is automatically injected - no configuration needed!',
            ],

            'resource' => [
                'title' => 'Resource Handler with Logger Interface',
                'code' => '
#[McpResource(uri: "my://resource")]
public function getResource(LoggerInterface $logger): string
{
    $logger->debug("Resource accessed");
    return "resource content";
}',
                'description' => 'Works with both McpLogger and LoggerInterface types',
            ],

            'function' => [
                'title' => 'Function Handler with Auto-Injection',
                'code' => '
function myHandler(array $params, McpLogger $logger): array
{
    $logger->warning("Function handler called");
    return $params;
}',
                'description' => 'Even function handlers get auto-injection!',
            ],

            default => [
                'title' => 'Basic Logging Pattern',
                'code' => '
// Just declare McpLogger as a parameter
public function handler($data, McpLogger $logger)
{
    $logger->info("Handler started");
    // Auto-injected, no setup required!
}',
                'description' => 'The simplest way to get MCP logging',
            ],
        };

        $logger->info('✅ Generated logging example', ['type' => $example_type]);

        return [
            'prompt' => "Here's how to use auto-injected MCP logging:",
            'example' => $examples,
            'tips' => [
                'Just add McpLogger or LoggerInterface as a parameter',
                'No configuration or setup required',
                'Logger is automatically provided by the MCP SDK',
                'Logs are sent to connected MCP clients',
                'Fallback logger used if MCP transport unavailable',
            ],
        ];
    }
}
