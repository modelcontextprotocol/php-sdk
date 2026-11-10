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

use Firebase\JWT\JWT;
use Mcp\Exception\AuthorizationException;
use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\OAuthErrorException;
use Mcp\Exception\TokenEndpointUnavailableException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Exchanges an authorization code or a refresh token for an access token.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class TokenEndpoint
{
    /** Asymmetric, since the authorization server holds the public key, and signable from a PEM key with every supported firebase/php-jwt. */
    public const ASSERTION_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES256K', 'ES384'];

    /** RFC 10070 section 4: an explicit type keeps the assertion from passing for another kind of JWT. */
    private const ASSERTION_TYPE = 'client-authentication+jwt';

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
        ?string $privateKeyPem = null,
        string $signingAlgorithm = 'RS256',
    ): AccessToken {
        $parameters['client_id'] = $client->clientId;
        $authorization = null;

        switch ($client->tokenEndpointAuthMethod) {
            case TokenEndpointAuthMethod::ClientSecretBasic:
                // RFC 6749 section 2.3.1: form-urlencoded before joining, so a secret may contain a colon.
                $authorization = 'Basic '.base64_encode(rawurlencode($client->clientId).':'.rawurlencode((string) $client->clientSecret));
                break;

            case TokenEndpointAuthMethod::ClientSecretPost:
                $parameters['client_secret'] = (string) $client->clientSecret;
                break;

            case TokenEndpointAuthMethod::PrivateKeyJwt:
                if (null === $privateKeyPem) {
                    throw new AuthorizationException('The client registration uses private_key_jwt client authentication, but no private key was configured. Call OAuth::setPrivateKeyJwt().');
                }

                $parameters['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
                $parameters['client_assertion'] = $this->createClientAssertion($client->clientId, $metadata->issuer, $privateKeyPem, $signingAlgorithm);
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
     * Post a form-encoded grant request, for an exchange that doesn't return an access token.
     *
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    public function post(string $endpoint, array $parameters, ?string $authorization = null): array
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
            throw new TokenEndpointUnavailableException(\sprintf('The token request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode(ResponseBody::read($response) ?? '', true);

        if (200 !== $response->getStatusCode() || !\is_array($decoded)) {
            // Only an error document is quoted, as an unreadable 200 may carry a live token.
            $message = \sprintf('The token endpoint "%s" answered with %d%s.', $endpoint, $response->getStatusCode(), match (true) {
                200 === $response->getStatusCode() => ', and a body this client could not read as JSON', \is_array($decoded) => ': '.self::describeError($decoded), default => '',
            });

            $status = $response->getStatusCode();
            $error = \is_array($decoded) ? ($decoded['error'] ?? null) : null;

            if ($status >= 500 || 408 === $status || 429 === $status || \in_array($error, ['temporarily_unavailable', 'server_error'], true)) {
                throw new TokenEndpointUnavailableException($message);
            }

            if (\is_string($error)) {
                throw new OAuthErrorException($message, $error);
            }

            throw new AuthorizationException($message);
        }

        return $decoded;
    }

    /**
     * Builds the JWT a client signs to authenticate itself (RFC 7523 section 2.2, as updated by RFC 10070).
     */
    private function createClientAssertion(string $clientId, string $audience, string $privateKeyPem, string $algorithm): string
    {
        if (!class_exists(JWT::class)) {
            throw new AuthorizationException('For using private_key_jwt client authentication, the firebase/php-jwt package is required. Try running "composer require firebase/php-jwt".');
        }

        if (!\in_array($algorithm, self::ASSERTION_ALGORITHMS, true)) {
            throw new AuthorizationException(\sprintf('Unsupported client assertion algorithm "%s". Use one of %s.', $algorithm, implode(', ', self::ASSERTION_ALGORITHMS)));
        }

        $now = time();

        try {
            return JWT::encode([
                'iss' => $clientId,
                'sub' => $clientId,
                'aud' => $audience,
                'jti' => bin2hex(random_bytes(16)),
                'iat' => $now,
                'exp' => $now + 60,
            ], $privateKeyPem, $algorithm, head: ['typ' => self::ASSERTION_TYPE]);
        } catch (\DomainException|\InvalidArgumentException|\UnexpectedValueException $e) {
            throw new AuthorizationException(\sprintf('Signing the client assertion with %s failed: %s', $algorithm, $e->getMessage()), 0, $e);
        }
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
