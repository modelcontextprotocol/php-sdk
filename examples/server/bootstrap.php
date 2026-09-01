<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Capability\Registry\Container;
use Mcp\Server\Transport\StdioTransport;
use Mcp\Server\Transport\StreamableHttpTransport;
use Mcp\Server\Transport\TransportInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

set_exception_handler(static function (Throwable $t): never {
    logger()->critical('Uncaught exception: '.$t->getMessage(), ['exception' => $t]);

    exit(1);
});

/**
 * The transport every example runs on.
 *
 * Over HTTP that is one endpoint serving both protocol eras: `StreamableHttpTransport`
 * classifies each request and routes it to the lifecycle it belongs to, so every
 * example here answers an `initialize` handshake and a 2026-07-28 envelope alike.
 * Over stdio there is no such choice to make — that binding carries the handshake era.
 *
 * @return TransportInterface<int>|TransportInterface<ResponseInterface>
 */
function transport(): TransportInterface
{
    if ('cli' === \PHP_SAPI) {
        return new StdioTransport(logger: logger());
    }

    return new StreamableHttpTransport(
        (new Psr17Factory())->createServerRequestFromGlobals(),
        logger: logger(),
    );
}

function shutdown(ResponseInterface|int $result): never
{
    if ('cli' === \PHP_SAPI) {
        exit($result);
    }

    (new SapiEmitter())->emit($result);
    exit(0);
}

function logger(): LoggerInterface
{
    return new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $debug = $_SERVER['DEBUG'] ?? false;

            if (!$debug && 'debug' === $level) {
                return;
            }

            $exception = $context['exception'] ?? null;
            unset($context['exception']);

            $logMessage = sprintf(
                "[%s] %s %s\n",
                strtoupper($level),
                $message,
                ([] === $context || !$debug) ? '' : json_encode($context),
            );

            if ($exception instanceof Throwable) {
                $logMessage .= sprintf('> %s', $exception->getMessage())."\n";
            }

            if (($_SERVER['FILE_LOG'] ?? false) || !defined('STDERR')) {
                file_put_contents('dev.log', $logMessage, \FILE_APPEND);
            } else {
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
