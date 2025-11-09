<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\DiscoveryCalculator;

use Mcp\Capability\Attribute\McpResource;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Icon;
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
     * @param float  $a         the first operand
     * @param float  $b         the second operand
     * @param string $operation the operation ('add', 'subtract', 'multiply', 'divide')
     *
     * @return float the result of the calculation
     */
    #[McpTool(
        name: 'calculate',
        icons: [new Icon('https://www.svgrepo.com/show/530644/calculator.svg', 'image/svg+xml', ['any'])],
    )]
    public function calculate(float $a, float $b, string $operation): float
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
                    throw new ToolCallException('Division by zero is not allowed.');
                }
                $result = $a / $b;
                break;
            default:
                throw new ToolCallException("Unknown operation '{$operation}'. Supported: add, subtract, multiply, divide.");
        }

        if (!$this->config['allow_negative'] && $result < 0) {
            throw new ToolCallException('Negative results are disabled.');
        }

        return round($result, $this->config['precision']);
    }

    /**
     * Provides the current calculator configuration.
     * Can be read by clients to understand precision etc.
     *
     * @return Config the configuration array
     */
    #[McpResource(
        uri: 'config://calculator/settings',
        name: 'calculator_config',
        description: 'Current settings for the calculator tool (precision, allow_negative).',
        mimeType: 'application/json',
        icons: [new Icon('https://www.svgrepo.com/show/529867/settings.svg', 'image/svg+xml', ['any'])],
    )]
    public function getConfiguration(): array
    {
        $this->logger->info('Resource config://calculator/settings read.');

        return $this->config;
    }

    /**
     * Updates a specific configuration setting.
     * Note: This requires more robust validation in a real app.
     *
     * @param string $setting the setting key ('precision' or 'allow_negative')
     * @param mixed  $value   the new value (int for precision, bool for allow_negative)
     *
     * @return array{
     *     success: bool,
     *     error?: string,
     *     message?: string
     * } success message or error
     */
    #[McpTool(name: 'update_setting')]
    public function updateSetting(string $setting, mixed $value): array
    {
        $this->logger->info(\sprintf('Setting tool called: setting=%s, value=%s', $setting, var_export($value, true)));
        if (!\array_key_exists($setting, $this->config)) {
            return ['success' => false, 'error' => "Unknown setting '{$setting}'."];
        }

        if ('precision' === $setting) {
            if (!\is_int($value) || $value < 0 || $value > 10) {
                return ['success' => false, 'error' => 'Invalid precision value. Must be integer between 0 and 10.'];
            }
            $this->config['precision'] = $value;

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
                return ['success' => false, 'error' => 'Invalid allow_negative value. Must be boolean (true/false).'];
            }
        }
        $this->config['allow_negative'] = $value;

        // $registry->notifyResourceChanged('config://calculator/settings');
        return ['success' => true, 'message' => 'Allow negative results set to '.($value ? 'true' : 'false').'.'];
    }
}
