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
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\OAuthErrorException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Exchanges whatever the client is holding for an access token.
 *
 * All four grants land here, and so do all four ways of proving which client is asking:
 * the difference between them is a handful of form fields and one header, which is why
 * they share one place rather than one class each.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TokenEndpoint
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, string> $parameters grant-specific form fields
     */
    public function request(
        AuthorizationServerMetadata $metadata,
        ClientRegistration $client,
        array $parameters,
    ): AccessToken {
        $parameters['client_id'] = $client->clientId;
        $authorization = null;

        switch ($client->tokenEndpointAuthMethod) {
            case TokenEndpointAuthMethod::ClientSecretBasic:
                // RFC 6749 section 2.3.1: both halves are form-urlencoded before being
                // joined, so a secret containing a colon survives the round trip.
                $authorization = 'Basic '.base64_encode(rawurlencode($client->clientId).':'.rawurlencode((string) $client->clientSecret));
                break;

            case TokenEndpointAuthMethod::ClientSecretPost:
                $parameters['client_secret'] = (string) $client->clientSecret;
                break;

            case TokenEndpointAuthMethod::None:
                break;
        }

        $this->logger->debug('Requesting an access token', [
            'endpoint' => $metadata->tokenEndpoint,
            'grant_type' => $parameters['grant_type'] ?? null,
            'auth_method' => $client->tokenEndpointAuthMethod->value,
        ]);

        $response = $this->post($metadata->tokenEndpoint, $parameters, $authorization);

        try {
            return AccessToken::fromResponse($response);
        } catch (InvalidArgumentException $e) {
            throw new AuthorizationException(\sprintf('The authorization server sent an invalid token response: %s', $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $parameters, ?string $authorization = null): array
    {
        $request = $this->requestFactory->createRequest('POST', $endpoint)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream(http_build_query($parameters, '', '&', \PHP_QUERY_RFC1738)));

        if (null !== $authorization) {
            $request = $request->withHeader('Authorization', $authorization);
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new AuthorizationException(\sprintf('The token request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (200 !== $response->getStatusCode() || !\is_array($decoded)) {
            // Only an error document is quoted back. A 200 that failed to decode is a
            // successful token response in a shape this client cannot read, and putting
            // its body in the message would put a live access token into every log the
            // exception reaches.
            $message = \sprintf('The authorization server answered the token request with %d%s.', $response->getStatusCode(), match (true) {
                200 === $response->getStatusCode() => ', and a body this client could not read as JSON', \is_array($decoded) => ': '.self::describeError($decoded), default => '',
            });

            if (\is_array($decoded) && \is_string($decoded['error'] ?? null)) {
                throw new OAuthErrorException($message, $decoded['error']);
            }

            throw new AuthorizationException($message);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $response
     */
    private static function describeError(array $response): string
    {
        $error = \is_string($response['error'] ?? null) ? $response['error'] : 'unknown_error';
        $description = \is_string($response['error_description'] ?? null) ? $response['error_description'] : null;

        return null === $description ? $error : $error.' ('.$description.')';
    }
}
