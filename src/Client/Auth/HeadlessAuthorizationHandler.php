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
 * Requests the authorization endpoint directly and reads the code out of the redirect.
 *
 * There is no user and no browser here: the handler plays the user agent itself, asks
 * for the authorization URL, and stops at the `Location` header instead of following it.
 * That only works against an authorization server that grants without an interactive
 * prompt -- a conformance harness, a test double, or an enterprise IdP that has already
 * consented on the user's behalf. Anywhere a human is supposed to approve something,
 * use {@see LoopbackAuthorizationHandler} or {@see ConsoleAuthorizationHandler}.
 *
 * The request is made over a plain socket rather than the PSR-18 client on purpose:
 * PSR-18 leaves redirect following to the implementation, and this handler's whole job
 * is to *not* follow the redirect.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class HeadlessAuthorizationHandler implements AuthorizationHandlerInterface
{
    public function __construct(
        private readonly float $timeout = 10.0,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function authorize(string $authorizationUrl, string $redirectUri): array
    {
        $location = $this->fetchRedirect($authorizationUrl);
        $query = parse_url($location, \PHP_URL_QUERY);

        if (!\is_string($query) || '' === $query) {
            throw new AuthorizationException(\sprintf('The authorization server redirected to "%s", which carries no query parameters.', $location));
        }

        parse_str($query, $parameters);
        $parameters = array_map(strval(...), array_filter($parameters, is_scalar(...)));

        // Only the parameter names: the query carries the authorization code, and a
        // single-use credential in a log file is still a credential in a log file.
        $this->logger->debug('Authorization endpoint redirected', ['parameters' => array_keys($parameters)]);

        return $parameters;
    }

    /**
     * Issue a single GET and return the `Location` header without following it.
     */
    private function fetchRedirect(string $url): string
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['host'])) {
            throw new AuthorizationException(\sprintf('The authorization URL "%s" cannot be parsed.', $url));
        }

        $secure = 'https' === ($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($secure ? 443 : 80);
        $target = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $socket = @stream_socket_client(
            \sprintf('%s://%s:%d', $secure ? 'ssl' : 'tcp', $parts['host'], $port),
            $errorCode,
            $errorMessage,
            $this->timeout,
        );

        if (false === $socket) {
            throw new AuthorizationException(\sprintf('Could not reach the authorization endpoint at "%s": %s (%d).', $url, $errorMessage, $errorCode));
        }

        try {
            stream_set_timeout($socket, (int) $this->timeout);
            fwrite($socket, \sprintf(
                "GET %s HTTP/1.1\r\nHost: %s\r\nAccept: */*\r\nConnection: close\r\n\r\n",
                $target,
                $parts['host'].(\in_array($port, [80, 443], true) ? '' : ':'.$port),
            ));

            $headers = '';

            while (!feof($socket) && !str_contains($headers, "\r\n\r\n")) {
                $chunk = fread($socket, 4096);

                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $headers .= $chunk;
            }
        } finally {
            fclose($socket);
        }

        if (preg_match('/^Location:\s*(\S+)\s*$/im', $headers, $matches)) {
            return $matches[1];
        }

        throw new AuthorizationException(\sprintf('The authorization endpoint "%s" did not answer with a redirect. It may need a user to approve the request, in which case an interactive authorization handler is required.', $url));
    }
}
