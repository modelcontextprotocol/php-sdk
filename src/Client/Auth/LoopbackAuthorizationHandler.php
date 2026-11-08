<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

use Mcp\Exception\AuthorizationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Opens the user's browser and catches the redirect on a loopback listener.
 *
 * This is the flow a desktop or command line application wants: the redirect URI points
 * at `http://127.0.0.1:<port>/...`, the handler listens there for exactly as long as the
 * authorization takes, shows the user a "you can close this tab" page, and hands the
 * query parameters back. Loopback redirects are what OAuth 2.1 recommends for native
 * applications, and unlike a pasted code they keep the authorization code off the
 * user's clipboard and out of their shell history.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class LoopbackAuthorizationHandler implements AuthorizationHandlerInterface
{
    private const CALLBACK_PAGE = '<!doctype html><meta charset="utf-8"><title>Authorization finished</title>'
        .'<body style="font:16px system-ui;margin:4rem auto;max-width:32rem">'
        .'<h1>Authorization finished.</h1><p>You can close this tab and return to the application.</p>';

    /**
     * @param ?callable(string $url): void $openBrowser how to put the URL in front of the user,
     *                                                  defaulting to the platform's URL opener
     * @param int                          $timeout     seconds to wait for the user to come back
     */
    public function __construct(
        private readonly mixed $openBrowser = null,
        private readonly int $timeout = 300,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        $parts = parse_url($redirectUri);
        $host = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? ('https' === ($parts['scheme'] ?? 'http') ? 443 : 80);
        $callbackPath = $parts['path'] ?? '/';

        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);
        $state = \is_string($query['state'] ?? null) ? $query['state'] : null;

        // Windows lets a second listener bind a port that is in use.
        if ('Windows' === \PHP_OS_FAMILY && self::isPortInUse($host, $port)) {
            throw new AuthorizationException(\sprintf('Could not listen on "%s:%d" for the authorization redirect: the port is in use.', $host, $port));
        }

        $server = @stream_socket_server(\sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage);

        if (false === $server) {
            throw new AuthorizationException(\sprintf('Could not listen on "%s:%d" for the authorization redirect: %s (%d).', $host, $port, $errorMessage, $errorCode));
        }

        try {
            $this->open($authorizationUrl);

            $deadline = microtime(true) + $this->timeout;

            while (microtime(true) < $deadline) {
                $connection = @stream_socket_accept($server, max(1, (int) ($deadline - microtime(true))));

                if (false === $connection) {
                    continue;
                }

                $parameters = $this->serve($connection, $callbackPath, $state);

                if (null !== $parameters) {
                    return $parameters;
                }
            }
        } finally {
            fclose($server);
        }

        throw new AuthorizationException(\sprintf('No authorization redirect arrived at "%s" within %d seconds.', $redirectUri, $this->timeout));
    }

    /**
     * Answer one connection, returning its query parameters when it is the callback.
     *
     * Any local process or web page can reach this port, so everything else is answered and ignored.
     *
     * @param resource $connection
     *
     * @return array<string, string>|null
     */
    private function serve($connection, string $callbackPath, ?string $state): ?array
    {
        stream_set_timeout($connection, 5);
        $requestLine = fgets($connection, 8192);

        if (false === $requestLine || !preg_match('#^GET\s+(\S+)\s+HTTP/#i', $requestLine, $matches)) {
            fclose($connection);

            return null;
        }

        $target = parse_url($matches[1]);
        parse_str(\is_string($target['query'] ?? null) ? $target['query'] : '', $parameters);

        $callback = [];

        foreach ($parameters as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $callback[$name] = (string) $value;
            }
        }

        $isCallback = [] !== $callback
            && $callbackPath === ($target['path'] ?? null)
            && (null === $state || hash_equals($state, $callback['state'] ?? ''));

        $status = $isCallback ? '200 OK' : '404 Not Found';
        $body = $isCallback ? self::CALLBACK_PAGE : 'Not Found';
        fwrite($connection, \sprintf(
            "HTTP/1.1 %s\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            $status,
            \strlen($body),
            $body,
        ));
        fclose($connection);

        return $isCallback ? $callback : null;
    }

    private static function isPortInUse(string $host, int $port): bool
    {
        $connection = @stream_socket_client(\sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, 1);

        if (false === $connection) {
            return false;
        }

        fclose($connection);

        return true;
    }

    private function open(string $url): void
    {
        if (\is_callable($this->openBrowser)) {
            ($this->openBrowser)($url);

            return;
        }

        // The SDK only ever passes an endpoint it has already validated, but this string
        // reaches the desktop's protocol-handler dispatcher, so it is worth not relying
        // on that staying true.
        if (!AuthorizationServerMetadata::isTransportSecure($url)) {
            throw new AuthorizationException(\sprintf('Refusing to open "%s": an authorization URL must be HTTPS, or HTTP on a loopback address.', $url));
        }

        $this->logger->info('Opening the authorization page in the browser', ['url' => $url]);

        // Printed every time, as a backgrounded launcher can't report that no browser opened.
        file_put_contents('php://stderr', \sprintf("\nOpen this URL to authorize the application if your browser doesn't open it:\n\n  %s\n\n", $url));

        if (!\function_exists('proc_open')) {
            return;
        }

        $process = @proc_open(self::browserCommand($url, \PHP_OS_FAMILY), [], $pipes);

        if (\is_resource($process)) {
            proc_close($process);
        }
    }

    /**
     * The URL stays a separate argument, as escapeshellarg() removes "%" on Windows and cmd.exe would expand it.
     *
     * @internal
     *
     * @return list<string>
     */
    public static function browserCommand(string $url, string $osFamily): array
    {
        // sh backgrounds the opener, because it may not return until the browser exits.
        return match ($osFamily) {
            'Windows' => ['rundll32', 'url.dll,FileProtocolHandler', $url],
            'Darwin' => ['/bin/sh', '-c', 'open "$1" > /dev/null 2>&1 &', 'sh', $url],
            default => ['/bin/sh', '-c', 'xdg-open "$1" > /dev/null 2>&1 &', 'sh', $url],
        };
    }
}
