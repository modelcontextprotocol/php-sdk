<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\StdioDiscoveryCalculator;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Logger\ClientLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @phpstan-type Config array{precision: int, allow_negative: bool}
 */
final class McpElements
{
    /**
     * @var Config
     */
    private array $config = [
        'precision' => 2,
        'allow_negative' => true,
    ];

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Performs a calculation based on the operation.
     *
     * Supports 'add', 'subtract', 'multiply', 'divide'.
     * Obeys the 'precision' and 'allow_negative' settings from the config resource.
     *
     * @param float        $a         the first operand
     * @param float        $b         the second operand
     * @param string       $operation the operation ('add', 'subtract', 'multiply', 'divide')
     * @param ClientLogger $logger    Auto-injected MCP logger
     *
     * @return float|string the result of the calculation, or an error message string
     */
    #[McpTool(name: 'calculate')]
    public function calculate(float $a, float $b, string $operation, ClientLogger $logger): float|string
    {
        $this->logger->info(\sprintf('Calculating: %f %s %f', $a, $operation, $b));

        $op = strtolower($operation);

        switch ($op) {
            case 'add':
                $result = $a + $b;
                break;
            case 'subtract':
                $result = $a - $b;
                break;
            case 'multiply':
                $result = $a * $b;
                break;
            case 'divide':
                if (0 == $b) {
                    $logger->warning('Division by zero attempted', [
                        'operand_a' => $a,
                        'operand_b' => $b,
                    ]);

                    return 'Error: Division by zero.';
                }
                $result = $a / $b;
                break;
            default:
                $logger->error('Unknown operation requested', [
                    'operation' => $operation,
                    'supported_operations' => ['add', 'subtract', 'multiply', 'divide'],
                ]);

                return "Error: Unknown operation '{$operation}'. Supported: add, subtract, multiply, divide.";
        }

        if (!$this->config['allow_negative'] && $result < 0) {
            $logger->warning('Negative result blocked by configuration', [
                'result' => $result,
                'allow_negative_setting' => false,
            ]);

            return 'Error: Negative results are disabled.';
        }

        $finalResult = round($result, $this->config['precision']);
        $logger->info('Calculation completed successfully', [
            'result' => $finalResult,
            'precision' => $this->config['precision'],
        ]);

        return $finalResult;
    }

    /**
     * Provides the current calculator configuration.
     * Can be read by clients to understand precision etc.
     *
     * @param ClientLogger $logger Auto-injected MCP logger for demonstration
     *
     * @return Config the configuration array
     */
    #[McpResource(
        uri: 'config://calculator/settings',
        name: 'calculator_config',
        description: 'Current settings for the calculator tool (precision, allow_negative).',
        mimeType: 'application/json',
    )]
    public function getConfiguration(ClientLogger $logger): array
    {
        $logger->info('📊 Resource config://calculator/settings accessed via auto-injection!', [
            'current_config' => $this->config,
            'auto_injection_demo' => 'ClientLogger was automatically injected into this resource handler',
        ]);

        return $this->config;
    }

    /**
     * Updates a specific configuration setting.
     * Note: This requires more robust validation in a real app.
     *
     * @param string       $setting the setting key ('precision' or 'allow_negative')
     * @param mixed        $value   the new value (int for precision, bool for allow_negative)
     * @param ClientLogger $logger  Auto-injected MCP logger
     *
     * @return array{
     *     success: bool,
     *     error?: string,
     *     message?: string
     * } success message or error
     */
    #[McpTool(name: 'update_setting')]
    public function updateSetting(string $setting, mixed $value, ClientLogger $logger): array
    {
        $this->logger->info(\sprintf('Setting tool called: setting=%s, value=%s', $setting, var_export($value, true)));
        if (!\array_key_exists($setting, $this->config)) {
            $logger->error('Unknown setting requested', [
                'setting' => $setting,
                'available_settings' => array_keys($this->config),
            ]);

            return ['success' => false, 'error' => "Unknown setting '{$setting}'."];
        }

        if ('precision' === $setting) {
            if (!\is_int($value) || $value < 0 || $value > 10) {
                $logger->warning('Invalid precision value provided', [
                    'value' => $value,
                    'valid_range' => '0-10',
                ]);

                return ['success' => false, 'error' => 'Invalid precision value. Must be integer between 0 and 10.'];
            }
            $this->config['precision'] = $value;
            $logger->info('Precision setting updated', [
                'new_precision' => $value,
                'previous_config' => $this->config,
            ]);

            // In real app, notify subscribers of config://calculator/settings change
            // $registry->notifyResourceChanged('config://calculator/settings');
            return ['success' => true, 'message' => "Precision updated to {$value}."];
        }

        if (!\is_bool($value)) {
            // Attempt basic cast for flexibility
            if (\in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'])) {
                $value = true;
            } elseif (\in_array(strtolower((string) $value), ['false', '0', 'no', 'off'])) {
                $value = false;
            } else {
                $logger->warning('Invalid allow_negative value provided', [
                    'value' => $value,
                    'expected_type' => 'boolean',
                ]);

                return ['success' => false, 'error' => 'Invalid allow_negative value. Must be boolean (true/false).'];
            }
        }
        $this->config['allow_negative'] = $value;
        $logger->info('Allow negative setting updated', [
            'new_allow_negative' => $value,
            'updated_config' => $this->config,
        ]);

        // $registry->notifyResourceChanged('config://calculator/settings');
        return ['success' => true, 'message' => 'Allow negative results set to '.($value ? 'true' : 'false').'.'];
    }
}
