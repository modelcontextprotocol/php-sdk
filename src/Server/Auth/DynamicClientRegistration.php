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
 * OAuth 2.0 Dynamic Client Registration Protocol (RFC 7591).
 *
 * Allows MCP clients to register themselves dynamically with authorization servers.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class DynamicClientRegistration
{
    private ClientInterface $httpClient;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param ClientInterface|null         $httpClient      PSR-18 HTTP client (auto-discovered if null)
     * @param RequestFactoryInterface|null $requestFactory  PSR-17 request factory (auto-discovered if null)
     * @param StreamFactoryInterface|null  $streamFactory   PSR-17 stream factory (auto-discovered if null)
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    /**
     * Register a new OAuth client dynamically.
     *
     * @param string               $registrationEndpoint The authorization server's registration endpoint
     * @param ClientRegistration   $clientMetadata       Client metadata to register
     * @param string|null          $initialAccessToken   Initial access token (if required by server)
     *
     * @return ClientRegistrationResponse The registered client information
     *
     * @throws \RuntimeException If registration fails
     */
    public function register(
        string $registrationEndpoint,
        ClientRegistration $clientMetadata,
        ?string $initialAccessToken = null,
    ): ClientRegistrationResponse {
        try {
            $body = json_encode($clientMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $request = $this->requestFactory->createRequest('POST', $registrationEndpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream($body));

            if (null !== $initialAccessToken) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $initialAccessToken);
            }

            $response = $this->httpClient->sendRequest($request);
            $responseBody = (string) $response->getBody();
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 201 && $statusCode !== 200) {
                $this->logger->error('Dynamic client registration failed', [
                    'endpoint' => $registrationEndpoint,
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);

                $error = json_decode($responseBody, true);
                throw new \RuntimeException(sprintf(
                    'Client registration failed: %s - %s',
                    $error['error'] ?? 'unknown_error',
                    $error['error_description'] ?? 'No description'
                ));
            }

            $data = json_decode($responseBody, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid registration response');
            }

            return ClientRegistrationResponse::fromArray($data);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode/decode registration data: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Update an existing client registration (RFC 7592).
     *
     * @param string             $configurationEndpoint The client's registration configuration endpoint
     * @param string             $registrationAccessToken The registration access token
     * @param ClientRegistration $clientMetadata Updated client metadata
     *
     * @return ClientRegistrationResponse The updated client information
     *
     * @throws \RuntimeException If update fails
     */
    public function update(
        string $configurationEndpoint,
        string $registrationAccessToken,
        ClientRegistration $clientMetadata,
    ): ClientRegistrationResponse {
        try {
            $body = json_encode($clientMetadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $request = $this->requestFactory->createRequest('PUT', $configurationEndpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Authorization', 'Bearer ' . $registrationAccessToken)
                ->withBody($this->streamFactory->createStream($body));

            $response = $this->httpClient->sendRequest($request);
            $responseBody = (string) $response->getBody();
            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                $this->logger->error('Client registration update failed', [
                    'endpoint' => $configurationEndpoint,
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);

                $error = json_decode($responseBody, true);
                throw new \RuntimeException(sprintf(
                    'Client update failed: %s - %s',
                    $error['error'] ?? 'unknown_error',
                    $error['error_description'] ?? 'No description'
                ));
            }

            $data = json_decode($responseBody, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Invalid update response');
            }

            return ClientRegistrationResponse::fromArray($data);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Failed to encode/decode registration data: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a client registration (RFC 7592).
     *
     * @param string $configurationEndpoint    The client's registration configuration endpoint
     * @param string $registrationAccessToken  The registration access token
     *
     * @throws \RuntimeException If deletion fails
     */
    public function delete(string $configurationEndpoint, string $registrationAccessToken): void
    {
        $request = $this->requestFactory->createRequest('DELETE', $configurationEndpoint)
            ->withHeader('Authorization', 'Bearer ' . $registrationAccessToken);

        $response = $this->httpClient->sendRequest($request);
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 204 && $statusCode !== 200) {
            $responseBody = (string) $response->getBody();
            $this->logger->error('Client deletion failed', [
                'endpoint' => $configurationEndpoint,
                'status' => $statusCode,
                'response' => $responseBody,
            ]);

            $error = json_decode($responseBody, true);
            throw new \RuntimeException(sprintf(
                'Client deletion failed: %s - %s',
                $error['error'] ?? 'unknown_error',
                $error['error_description'] ?? 'No description'
            ));
        }
    }

    /**
     * Read current client registration (RFC 7592).
     *
     * @param string $configurationEndpoint    The client's registration configuration endpoint
     * @param string $registrationAccessToken  The registration access token
     *
     * @return ClientRegistrationResponse Current client information
     *
     * @throws \RuntimeException If read fails
     */
    public function read(string $configurationEndpoint, string $registrationAccessToken): ClientRegistrationResponse
    {
        $request = $this->requestFactory->createRequest('GET', $configurationEndpoint)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $registrationAccessToken);

        $response = $this->httpClient->sendRequest($request);
        $responseBody = (string) $response->getBody();
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $this->logger->error('Client read failed', [
                'endpoint' => $configurationEndpoint,
                'status' => $statusCode,
                'response' => $responseBody,
            ]);

            $error = json_decode($responseBody, true);
            throw new \RuntimeException(sprintf(
                'Client read failed: %s - %s',
                $error['error'] ?? 'unknown_error',
                $error['error_description'] ?? 'No description'
            ));
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid read response');
        }

        return ClientRegistrationResponse::fromArray($data);
    }
}

