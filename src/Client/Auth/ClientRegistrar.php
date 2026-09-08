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
 * Registers the client with an authorization server on the fly (RFC 7591).
 *
 * MCP clients meet servers they have never seen before, so asking an administrator to
 * pre-provision a client id for each one does not scale. Where the authorization server
 * publishes a registration endpoint, the client creates its own identity instead.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class ClientRegistrar
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function register(
        AuthorizationServerMetadata $metadata,
        ClientMetadata $clientMetadata,
        TokenEndpointAuthMethod $preferredAuthMethod,
        ?string $scope = null,
    ): ClientRegistration {
        if (null === $metadata->registrationEndpoint) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" does not support dynamic client registration, and no client id was configured for it.', $metadata->issuer));
        }

        $body = $clientMetadata->toArray($preferredAuthMethod, $scope);

        $this->logger->debug('Registering with the authorization server', [
            'endpoint' => $metadata->registrationEndpoint,
            'client_name' => $clientMetadata->clientName,
        ]);

        $request = $this->requestFactory->createRequest('POST', $metadata->registrationEndpoint)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream((string) json_encode($body)));

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (\Throwable $e) {
            throw new AuthorizationException(\sprintf('The client registration request to "%s" failed: %s', $metadata->registrationEndpoint, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);

        if (!\in_array($response->getStatusCode(), [200, 201], true) || !\is_array($decoded)) {
            throw new AuthorizationException(\sprintf('The authorization server refused the client registration with %d.', $response->getStatusCode()));
        }

        return ClientRegistration::fromResponse($decoded, $preferredAuthMethod);
    }
}
