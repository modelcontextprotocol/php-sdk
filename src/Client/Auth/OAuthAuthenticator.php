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
use Mcp\Exception\AuthorizationPendingException;
use Mcp\Exception\OAuthErrorException;
use Mcp\Exception\TokenEndpointUnavailableException;
use Mcp\Server\Transport\Http\OAuth\SecureUrl;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drives the MCP authorization flow when a server answers with a challenge; build one with {@see OAuth}.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuthAuthenticator implements AuthenticatorInterface
{
    private ?string $issuer = null;
    private ?string $resource = null;
    private ?AccessToken $token = null;

    /**
     * The resource the current token may be sent to, which stays behind $resource when an authorization fails.
     */
    private ?string $audience = null;

    /** @var string[] The scopes the last authorization asked for, which a step-up challenge adds to. */
    private array $requestedScopes = [];

    private bool $offlineAccessRefused = false;

    public function __construct(
        private readonly OAuthConfiguration $configuration,
        private readonly CredentialStorageInterface $storage,
        private readonly AuthorizationHandlerInterface $authorizationHandler,
        private readonly MetadataDiscovery $discovery,
        private readonly ClientRegistrar $registrar,
        private readonly TokenEndpoint $tokenEndpoint,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function authenticate(RequestInterface $request): RequestInterface
    {
        if (null === $this->token || $this->token->isExpired()) {
            return $request;
        }

        // An authenticator may be reused, and another resource must not receive this token.
        if (null === $this->audience || !ProtectedResourceMetadata::isWithin(self::canonicalize((string) $request->getUri()), $this->audience)) {
            return $request;
        }

        return $request->withHeader('Authorization', $this->token->getAuthorizationHeader());
    }

    public function handleChallenge(RequestInterface $request, ResponseInterface $response): bool
    {
        $challenge = AuthorizationChallenge::fromResponse($response);

        // Signing in again only helps a 403 that asks for more scopes.
        if (403 === $response->getStatusCode() && 'insufficient_scope' !== $challenge->getError()) {
            return false;
        }

        $endpoint = self::canonicalize((string) $request->getUri());

        // Metadata fetched over plain http can be forged and the token would travel in the clear.
        if (!AuthorizationServerMetadata::isTransportSecure($endpoint)) {
            throw new AuthorizationException(\sprintf('Refusing to authorize the server at "%s": it is neither an HTTPS URL nor a loopback address.', $endpoint));
        }

        [$resource, $metadata, $scopesSupported] = $this->discover($endpoint, $challenge);

        if (null !== $this->configuration->expectedIssuer && $metadata->issuer !== $this->configuration->expectedIssuer) {
            throw new AuthorizationException(\sprintf('The server at "%s" names the authorization server "%s", but only "%s" is expected.', $endpoint, $metadata->issuer, $this->configuration->expectedIssuer));
        }

        if ($metadata->issuer !== $this->issuer) {
            // Credentials of one authorization server must never reach another.
            $this->logger->info('Authorization server changed', ['from' => $this->issuer, 'to' => $metadata->issuer]);
            $this->token = null;
            $this->requestedScopes = [];
            $this->offlineAccessRefused = false;
        } elseif ($resource !== $this->resource) {
            $this->requestedScopes = [];
        }

        $this->issuer = $metadata->issuer;
        $this->resource = $resource;

        $stored = $this->storage->getToken($metadata->issuer, $resource);
        $scopes = $this->selectScopes($challenge, $scopesSupported, $metadata, $stored);
        // The resource never requires offline_access, which only gets a refresh token.
        $needed = array_values(array_diff($scopes, ['offline_access']));

        // Signing in again can't help if this token holds or already asked for all the server wants.
        if ('insufficient_scope' === $challenge->getError()
            && null !== $this->token
            && $request->getHeaderLine('Authorization') === $this->token->getAuthorizationHeader()
            && [] === array_diff($needed, $this->token->scopes, $this->requestedScopes)
        ) {
            return false;
        }

        if (null !== $stored && $this->isUsable($stored, $needed, $request)) {
            $this->logger->debug('Reusing a stored access token', ['issuer' => $metadata->issuer, 'resource' => $resource]);
            $this->token = $stored;
            $this->audience = $resource;

            return true;
        }

        $client = $this->resolveClient($metadata, $scopes, $scopesSupported);
        // A refresh can't widen a grant (RFC 6749 section 6), so a step-up needs a new authorization.
        $refreshable = 'insufficient_scope' === $challenge->getError() ? null : $stored;

        try {
            $token = $this->refresh($refreshable, $metadata, $client, $needed, $resource, $request);
        } catch (OAuthErrorException $e) {
            if ($client->clientId === $this->configuration->clientMetadataUrl) {
                throw new OAuthErrorException(\sprintf('The authorization server "%s" answered invalid_client for the client metadata document "%s". Check that it can fetch the document and that its "client_id" and "redirect_uris" match.', $metadata->issuer, $client->clientId), $e->error);
            }

            if (!$client->dynamic) {
                throw new OAuthErrorException(\sprintf('The authorization server "%s" answered invalid_client for the configured client "%s". Check the client ID and secret given to setClientCredentials().', $metadata->issuer, $client->clientId), $e->error);
            }

            $this->logger->info('The authorization server no longer knows this client; registering it again.', ['issuer' => $metadata->issuer]);
            $this->forgetRefusedClient($metadata->issuer, $client);
            $token = null;
            $client = $this->resolveClient($metadata, $scopes, $scopesSupported);
        }

        if (null === $token) {
            try {
                [$token, $scopes] = $this->grantWithFallback($metadata, $client, $scopes, $resource);
            } catch (AuthorizationException $e) {
                // The user already went through the browser once, so only the next attempt registers again.
                if (self::isForgottenClient($e, $client)) {
                    $this->forgetRefusedClient($metadata->issuer, $client);
                }

                throw $e;
            }

            $token = $token->withGrantedScopes($scopes);
            $this->requestedScopes = $scopes;
            $this->storage->saveToken($metadata->issuer, $resource, $token);
        }

        $this->token = $token;
        $this->audience = $resource;

        return true;
    }

    /**
     * Completes an authorization that RedirectAuthorizationHandler sent to the browser.
     *
     * @param array<string, string> $callback the query parameters of the request to the redirect URI
     *
     * @throws AuthorizationPendingException when the authorization has to start again without offline_access
     * @throws AuthorizationException        when the callback does not complete a pending authorization of this storage
     */
    public function finishAuthorization(array $callback): void
    {
        foreach (['state', 'iss', 'code', 'error', 'error_description'] as $key) {
            if (\array_key_exists($key, $callback) && !\is_string($callback[$key])) {
                throw new AuthorizationException(\sprintf('The authorization response parameter "%s" must be a string.', $key));
            }
        }

        $state = $callback['state'] ?? '';

        if ('' === $state || !$this->storage instanceof PendingAuthorizationStorageInterface) {
            throw new AuthorizationException('No pending authorization is available for this callback.');
        }

        // Consumed before anything is checked, so a callback can't be tried twice.
        $pending = $this->storage->consumePendingAuthorization($state);

        if (null === $pending || $pending->expiresAt <= time()) {
            throw new AuthorizationException('The authorization response does not match a pending authorization.');
        }

        $metadata = $pending->metadata;

        if (null !== $this->configuration->expectedIssuer && $metadata->issuer !== $this->configuration->expectedIssuer) {
            throw new AuthorizationException(\sprintf('The pending authorization belongs to the authorization server "%s", but only "%s" is expected.', $metadata->issuer, $this->configuration->expectedIssuer));
        }

        // Another flow may have replaced the registration of the issuer; a configured client is resolved again.
        $client = $pending->registration ?? $this->resolveClient($metadata, $pending->scopes, null);

        if ($client->clientId !== $pending->clientId) {
            throw new AuthorizationException(\sprintf('The client "%s" of the pending authorization is no longer the client for "%s"; start the authorization again.', $pending->clientId, $metadata->issuer));
        }

        $this->token = null;
        $this->audience = null;
        $this->requestedScopes = [];
        $this->issuer = $metadata->issuer;
        $this->resource = $pending->resource;
        $this->offlineAccessRefused = $pending->offlineAccessRefused;
        $scopes = $pending->scopes;

        try {
            $this->verifyCallback($callback, $pending->state, $metadata);
            $token = $this->requestToken($metadata, $client, self::withoutEmptyValues([
                'grant_type' => Grant::AuthorizationCode->value,
                'code' => $callback['code'],
                'redirect_uri' => $pending->redirectUri,
                'code_verifier' => $pending->codeVerifier,
                'resource' => $pending->resource,
            ]));
        } catch (AuthorizationException $e) {
            if (self::isForgottenClient($e, $client)) {
                $this->forgetRefusedClient($metadata->issuer, $client);

                throw $e;
            }

            $scopes = $this->reduceScopes($scopes, $e);
            $token = $this->grant($metadata, $client, $scopes, $pending->resource);
        }

        $this->token = $token->withGrantedScopes($scopes);
        $this->requestedScopes = $scopes;
        $this->audience = $pending->resource;
        $this->storage->saveToken($metadata->issuer, $pending->resource, $this->token);
    }

    /**
     * Find the resource and the server, on every challenge as a resource may move.
     *
     * @return array{string, AuthorizationServerMetadata, ?string[]}
     */
    private function discover(string $endpoint, AuthorizationChallenge $challenge): array
    {
        $metadata = $this->discovery->discoverProtectedResource($endpoint, self::trustedMetadataUrl($endpoint, $challenge));

        if (null === $metadata) {
            return [$endpoint, $this->discoverLegacyServer($endpoint), null];
        }

        if (!$metadata->covers($endpoint)) {
            // A token for another resource would be sent to this one: a confused deputy.
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" declares the unrelated resource "%s"; refusing to authorize against it.', $endpoint, $metadata->resource));
        }

        $issuer = $this->selectIssuer($endpoint, $metadata->authorizationServers);

        // Checked before the fetch, as even the request is made on the server's behalf.
        if (!AuthorizationServerMetadata::isTransportSecure($issuer)) {
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" names "%s" as its authorization server, which is neither an HTTPS URL nor a loopback address.', $endpoint, $issuer));
        }

        // Even the request is made on the server's behalf, so a remote one can't reach what only listens locally.
        if (SecureUrl::isLoopback($issuer) && !SecureUrl::isLoopback($endpoint) && $issuer !== $this->configuration->expectedIssuer) {
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" names "%s" as its authorization server, which is a loopback address. Call OAuth::setExpectedIssuer() if that is intended.', $endpoint, $issuer));
        }

        $server = $this->discovery->discoverAuthorizationServer($issuer);

        if (null === $server) {
            throw new AuthorizationException(\sprintf('No usable metadata was found for the authorization server "%s".', $issuer));
        }

        return [$metadata->resource, $server, $metadata->scopesSupported];
    }

    private function forgetRefusedClient(string $issuer, ClientRegistration $client): void
    {
        // Under the registration lock, so a client that another worker registered after this one is kept.
        $this->storage->synchronize(function () use ($issuer, $client): void {
            if ($client->clientId === $this->storage->getClientRegistration($issuer)?->clientId) {
                $this->storage->forget($issuer);
            }
        });
    }

    private static function isForgottenClient(AuthorizationException $e, ClientRegistration $client): bool
    {
        return $client->dynamic && self::isRefusedClient($e);
    }

    private static function isRefusedClient(AuthorizationException $e): bool
    {
        return $e instanceof OAuthErrorException && 'invalid_client' === $e->error;
    }

    /**
     * @param string[] $authorizationServers
     */
    private function selectIssuer(string $endpoint, array $authorizationServers): string
    {
        if ([] === $authorizationServers) {
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" names no authorization server.', $endpoint));
        }

        $expected = $this->configuration->expectedIssuer;

        if (null === $expected) {
            return $authorizationServers[0];
        }

        if (!\in_array($expected, $authorizationServers, true)) {
            throw new AuthorizationException(\sprintf('The server at "%s" names the authorization servers "%s", but only "%s" is expected.', $endpoint, implode('", "', $authorizationServers), $expected));
        }

        return $expected;
    }

    /**
     * The metadata URL of the challenge, unless it would make the client request another origin on the server's behalf.
     */
    private static function trustedMetadataUrl(string $endpoint, AuthorizationChallenge $challenge): ?string
    {
        $url = $challenge->getResourceMetadataUrl();

        if (null === $url) {
            return null;
        }

        if (self::origin($url) === self::origin($endpoint)) {
            return $url;
        }

        return null;
    }

    /**
     * Fall back to the discovery of the 2025-03-26 revision, where a server is its own authorization server.
     */
    private function discoverLegacyServer(string $endpoint): AuthorizationServerMetadata
    {
        if (!$this->configuration->legacyDiscovery) {
            throw new AuthorizationException(\sprintf('The server at "%s" published no usable protected resource metadata, and discovery of pre-2025-06-18 servers is disabled.', $endpoint));
        }

        $issuer = self::origin($endpoint);
        $this->logger->info('No protected resource metadata; falling back to treating the server as its own authorization server.', ['issuer' => $issuer]);

        return $this->discovery->discoverLegacyAuthorizationServer($issuer)
            ?? throw new AuthorizationException(\sprintf('The authorization server metadata of "%s" could not be read, and only a 404 falls back to the fixed endpoints.', $issuer));
    }

    /**
     * Prefer the scopes of the challenge, then those of the resource, and keep the ones requested before.
     *
     * @param ?string[] $scopesSupported
     *
     * @return string[]
     */
    private function selectScopes(AuthorizationChallenge $challenge, ?array $scopesSupported, AuthorizationServerMetadata $metadata, ?AccessToken $stored): array
    {
        $scopes = $this->configuration->scopes;

        if ([] === $scopes) {
            // What a server names is no reason to ask for a refresh token.
            $scopes = array_diff($challenge->getScopes() ?: ($scopesSupported ?? []), ['offline_access']);
        } elseif ('insufficient_scope' === $challenge->getError()) {
            $scopes = [...$scopes, ...array_diff($challenge->getScopes(), ['offline_access'])];
        }

        $held = 'insufficient_scope' === $challenge->getError() ? array_diff($stored->scopes ?? [], ['offline_access']) : [];
        $scopes = array_values(array_unique([...$this->requestedScopes, ...$held, ...$scopes]));

        $limit = $this->scopeLimit();

        if (null !== $limit) {
            $beyond = array_values(array_diff($scopes, $limit));

            if ([] !== $beyond) {
                $this->logger->warning('Not requesting scopes beyond the configured ones for a client without a user', ['dropped' => $beyond, 'configured' => $limit]);
                $scopes = array_values(array_intersect($scopes, $limit));
            }
        }

        if ($this->offlineAccessRefused || !$this->configuration->offlineAccess) {
            return array_values(array_diff($scopes, ['offline_access']));
        }

        // An unknown scope is an error, so it is only requested where the server lists it.
        if ($metadata->supportsScope('offline_access')
            && $metadata->supportsGrant(Grant::RefreshToken->value)
            && !\in_array('offline_access', $scopes, true)
        ) {
            $scopes[] = 'offline_access';
        }

        return $scopes;
    }

    /**
     * Whether a stored token is worth trying instead of authorizing again.
     *
     * @param string[] $scopes
     */
    private function isUsable(AccessToken $token, array $scopes, RequestInterface $request): bool
    {
        // The server just rejected this very token.
        if ($request->getHeaderLine('Authorization') === $token->getAuthorizationHeader()) {
            return false;
        }

        return !$token->isExpiringSoon() && $token->covers($scopes) && $this->isWithinScopeLimit($token);
    }

    /**
     * Tokens are stored per server and resource, so a stored one may come from a broader configuration.
     */
    private function isWithinScopeLimit(AccessToken $token): bool
    {
        $limit = $this->scopeLimit();

        return null === $limit || ([] !== $token->scopes && [] === array_diff($token->scopes, $limit));
    }

    /**
     * Without a user to consent, the configured scopes are all a client may hold, whatever a server or a stored token names.
     *
     * @return ?string[]
     */
    private function scopeLimit(): ?array
    {
        if (Grant::AuthorizationCode === $this->configuration->grant || [] === $this->configuration->scopes) {
            return null;
        }

        return $this->configuration->scopes;
    }

    /**
     * Trade a refresh token for a new access token, one process at a time as a rotated refresh token can only be spent once.
     *
     * @param string[] $scopes
     */
    private function refresh(?AccessToken $stored, AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes, string $resource, RequestInterface $request): ?AccessToken
    {
        if (null === $stored || null === $stored->refreshToken) {
            return null;
        }

        // Without a list, the refresh token this server issued is the best evidence, and a refusal only costs one request.
        if (null !== $metadata->grantTypesSupported && !$metadata->supportsGrant(Grant::RefreshToken->value)) {
            return null;
        }

        return $this->storage->synchronize(function () use ($metadata, $client, $scopes, $resource, $request): ?AccessToken {
            // Another process may have refreshed the token while this one waited.
            $current = $this->storage->getToken($metadata->issuer, $resource);

            if (null === $current) {
                return null;
            }

            if ($this->isUsable($current, $scopes, $request)) {
                return $current;
            }

            // A refresh keeps the scopes of the grant, so a broader stored one is replaced by a new grant instead.
            if (null === $current->refreshToken || !$current->covers($scopes) || !$this->isWithinScopeLimit($current)) {
                return null;
            }

            $token = $this->spendRefreshToken($current, $current->refreshToken, $metadata, $client, $resource);

            if (null !== $token) {
                $this->storage->saveToken($metadata->issuer, $resource, $token);
            }

            return $token;
        });
    }

    private function spendRefreshToken(AccessToken $stored, string $refreshToken, AuthorizationServerMetadata $metadata, ClientRegistration $client, string $resource): ?AccessToken
    {
        try {
            $token = $this->requestToken($metadata, $client, self::withoutEmptyValues([
                'grant_type' => Grant::RefreshToken->value,
                'refresh_token' => $refreshToken,
                'resource' => $resource,
            ]));
        } catch (AuthorizationException $e) {
            // A refused client would fail the new authorization as well, after the user signed in.
            if ($e instanceof TokenEndpointUnavailableException || self::isRefusedClient($e)) {
                throw $e;
            }

            $this->logger->info('Refreshing the access token failed; falling back to a new authorization.', ['exception' => $e]);

            return null;
        }

        // A response may leave out the refresh token and the scope, and both then stay as they were.
        return new AccessToken(
            $token->accessToken,
            $token->tokenType,
            $token->expiresAt,
            $token->refreshToken ?? $refreshToken,
            [] === $token->scopes ? $stored->scopes : $token->scopes,
        );
    }

    /**
     * Run the grant, and retry without offline_access if it was refused (invalid_scope, or not_allowed from Keycloak).
     *
     * @param string[] $scopes
     *
     * @return array{AccessToken, string[]}
     */
    private function grantWithFallback(AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes, string $resource): array
    {
        try {
            return [$this->grant($metadata, $client, $scopes, $resource), $scopes];
        } catch (AuthorizationException $e) {
            $reduced = $this->reduceScopes($scopes, $e);

            return [$this->grant($metadata, $client, $reduced, $resource), $reduced];
        }
    }

    /**
     * @param string[] $scopes
     *
     * @return string[]
     */
    private function reduceScopes(array $scopes, AuthorizationException $e): array
    {
        $reduced = array_values(array_diff($scopes, ['offline_access']));

        if ($reduced === $scopes || !$e instanceof OAuthErrorException || !\in_array($e->error, ['invalid_scope', 'not_allowed'], true)) {
            throw $e;
        }

        $this->logger->info('The authorization server refused the offline_access scope; retrying without it, at the cost of a refresh token.', ['exception' => $e]);
        $this->offlineAccessRefused = true;

        return $reduced;
    }

    /**
     * @param string[] $scopes
     */
    private function grant(AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes, string $resource): AccessToken
    {
        if (Grant::JwtBearer === $this->configuration->grant) {
            return $this->requestToken($metadata, $client, self::withoutEmptyValues([
                'grant_type' => Grant::JwtBearer->value,
                'assertion' => $this->requestAuthorizationGrant($metadata, $scopes, $resource),
                'scope' => implode(' ', $scopes),
                'resource' => $resource,
            ]));
        }

        if (Grant::ClientCredentials === $this->configuration->grant) {
            return $this->requestToken($metadata, $client, self::withoutEmptyValues([
                'grant_type' => Grant::ClientCredentials->value,
                'scope' => implode(' ', $scopes),
                'resource' => $resource,
            ]));
        }

        self::assertSupportsPkce($metadata);
        $this->assertIdentifiesItsResponses($metadata);

        $pkce = Pkce::generate();
        $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $parameters = self::withoutEmptyValues([
            'response_type' => 'code',
            'client_id' => $client->clientId,
            'redirect_uri' => $this->configuration->redirectUri,
            'state' => $state,
            'code_challenge' => $pkce->challenge,
            'code_challenge_method' => Pkce::METHOD,
            'resource' => $resource,
            'scope' => implode(' ', $scopes),
        ]);

        if (null === $metadata->authorizationEndpoint) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" has no authorization endpoint.', $metadata->issuer));
        }

        $url = $metadata->authorizationEndpoint
            .(str_contains($metadata->authorizationEndpoint, '?') ? '&' : '?')
            .http_build_query($parameters, '', '&', \PHP_QUERY_RFC3986);

        $this->logger->info('Starting the authorization code flow', ['authorization_endpoint' => $metadata->authorizationEndpoint, 'scope' => $parameters['scope'] ?? null]);

        try {
            $callback = $this->authorizationHandler->authorize($url, $this->configuration->redirectUri);
        } catch (AuthorizationPendingException $e) {
            if (!$this->storage instanceof PendingAuthorizationStorageInterface) {
                throw new AuthorizationException('A pending authorization requires PendingAuthorizationStorageInterface.');
            }

            $this->storage->savePendingAuthorization(new PendingAuthorization(
                state: $state,
                codeVerifier: $pkce->verifier,
                redirectUri: $this->configuration->redirectUri,
                resource: $resource,
                scopes: $scopes,
                metadata: $metadata,
                clientId: $client->clientId,
                expiresAt: time() + 600,
                offlineAccessRefused: $this->offlineAccessRefused,
                registration: $client->dynamic ? $client : null,
            ));

            throw $e;
        }

        $this->verifyCallback($callback, $state, $metadata);

        return $this->requestToken($metadata, $client, self::withoutEmptyValues([
            'grant_type' => Grant::AuthorizationCode->value,
            'code' => $callback['code'],
            'redirect_uri' => $this->configuration->redirectUri,
            'code_verifier' => $pkce->verifier,
            'resource' => $resource,
        ]));
    }

    /**
     * @param array<string, string> $parameters
     */
    private function requestToken(AuthorizationServerMetadata $metadata, ClientRegistration $client, array $parameters): AccessToken
    {
        return $this->tokenEndpoint->request($metadata, $client, $parameters, $this->configuration->privateKeyPem, $this->configuration->signingAlgorithm);
    }

    /**
     * Trades the identity token for a grant for this resource and authorization server.
     *
     * @param string[] $scopes
     */
    private function requestAuthorizationGrant(AuthorizationServerMetadata $metadata, array $scopes, string $resource): string
    {
        $crossAppAccess = $this->configuration->crossAppAccess;
        \assert(null !== $crossAppAccess);

        $this->logger->info('Exchanging the enterprise identity for an authorization grant', [
            'identity_provider' => $crossAppAccess->tokenEndpoint,
            'audience' => $metadata->issuer,
            'resource' => $resource,
        ]);

        $response = $this->tokenEndpoint->post($crossAppAccess->tokenEndpoint, self::withoutEmptyValues([
            'grant_type' => Grant::TokenExchange->value,
            'subject_token' => $crossAppAccess->getIdentityToken(),
            'subject_token_type' => $crossAppAccess->identityTokenType,
            'requested_token_type' => CrossAppAccess::AUTHORIZATION_GRANT_TOKEN_TYPE,
            'audience' => $metadata->issuer,
            'resource' => $resource,
            'scope' => implode(' ', $scopes),
            'client_id' => $crossAppAccess->clientId ?? $this->configuration->clientId,
            'client_secret' => $crossAppAccess->clientSecret,
        ]));

        if (!\is_string($response['access_token'] ?? null) || CrossAppAccess::AUTHORIZATION_GRANT_TOKEN_TYPE !== ($response['issued_token_type'] ?? null)) {
            throw new AuthorizationException(\sprintf('The identity provider at "%s" returned no authorization grant.', $crossAppAccess->tokenEndpoint));
        }

        return $response['access_token'];
    }

    /**
     * Check the authorization response before spending the code it carries.
     *
     * @param array<string, string> $callback
     *
     * @phpstan-assert non-empty-array{code: string} $callback
     */
    private function verifyCallback(array $callback, string $state, AuthorizationServerMetadata $metadata): void
    {
        // RFC 6749 section 10.12: required, as any local process can reach the loopback listener.
        if (!isset($callback['state']) || !hash_equals($state, $callback['state'])) {
            throw new AuthorizationException('The authorization response did not carry back the state parameter this client sent.');
        }

        // RFC 9207: compared as a string, and required if the server announced it, to catch a mix-up.
        if (isset($callback['iss'])) {
            if ($callback['iss'] !== $metadata->issuer) {
                throw new AuthorizationException(\sprintf('The authorization response names the issuer "%s", but the flow was started with "%s".', $callback['iss'], $metadata->issuer));
            }
        } elseif ($metadata->issuerParameterSupported) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" advertises the iss parameter, but its response did not carry one.', $metadata->issuer));
        }

        if (isset($callback['error'])) {
            throw new OAuthErrorException(\sprintf('The authorization server denied the request: %s%s', $callback['error'], isset($callback['error_description']) ? ' ('.$callback['error_description'].')' : ''), $callback['error']);
        }

        if (!isset($callback['code']) || '' === $callback['code']) {
            throw new AuthorizationException('The authorization response carried no authorization code.');
        }
    }

    /**
     * Work out which client identity to use with this authorization server.
     *
     * @param string[]  $scopes
     * @param ?string[] $scopesSupported
     */
    private function resolveClient(AuthorizationServerMetadata $metadata, array $scopes, ?array $scopesSupported): ClientRegistration
    {
        $configuration = $this->configuration;

        if (null !== $configuration->clientId) {
            return new ClientRegistration(
                $configuration->clientId,
                $configuration->clientSecret,
                TokenEndpointAuthMethod::negotiate(
                    $metadata->tokenEndpointAuthMethodsSupported,
                    null !== $configuration->clientSecret,
                    null !== $configuration->privateKeyPem,
                    Grant::ClientCredentials !== $configuration->grant,
                ),
            );
        }

        if ($metadata->clientIdMetadataDocumentSupported && null !== $configuration->clientMetadataUrl) {
            $this->logger->debug('Using the client metadata document URL as the client id', ['client_id' => $configuration->clientMetadataUrl]);

            return new ClientRegistration($configuration->clientMetadataUrl, null, TokenEndpointAuthMethod::negotiate($metadata->tokenEndpointAuthMethodsSupported, false));
        }

        // Another process may register at the same time, and its tokens would not work with this client.
        return $this->storage->synchronize(function () use ($metadata, $configuration, $scopes, $scopesSupported): ClientRegistration {
            $stored = $this->storage->getClientRegistration($metadata->issuer);

            if (null !== $stored && !$stored->isExpired() && $this->matchesRedirectUris($stored)
                && (null === $stored->scopes || [] === array_diff($scopes, $stored->scopes))
            ) {
                return $stored;
            }

            self::assertSupportsPkce($metadata);
            $this->assertIdentifiesItsResponses($metadata);

            // A registration limits what the client may ever request (RFC 7591 section 2), so it covers what it may ask for later.
            $registered = array_values(array_unique([...$stored->scopes ?? [], ...$scopes, ...array_diff($scopesSupported ?? [], ['offline_access'])]));

            $registration = $this->registrar->register(
                $metadata,
                $configuration->clientMetadata,
                TokenEndpointAuthMethod::negotiate($metadata->tokenEndpointAuthMethodsSupported, true),
                [] === $registered ? null : implode(' ', $registered),
            );

            $this->storage->saveClientRegistration($metadata->issuer, $registration);

            return $registration;
        });
    }

    /**
     * @param array<string, ?string> $parameters
     *
     * @return array<string, string>
     */
    private static function withoutEmptyValues(array $parameters): array
    {
        $values = [];

        foreach ($parameters as $name => $value) {
            if (null !== $value && '' !== $value) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * Refuse an authorization endpoint that may belong to another server, unless its responses can prove they don't.
     *
     * A hostile server can name the endpoint of a real one, and keep the token endpoint. The user signs in at the real
     * address, and the code goes to the hostile server unless the response names its issuer (RFC 9700 section 4.4).
     */
    private function assertIdentifiesItsResponses(AuthorizationServerMetadata $metadata): void
    {
        if (null !== $this->configuration->expectedIssuer
            || $metadata->issuerParameterSupported
            || null === $metadata->authorizationEndpoint
            || self::origin($metadata->authorizationEndpoint) === self::origin($metadata->issuer)
        ) {
            return;
        }

        throw new AuthorizationException(\sprintf('The authorization server "%s" has its authorization endpoint "%s" on another origin and does not announce the iss parameter, so a response could come from another server. Call OAuth::setExpectedIssuer() if you trust this authorization server.', $metadata->issuer, $metadata->authorizationEndpoint));
    }

    private static function assertSupportsPkce(AuthorizationServerMetadata $metadata): void
    {
        if (!$metadata->supportsPkce()) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" does not list "%s" in "code_challenge_methods_supported", so the client cannot use PKCE with it.', $metadata->issuer, Pkce::METHOD));
        }
    }

    private function matchesRedirectUris(ClientRegistration $registration): bool
    {
        return [] === $registration->redirectUris || $registration->redirectUris === $this->configuration->clientMetadata->redirectUris;
    }

    /**
     * The canonical URI a token is requested for, without a fragment.
     */
    private static function canonicalize(string $url): string
    {
        $parts = parse_url($url);

        if (!\is_array($parts)) {
            return $url;
        }

        return self::origin($url).($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        if (!\is_array($parts)) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $origin = $scheme.'://'.strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;

        if (null === $port || $port === (['https' => 443, 'http' => 80][$scheme] ?? null)) {
            return $origin;
        }

        return $origin.':'.$port;
    }
}
