<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Auth;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Token Authenticator using OAuth 2.0 Token Introspection (RFC 7662).
 *
 * Validates opaque access tokens by calling the authorization server's
 * introspection endpoint.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7662
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class IntrospectionTokenAuthenticator implements TokenAuthenticatorInterface
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param string $introspectionEndpoint URL of the token introspection endpoint
     * @param string $clientId Client ID for authenticating to the introspection endpoint
     * @param string $clientSecret Client secret for authentication
     * @param string|null $expectedAudience Expected audience for the token
     * @param ClientInterface|null $httpClient PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory PSR-17 request factory (auto-discovered if null)
     * @param StreamFactoryInterface|null $streamFactory PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        private readonly string $introspectionEndpoint,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $expectedAudience = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function authenticate(string $token, ?string $resource = null): AuthenticationResult
    {
        try {
            $introspectionResult = $this->introspect($token);

            if (null === $introspectionResult) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Token introspection failed');
            }

            // Check if token is active
            $active = $introspectionResult['active'] ?? false;
            if (!$active) {
                return AuthenticationResult::unauthenticated('invalid_token', 'Token is not active');
            }

            // Validate audience if expected
            $expectedAudience = $resource ?? $this->expectedAudience;
            if (null !== $expectedAudience) {
                $aud = $introspectionResult['aud'] ?? null;
                $audList = is_array($aud) ? $aud : [$aud];
                if (!in_array($expectedAudience, $audList, true)) {
                    $this->logger->warning('Token audience mismatch', [
                        'expected' => $expectedAudience,
                        'actual' => $aud,
                    ]);

                    return AuthenticationResult::unauthenticated('invalid_token', 'Token not intended for this resource');
                }
            }

            // Build claims array from introspection response
            $claims = [];
            $relevantClaims = ['sub', 'iss', 'aud', 'scope', 'exp', 'iat', 'nbf', 'client_id', 'username'];
            foreach ($relevantClaims as $claim) {
                if (isset($introspectionResult[$claim])) {
                    $claims[$claim] = $introspectionResult[$claim];
                }
            }

            return AuthenticationResult::authenticated($claims, ['token_type' => 'introspection']);
        } catch (\Throwable $e) {
            $this->logger->error('Token introspection failed', ['exception' => $e]);

            return AuthenticationResult::unauthenticated('invalid_token', 'Token validation failed');
        }
    }

    /**
     * Call the introspection endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function introspect(string $token): ?array
    {
        try {
            $authHeader = 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
            $body = http_build_query(['token' => $token]);

            $request = $this->requestFactory->createRequest('POST', $this->introspectionEndpoint)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Authorization', $authHeader)
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() !== 200) {
                $this->logger->error('Introspection endpoint returned error', [
                    'endpoint' => $this->introspectionEndpoint,
                    'status' => $response->getStatusCode(),
                ]);

                return null;
            }

            $responseBody = (string)$response->getBody();
            $result = json_decode($responseBody, true);

            if (!is_array($result)) {
                $this->logger->error('Invalid introspection response', [
                    'response' => $responseBody,
                ]);

                return null;
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to call introspection endpoint', [
                'endpoint' => $this->introspectionEndpoint,
                'exception' => $e,
            ]);

            return null;
        }
    }
}

