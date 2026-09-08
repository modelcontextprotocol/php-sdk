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
        ?string $privateKeyPem = null,
        string $signingAlgorithm = 'RS256',
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

            case TokenEndpointAuthMethod::PrivateKeyJwt:
                if (null === $privateKeyPem) {
                    throw new AuthorizationException('The authorization server asked for private_key_jwt client authentication, but no private key was configured.');
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

        return AccessToken::fromResponse($this->post($metadata->tokenEndpoint, $parameters, $authorization));
    }

    /**
     * Post a form-encoded grant request and return the parsed response.
     *
     * Exposed because not every token endpoint exchange yields an access token: RFC 8693
     * returns whatever token type was asked for, and the caller decides what to do with it.
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
        } catch (\Throwable $e) {
            throw new AuthorizationException(\sprintf('The token request to "%s" failed: %s', $endpoint, $e->getMessage()), 0, $e);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (200 !== $response->getStatusCode() || !\is_array($decoded)) {
            // Only an error document is quoted back. A 200 that failed to decode is a
            // successful token response in a shape this client cannot read, and putting
            // its body in the message would put a live access token into every log the
            // exception reaches.
            throw new AuthorizationException(\sprintf('The authorization server answered the token request with %d%s.', $response->getStatusCode(), match (true) {
                200 === $response->getStatusCode() => ', and a body this client could not read as JSON', \is_array($decoded) => ': '.self::describeError($decoded), default => '',
            }));
        }

        return $decoded;
    }

    /**
     * Build the JWT a client signs to authenticate itself (RFC 7523 section 2.2).
     */
    private function createClientAssertion(string $clientId, string $audience, string $privateKeyPem, string $algorithm): string
    {
        if (!\extension_loaded('openssl')) {
            throw new AuthorizationException('Private key JWT client authentication needs the OpenSSL extension.');
        }

        $digest = match ($algorithm) {
            'RS256', 'ES256' => \OPENSSL_ALGO_SHA256,
            'RS384', 'ES384' => \OPENSSL_ALGO_SHA384,
            'RS512', 'ES512' => \OPENSSL_ALGO_SHA512,
            default => throw new AuthorizationException(\sprintf('Unsupported client assertion algorithm "%s". Use one of RS256, RS384, RS512, ES256, ES384 or ES512.', $algorithm)),
        };

        $now = time();
        $segments = [
            self::base64Url((string) json_encode(['alg' => $algorithm, 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iss' => $clientId,
                'sub' => $clientId,
                'aud' => $audience,
                'jti' => bin2hex(random_bytes(16)),
                'iat' => $now,
                'exp' => $now + 300,
            ])),
        ];

        $key = openssl_pkey_get_private($privateKeyPem);

        if (false === $key) {
            throw new AuthorizationException('The configured private key could not be read: '.(openssl_error_string() ?: 'unknown error').'.');
        }

        if (!openssl_sign(implode('.', $segments), $signature, $key, $digest)) {
            throw new AuthorizationException('Signing the client assertion failed: '.(openssl_error_string() ?: 'unknown error').'.');
        }

        if (str_starts_with($algorithm, 'ES')) {
            $signature = self::derToJose($signature, match ($algorithm) {
                'ES256' => 32,
                'ES384' => 48,
                default => 66,
            });
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    /**
     * Convert OpenSSL's DER-encoded ECDSA signature into the raw R||S pair JWS wants.
     *
     * DER carries each half as a minimally-encoded signed integer, so it may be shorter
     * than the curve's field size or a byte longer when the leading bit would read as
     * negative; JWS wants both halves fixed-width and unsigned.
     */
    private static function derToJose(string $der, int $partLength): string
    {
        if ('' === $der || "\x30" !== $der[0]) {
            throw new AuthorizationException('The ECDSA signature is not a DER sequence.');
        }

        $offset = 2;

        // A sequence longer than 127 bytes states its length over several bytes.
        if (\ord($der[1]) > 0x80) {
            $offset += \ord($der[1]) - 0x80;
        }

        $parts = [];

        for ($i = 0; $i < 2; ++$i) {
            if (!isset($der[$offset + 1]) || "\x02" !== $der[$offset]) {
                throw new AuthorizationException('The ECDSA signature does not contain two integers.');
            }

            $length = \ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\x00");
            $offset += 2 + $length;

            if (\strlen($value) > $partLength) {
                throw new AuthorizationException('The ECDSA signature does not match the algorithm\'s curve.');
            }

            $parts[] = str_pad($value, $partLength, "\x00", \STR_PAD_LEFT);
        }

        return implode('', $parts);
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

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
