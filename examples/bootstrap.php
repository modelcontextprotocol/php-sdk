<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Mcp\Capability\Registry\Container;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__).'/vendor/autoload.php';

set_exception_handler(function (Throwable $t): never {
    fwrite(\STDERR, "[MCP SERVER CRITICAL ERROR]\n");
    fwrite(\STDERR, 'Error: '.$t->getMessage()."\n");
    fwrite(\STDERR, 'File: '.$t->getFile().':'.$t->getLine()."\n");
    fwrite(\STDERR, $t->getTraceAsString()."\n");

    exit(1);
});

function logger(): LoggerInterface
{
    return new class extends AbstractLogger {
        public function log($level, Stringable|string $message, array $context = []): void
        {
            $debug = $_SERVER['DEBUG'] ?? false;

            if (!$debug && 'debug' === $level) {
                return;
            }

            $logMessage = sprintf(
                "[%s] %s %s\n",
                strtoupper($level),
                $message,
                ([] === $context || !$debug) ? '' : json_encode($context),
            );

            if ($_SERVER['FILE_LOG'] ?? false) {
                file_put_contents('dev.log', $logMessage, \FILE_APPEND);
            } elseif (defined('STDERR')) {
                fwrite(\STDERR, $logMessage);
            }
        }
    };
}

function container(): Container
{
    $container = new Container();
    $container->set(LoggerInterface::class, logger());

    return $container;
}
