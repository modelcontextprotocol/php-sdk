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

use Mcp\Client\Exception\AuthorizationException;
use Mcp\Client\Exception\InvalidArgumentException;
use Mcp\Client\Transport\NoRedirects;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-18 client that authenticates every request and retries the ones the
 * resource server challenges.
 *
 * Wrapping the HTTP client rather than teaching the transport about OAuth keeps the
 * two concerns apart: the transport still speaks MCP over plain PSR-18, and the same
 * decorator authenticates anything else the application sends to that server.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AuthenticatingHttpClient implements ClientInterface
{
    /**
     * Authorization attempts allowed while serving a single request.
     *
     * A server that keeps answering "insufficient scope" no matter what is asking for
     * an infinite loop; the specification asks clients to cap the escalation instead.
     */
    public const DEFAULT_MAX_AUTHORIZATION_ATTEMPTS = 3;

    private readonly ClientInterface $client;

    public function __construct(
        ClientInterface $client,
        private readonly AuthenticatorInterface $authenticator,
        private readonly int $maxAuthorizationAttempts = self::DEFAULT_MAX_AUTHORIZATION_ATTEMPTS,
    ) {
        if ($maxAuthorizationAttempts < 1) {
            throw new InvalidArgumentException(\sprintf('The number of authorization attempts must be at least 1, got %d.', $maxAuthorizationAttempts));
        }

        $this->client = NoRedirects::apply($client);
    }

    /**
     * Send a request that isn't worth a new authorization, such as closing a session.
     */
    public function sendUnchallenged(RequestInterface $request): ResponseInterface
    {
        return $this->client->sendRequest($this->authenticator->authenticate($request));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();
        $position = self::replayPosition($body);

        $sent = $this->authenticator->authenticate($request);
        $response = $this->client->sendRequest($sent);

        for ($attempt = 0; $attempt < $this->maxAuthorizationAttempts; ++$attempt) {
            if (!\in_array($response->getStatusCode(), [401, 403], true)) {
                return $response;
            }

            // The request as it actually went out, credentials and all: the
            // authenticator needs to see which of them the server just rejected.
            try {
                $retry = $this->authenticator->handleChallenge($sent, $response);
            } catch (AuthorizationException $e) {
                $response->getBody()->close();

                throw $e;
            } catch (\Throwable $e) {
                $response->getBody()->close();

                // Anything else counts as a connection problem, and retrying that means signing in again.
                throw new AuthorizationException('The authorization failed: '.$e->getMessage(), 0, $e);
            }

            if (!$retry) {
                return $response;
            }

            // A consumed body that cannot be replayed would go out empty or truncated.
            if (null === $position) {
                return $response;
            }

            try {
                $body->seek($position);
            } catch (\RuntimeException) {
                return $response;
            }

            $response->getBody()->close();

            $sent = $this->authenticator->authenticate($request);
            $response = $this->client->sendRequest($sent);
        }

        return $response;
    }

    private static function replayPosition(StreamInterface $body): ?int
    {
        if (!$body->isSeekable()) {
            return null;
        }

        try {
            return $body->tell();
        } catch (\RuntimeException) {
            return null;
        }
    }
}
