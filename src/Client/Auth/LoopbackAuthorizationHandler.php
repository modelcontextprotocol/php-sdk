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
    private const SUCCESS_PAGE = '<!doctype html><meta charset="utf-8"><title>Authorized</title>'
        .'<body style="font:16px system-ui;margin:4rem auto;max-width:32rem">'
        .'<h1>You are signed in.</h1><p>You can close this tab and return to the application.</p>';

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

                $parameters = $this->serve($connection);

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
     * Answer one connection, returning its query parameters when it looks like the callback.
     *
     * Browsers ask for `/favicon.ico` alongside the page, so a request without any
     * parameters is answered and ignored rather than mistaken for the redirect.
     *
     * @param resource $connection
     *
     * @return array<string, string>|null
     */
    private function serve($connection): ?array
    {
        stream_set_timeout($connection, 5);
        $requestLine = fgets($connection, 8192);

        if (false === $requestLine || !preg_match('#^GET\s+(\S+)\s+HTTP/#i', $requestLine, $matches)) {
            fclose($connection);

            return null;
        }

        $query = parse_url($matches[1], \PHP_URL_QUERY);
        parse_str(\is_string($query) ? $query : '', $parameters);

        $body = self::SUCCESS_PAGE;
        fwrite($connection, \sprintf(
            "HTTP/1.1 200 OK\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s",
            \strlen($body),
            $body,
        ));
        fclose($connection);

        if ([] === $parameters) {
            return null;
        }

        return array_map(strval(...), array_filter($parameters, is_scalar(...)));
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

        // Backgrounded, because the launcher may not return until the browser exits.
        $command = match (\PHP_OS_FAMILY) {
            'Darwin' => \sprintf('open %s > /dev/null 2>&1 &', escapeshellarg($url)),
            'Windows' => \sprintf('start "" %s', escapeshellarg($url)),
            default => \sprintf('xdg-open %s > /dev/null 2>&1 &', escapeshellarg($url)),
        };

        if (\function_exists('exec')) {
            @exec($command, $output, $status);

            if (0 === $status) {
                return;
            }
        }

        // No browser to open, or opening it failed: the user can still follow the link.
        fwrite(\STDERR, \sprintf("\nOpen this URL to authorize the application:\n\n  %s\n\n", $url));
    }
}
