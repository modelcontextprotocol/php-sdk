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

use Mcp\Exception\InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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

    public function __construct(
        private readonly ClientInterface $client,
        private readonly AuthenticatorInterface $authenticator,
        private readonly int $maxAuthorizationAttempts = self::DEFAULT_MAX_AUTHORIZATION_ATTEMPTS,
    ) {
        if ($maxAuthorizationAttempts < 1) {
            throw new InvalidArgumentException(\sprintf('The number of authorization attempts must be at least 1, got %d.', $maxAuthorizationAttempts));
        }
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $sent = $this->authenticator->authenticate($request);
        $response = $this->client->sendRequest($sent);

        for ($attempt = 0; $attempt < $this->maxAuthorizationAttempts; ++$attempt) {
            if (!\in_array($response->getStatusCode(), [401, 403], true)) {
                return $response;
            }

            // The request as it actually went out, credentials and all: the
            // authenticator needs to see which of them the server just rejected.
            if (!$this->authenticator->handleChallenge($sent, $response)) {
                return $response;
            }

            $sent = $this->authenticator->authenticate($this->rewind($request));
            $response = $this->client->sendRequest($sent);
        }

        return $response;
    }

    /**
     * Hand the body back to the client from the start.
     *
     * The first attempt consumed it; a non-seekable body cannot be replayed, in which
     * case the retry goes out with whatever the stream has left rather than failing --
     * MCP request bodies are built from strings and are always seekable.
     */
    private function rewind(RequestInterface $request): RequestInterface
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $request;
    }
}
