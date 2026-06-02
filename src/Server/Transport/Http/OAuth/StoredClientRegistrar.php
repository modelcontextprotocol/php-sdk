<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Transport\Http\OAuth;

use Mcp\Exception\ClientRegistrationException;

/**
 * Default OAuth 2.0 Dynamic Client Registration (RFC 7591) implementation,
 * persisting registered clients via a {@see ClientRepositoryInterface}.
 *
 * Wire it into {@see \Mcp\Server\Transport\Http\Middleware\ClientRegistrationMiddleware}.
 */
final class StoredClientRegistrar implements ClientRegistrarInterface
{
    /** @var list<string> */
    private array $defaultScopes;

    /** @var list<string> */
    private array $allowedRedirectUriSchemes;

    /**
     * @param list<string> $defaultScopes
     * @param list<string> $allowedRedirectUriSchemes Schemes allowed in addition to http on loopback hosts
     */
    public function __construct(
        private readonly ClientRepositoryInterface $clients,
        array $defaultScopes = [],
        array $allowedRedirectUriSchemes = ['https'],
    ) {
        $this->defaultScopes = array_values($defaultScopes);
        $this->allowedRedirectUriSchemes = array_values($allowedRedirectUriSchemes);
    }

    public function register(array $registrationRequest): array
    {
        $redirectUris = $this->validateRedirectUris($registrationRequest['redirect_uris'] ?? null);
        $authMethod = $this->resolveAuthMethod($registrationRequest['token_endpoint_auth_method'] ?? null);
        $grantTypes = $this->resolveGrantTypes($registrationRequest['grant_types'] ?? null);
        $scopes = $this->resolveScopes($registrationRequest['scope'] ?? null);
        $clientName = isset($registrationRequest['client_name']) && \is_string($registrationRequest['client_name'])
            ? $registrationRequest['client_name']
            : null;

        $clientId = bin2hex(random_bytes(16));
        $isPublic = Client::AUTH_METHOD_NONE === $authMethod;
        $clientSecret = $isPublic ? null : bin2hex(random_bytes(32));

        $this->clients->save(new Client(
            clientId: $clientId,
            clientSecret: $clientSecret,
            redirectUris: $redirectUris,
            grantTypes: $grantTypes,
            scopes: $scopes,
            tokenEndpointAuthMethod: $authMethod,
            clientName: $clientName,
        ));

        $response = [
            'client_id' => $clientId,
            'client_id_issued_at' => time(),
            'redirect_uris' => $redirectUris,
            'grant_types' => $grantTypes,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $authMethod,
            'scope' => implode(' ', $scopes),
        ];

        if (null !== $clientSecret) {
            $response['client_secret'] = $clientSecret;
            $response['client_secret_expires_at'] = 0;
        }

        if (null !== $clientName) {
            $response['client_name'] = $clientName;
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function validateRedirectUris(mixed $redirectUris): array
    {
        if (!\is_array($redirectUris) || [] === $redirectUris) {
            throw new ClientRegistrationException('At least one redirect URI is required.', 'invalid_redirect_uri');
        }

        $normalized = [];
        foreach ($redirectUris as $uri) {
            if (!\is_string($uri) || !$this->isAllowedRedirectUri($uri)) {
                throw new ClientRegistrationException(\sprintf('Invalid or disallowed redirect URI: "%s".', \is_string($uri) ? $uri : \gettype($uri)), 'invalid_redirect_uri');
            }

            $normalized[] = $uri;
        }

        return array_values(array_unique($normalized));
    }

    private function isAllowedRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['scheme'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host'] ?? '');

        if ('http' === $scheme && \in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return \in_array($scheme, $this->allowedRedirectUriSchemes, true);
    }

    private function resolveAuthMethod(mixed $method): string
    {
        if (Client::AUTH_METHOD_NONE === $method) {
            return Client::AUTH_METHOD_NONE;
        }

        if (Client::AUTH_METHOD_CLIENT_SECRET_POST === $method) {
            return Client::AUTH_METHOD_CLIENT_SECRET_POST;
        }

        return Client::AUTH_METHOD_CLIENT_SECRET_BASIC;
    }

    /**
     * @return list<string>
     */
    private function resolveGrantTypes(mixed $grantTypes): array
    {
        $requested = \is_array($grantTypes)
            ? array_values(array_filter($grantTypes, 'is_string'))
            : ['authorization_code'];

        if (!\in_array('authorization_code', $requested, true)) {
            $requested[] = 'authorization_code';
        }

        if (!\in_array('refresh_token', $requested, true)) {
            $requested[] = 'refresh_token';
        }

        return array_values(array_unique($requested));
    }

    /**
     * @return list<string>
     */
    private function resolveScopes(mixed $scope): array
    {
        if (!\is_string($scope) || '' === trim($scope)) {
            return $this->defaultScopes;
        }

        $requested = array_filter(explode(' ', $scope), static fn (string $s): bool => '' !== $s);

        if ([] === $this->defaultScopes) {
            return array_values($requested);
        }

        return array_values(array_filter($requested, fn (string $s): bool => \in_array($s, $this->defaultScopes, true)));
    }
}
