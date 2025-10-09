<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioEnvVariables;

use Mcp\Capability\Attribute\McpTool;

final class EnvToolHandler
{
    /**
     * Performs an action that can be modified by an environment variable.
     * The MCP client should set 'APP_MODE' in its 'env' config for this server.
     *
     * @param string $input some input data
     *
     * @return array<string, string|int> the result, varying by APP_MODE
     */
    #[McpTool(
        name: 'process_data_by_mode',
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'description' => 'The processing mode used',
                ],
                'processed_input' => [
                    'type' => 'string',
                    'description' => 'The processed input data (only in debug mode)',
                ],
                'processed_input_length' => [
                    'type' => 'integer',
                    'description' => 'The length of the processed input (only in production mode)',
                ],
                'original_input' => [
                    'type' => 'string',
                    'description' => 'The original input data (only in default mode)',
                ],
                'message' => [
                    'type' => 'string',
                    'description' => 'A descriptive message about the processing',
                ],
            ],
            'required' => ['mode', 'message'],
        ]
    )]
    public function processData(string $input): array
    {
        $appMode = getenv('APP_MODE'); // Read from environment

        if ('debug' === $appMode) {
            return [
                'mode' => 'debug',
                'processed_input' => strtoupper($input),
                'message' => 'Processed in DEBUG mode.',
            ];
        } elseif ('production' === $appMode) {
            return [
                'mode' => 'production',
                'processed_input_length' => \strlen($input),
                'message' => 'Processed in PRODUCTION mode (summary only).',
            ];
        } else {
            return [
                'mode' => $appMode ?: 'default',
                'original_input' => $input,
                'message' => 'Processed in default mode (APP_MODE not recognized or not set).',
            ];
        }
    }
}
