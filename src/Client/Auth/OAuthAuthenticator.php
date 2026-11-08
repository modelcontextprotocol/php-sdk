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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drives the full MCP authorization flow on behalf of an HTTP client.
 *
 * Nothing happens until a server says no. The first request goes out bare; the 401 that
 * comes back names the resource's metadata, which names the authorization server, which
 * is asked for a client identity, an authorization code, and finally a token. Every step
 * after the first is skipped whenever what it would produce is already on hand, so a
 * warm client sends one request and a cold one sends the whole chain exactly once.
 *
 * Discovery is the exception: it runs again on every challenge rather than being
 * remembered. A resource that has moved to a different authorization server announces it
 * in exactly that document, and noticing is what stops one server's credentials from
 * being offered to another.
 *
 * Build one with {@see OAuth} rather than by hand.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class OAuthAuthenticator implements AuthenticatorInterface
{
    private ?string $issuer = null;
    private ?string $resource = null;
    private ?AccessToken $token = null;

    /**
     * The resource the current token may be sent to.
     *
     * Distinct from $resource, which is what was asked for: an application that
     * overrides the RFC 8707 resource parameter is choosing which token to obtain, not
     * granting itself permission to spend it somewhere else.
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

        // A token is minted for one resource and means nothing at another, so the
        // request has to be aimed at the resource this one was obtained for. Normally it
        // is -- the transport builds one authenticator per endpoint -- but an
        // authenticator is an ordinary object a caller may reuse, and the cost of one
        // mistake there is handing a hostile server somebody else's token.
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

        [$resource, $audience, $metadata, $scopesSupported] = $this->discover($endpoint, $challenge);

        if (null !== $this->configuration->expectedIssuer && $metadata->issuer !== $this->configuration->expectedIssuer) {
            throw new AuthorizationException(\sprintf('The server at "%s" names the authorization server "%s", but only "%s" is expected.', $endpoint, $metadata->issuer, $this->configuration->expectedIssuer));
        }

        if ($metadata->issuer !== $this->issuer) {
            // A different authorization server is a different world: its predecessor's
            // client id and tokens are not merely stale there, presenting them would
            // hand one server credentials that belong to another.
            $this->logger->info('Authorization server changed', ['from' => $this->issuer, 'to' => $metadata->issuer]);
            $this->token = null;
            $this->requestedScopes = [];
            $this->offlineAccessRefused = false;
        }

        $this->issuer = $metadata->issuer;
        $this->resource = $resource;
        $this->audience = $audience;

        $scopes = $this->selectScopes($challenge, $scopesSupported, $metadata);
        // The resource never requires offline_access, which only gets a refresh token.
        $needed = array_values(array_diff($scopes, ['offline_access']));
        $stored = $this->storage->getToken($metadata->issuer, $resource);

        if (null !== $stored && $this->isUsable($stored, $needed, $request)) {
            $this->logger->debug('Reusing a stored access token', ['issuer' => $metadata->issuer, 'resource' => $resource]);
            $this->token = $stored;

            return true;
        }

        $client = $this->resolveClient($metadata, $scopes);
        $token = $this->refresh($stored, $metadata, $client, $needed);

        if (null === $token) {
            [$token, $scopes] = $this->grantWithFallback($metadata, $client, $scopes, $resource);
        }

        $this->token = $token->withGrantedScopes($scopes);
        $this->requestedScopes = $scopes;
        $this->storage->saveToken($metadata->issuer, $resource, $this->token);

        return true;
    }

    /**
     * Work out which resource is being protected, and by whom.
     *
     * Two resource identifiers come out of this, and they are not always the same one.
     * The first is what goes in the RFC 8707 `resource` parameter, which an application
     * may override. The second is the resource this server actually is, and it is what
     * the resulting token may be sent to -- an override says which token to ask for, not
     * where it is safe to spend.
     *
     * @return array{string, string, AuthorizationServerMetadata, ?string[]}
     */
    private function discover(string $endpoint, AuthorizationChallenge $challenge): array
    {
        $metadata = $this->discovery->discoverProtectedResource($endpoint, self::trustedMetadataUrl($endpoint, $challenge));

        if (null === $metadata) {
            return [$this->configuration->resource ?? $endpoint, $endpoint, $this->discoverLegacyServer($endpoint), null];
        }

        if (!$metadata->covers($endpoint)) {
            // The document claims tokens for somewhere else. Following it would mean
            // asking an authorization server for a token bound to a resource the client
            // is not talking to, and then sending it to one that is -- the exact shape
            // of a confused-deputy attack.
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" declares the unrelated resource "%s"; refusing to authorize against it.', $endpoint, $metadata->resource));
        }

        $issuer = $metadata->authorizationServers[0] ?? null;

        if (null === $issuer) {
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" names no authorization server.', $endpoint));
        }

        // Checked before the document is fetched, not after: this identifier is a string
        // the server chose, and building well-known URLs out of it and requesting them is
        // already a request made on the server's behalf, from wherever the client runs.
        if (!AuthorizationServerMetadata::isTransportSecure($issuer)) {
            throw new AuthorizationException(\sprintf('The protected resource metadata for "%s" names "%s" as its authorization server, which is neither an HTTPS URL nor a loopback address.', $endpoint, $issuer));
        }

        $server = $this->discovery->discoverAuthorizationServer($issuer);

        if (null === $server) {
            throw new AuthorizationException(\sprintf('No usable metadata was found for the authorization server "%s".', $issuer));
        }

        return [$this->configuration->resource ?? $metadata->resource, $metadata->resource, $server, $metadata->scopesSupported];
    }

    /**
     * The metadata location named by the challenge, but only when it belongs to the
     * server that issued the challenge.
     *
     * RFC 9728 derives the location from the resource identifier, so a document that
     * describes this resource lives on this resource. A challenge pointing anywhere else
     * is asking the client to make a request on the server's behalf -- to a host the
     * server picked, from wherever the client happens to run, which may be inside a
     * network the server cannot reach itself. Ignoring it costs nothing: the well-known
     * locations are probed instead, exactly as for a challenge that named none.
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
     * Fall back to how authorization was discovered before protected resource metadata existed.
     *
     * Servers built against the 2025-03-26 revision act as their own authorization
     * server, either publishing metadata at their root or exposing nothing at all and
     * relying on the fixed endpoint names that revision spelled out.
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
     * Decide what to ask for.
     *
     * The challenge is the most specific thing available and wins; failing that the
     * resource's own list is asked for in full; failing that the parameter is left off
     * entirely, because inventing scopes only produces a rejection. Whatever comes out
     * is unioned with what was previously granted, so a step-up challenge naming only
     * the missing scope does not quietly drop the ones already held.
     *
     * Configured scopes replace that choice. An insufficient_scope challenge still adds
     * the scopes it names, or the new token would be refused again.
     *
     * @param ?string[] $scopesSupported
     *
     * @return string[]
     */
    private function selectScopes(AuthorizationChallenge $challenge, ?array $scopesSupported, AuthorizationServerMetadata $metadata): array
    {
        $scopes = $this->configuration->scopes;

        if ([] === $scopes) {
            $scopes = $challenge->getScopes() ?: ($scopesSupported ?? []);
        } elseif ('insufficient_scope' === $challenge->getError()) {
            $scopes = [...$scopes, ...$challenge->getScopes()];
        }

        $scopes = array_values(array_unique([...$this->requestedScopes, ...$scopes]));

        if ($this->offlineAccessRefused) {
            return array_values(array_diff($scopes, ['offline_access']));
        }

        // A refresh token is only worth asking for where the authorization server says
        // it understands the request; sending an unknown scope is an error, not a hint.
        if ($this->configuration->offlineAccess
            && $metadata->supportsScope('offline_access')
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
        // The request that was just rejected carried this very token, so the server has
        // already given its answer about it.
        if ($request->getHeaderLine('Authorization') === $token->getAuthorizationHeader()) {
            return false;
        }

        return !$token->isExpired() && $token->covers($scopes);
    }

    /**
     * Trade a refresh token for a new access token, when there is one and it is enough.
     *
     * A step-up challenge asks for scopes the refresh token was never granted, and RFC
     * 6749 will not widen a grant on refresh, so that case falls through to a fresh
     * authorization instead.
     *
     * @param string[] $scopes
     */
    private function refresh(?AccessToken $stored, AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes): ?AccessToken
    {
        if (null === $stored || null === $stored->refreshToken || !$stored->covers($scopes) || !$metadata->supportsGrant(Grant::RefreshToken->value)) {
            return null;
        }

        try {
            $token = $this->tokenEndpoint->request($metadata, $client, array_filter([
                'grant_type' => Grant::RefreshToken->value,
                'refresh_token' => $stored->refreshToken,
                'resource' => $this->resource,
            ]));
        } catch (AuthorizationException $e) {
            $this->logger->info('Refreshing the access token failed; falling back to a new authorization.', ['exception' => $e]);

            return null;
        }

        // A server may rotate the refresh token, or leave the old one in place.
        return null === $token->refreshToken
            ? new AccessToken($token->accessToken, $token->tokenType, $token->expiresAt, $stored->refreshToken, $token->scopes)
            : $token;
    }

    /**
     * Run the grant, giving up the optional refresh-token scope if that is what stood
     * in the way.
     *
     * `offline_access` is asked for on the strength of the authorization server saying
     * it understands the scope, but understanding it and being willing to grant it to
     * this client for this user are different questions, and only the second one gets
     * answered here. Everything else the client asked for was named by the resource or
     * by the challenge, so there is nothing else to concede.
     *
     * The refusal comes from the authorization endpoint, as invalid_scope, or from the
     * token endpoint when the code is exchanged. Keycloak does the latter, with
     * not_allowed, for a user who may not have offline tokens.
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
            $reduced = array_values(array_diff($scopes, ['offline_access']));

            if ($reduced === $scopes || (!str_contains($e->getMessage(), 'invalid_scope') && !str_contains($e->getMessage(), 'not_allowed'))) {
                throw $e;
            }

            $this->logger->info('The authorization server refused the offline_access scope; retrying without it, at the cost of a refresh token.', ['exception' => $e]);
            $this->offlineAccessRefused = true;

            return [$this->grant($metadata, $client, $reduced, $resource), $reduced];
        }
    }

    /**
     * @param string[] $scopes
     */
    private function grant(AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes, string $resource): AccessToken
    {
        if (!$metadata->supportsPkce()) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" does not list "%s" in "code_challenge_methods_supported", so the client cannot use PKCE with it.', $metadata->issuer, Pkce::METHOD));
        }

        $pkce = Pkce::generate();
        $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        $parameters = array_filter([
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

        $callback = $this->authorizationHandler->authorize($url, $this->configuration->redirectUri);

        $this->verifyCallback($callback, $state, $metadata);

        return $this->tokenEndpoint->request($metadata, $client, array_filter([
            'grant_type' => Grant::AuthorizationCode->value,
            'code' => $callback['code'],
            'redirect_uri' => $this->configuration->redirectUri,
            'code_verifier' => $pkce->verifier,
            'resource' => $resource,
        ]));
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
        if (isset($callback['error'])) {
            throw new AuthorizationException(\sprintf('The authorization server denied the request: %s%s', $callback['error'], isset($callback['error_description']) ? ' ('.$callback['error_description'].')' : ''));
        }

        // A response that did not carry back the state this client sent did not come from
        // the request this client made (RFC 6749 section 10.12). Required, not merely
        // checked when present: the loopback listener accepts a connection from any local
        // process, and an absent state would let one of them feed the client a code.
        if (!isset($callback['state']) || !hash_equals($state, $callback['state'])) {
            throw new AuthorizationException('The authorization response did not carry back the state parameter this client sent.');
        }

        // RFC 9207: where the issuer is named it must be the one the flow was started
        // with, compared as a string with no normalisation -- the whole point is to
        // catch a response mixed in from a second, attacker-controlled server. Where the
        // server said it would name one, its absence is just as suspicious.
        if (isset($callback['iss'])) {
            if ($callback['iss'] !== $metadata->issuer) {
                throw new AuthorizationException(\sprintf('The authorization response names the issuer "%s", but the flow was started with "%s".', $callback['iss'], $metadata->issuer));
            }
        } elseif ($metadata->issuerParameterSupported) {
            throw new AuthorizationException(\sprintf('The authorization server "%s" advertises the iss parameter, but its response did not carry one.', $metadata->issuer));
        }

        if (!isset($callback['code']) || '' === $callback['code']) {
            throw new AuthorizationException('The authorization response carried no authorization code.');
        }
    }

    /**
     * Work out which client identity to use with this authorization server.
     *
     * @param string[] $scopes
     */
    private function resolveClient(AuthorizationServerMetadata $metadata, array $scopes): ClientRegistration
    {
        $configuration = $this->configuration;

        if (null !== $configuration->clientId) {
            return new ClientRegistration(
                $configuration->clientId,
                $configuration->clientSecret,
                TokenEndpointAuthMethod::negotiate(
                    $metadata->tokenEndpointAuthMethodsSupported,
                    null !== $configuration->clientSecret,
                ),
            );
        }

        $stored = $this->storage->getClientRegistration($metadata->issuer);

        if (null !== $stored && !$stored->isExpired()) {
            return $stored;
        }

        $registration = $this->registrar->register(
            $metadata,
            $configuration->clientMetadata,
            TokenEndpointAuthMethod::negotiate($metadata->tokenEndpointAuthMethodsSupported, true),
            [] === $scopes ? null : implode(' ', $scopes),
        );

        $this->storage->saveClientRegistration($metadata->issuer, $registration);

        return $registration;
    }

    /**
     * The canonical URI a token is requested for: no fragment, and nothing a server
     * would not recognise as its own identity.
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

        $origin = strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? ''));

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }
}
