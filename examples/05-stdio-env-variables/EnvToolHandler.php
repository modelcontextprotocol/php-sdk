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

namespace Mcp\Example\StdioEnvVariables;

use Mcp\Capability\Attribute\McpTool;

class EnvToolHandler
{
    /**
     * Performs an action that can be modified by an environment variable.
     * The MCP client should set 'APP_MODE' in its 'env' config for this server.
     *
     * @param string $input some input data
     *
     * @return array the result, varying by APP_MODE
     */
    #[McpTool(name: 'process_data_by_mode')]
    public function processData(string $input): array
    {
        $appMode = getenv('APP_MODE');
        // Read from environment
        if ('debug' === $appMode) {
            return [
                'mode' => 'debug',
                'processed_input' => strtoupper($input),
                'message' => 'Processed in DEBUG mode.',
            ];
        } // Read from environment

        if ('production' === $appMode) {
            return [
                'mode' => 'production',
                'processed_input_length' => \strlen($input),
                'message' => 'Processed in PRODUCTION mode (summary only).',
            ];
        }

        return [
            'mode' => $appMode ?: 'default',
            'original_input' => $input,
            'message' => 'Processed in default mode (APP_MODE not recognized or not set).',
        ];
    }
}
