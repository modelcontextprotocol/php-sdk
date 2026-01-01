<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Conformance;

use Psr\Log\AbstractLogger;

final class FileLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $filePath,
        private readonly bool $debug = false,
    ) {
    }

    public function log($level, mixed $message, array $context = []): void
    {
        if (!$this->debug && 'debug' === $level) {
            return;
        }

        $logMessage = \sprintf("[%s] %s\n", strtoupper($level), $message);
        file_put_contents($this->filePath, $logMessage, \FILE_APPEND);
    }
}
