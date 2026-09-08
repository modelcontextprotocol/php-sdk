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

    /** @var string[] The scopes the last authorization asked for, which a step-up challenge adds to. */
    private array $requestedScopes = [];

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
        if (null === $this->resource || !ProtectedResourceMetadata::isWithin(self::canonicalize((string) $request->getUri()), $this->resource)) {
            return $request;
        }

        return $request->withHeader('Authorization', $this->token->getAuthorizationHeader());
    }

    public function handleChallenge(RequestInterface $request, ResponseInterface $response): bool
    {
        $challenge = AuthorizationChallenge::fromResponse($response);
        $endpoint = self::canonicalize((string) $request->getUri());

        [$resource, $metadata, $scopesSupported] = $this->discover($endpoint, $challenge);

        if ($metadata->issuer !== $this->issuer) {
            // A different authorization server is a different world: its predecessor's
            // client id and tokens are not merely stale there, presenting them would
            // hand one server credentials that belong to another.
            $this->logger->info('Authorization server changed', ['from' => $this->issuer, 'to' => $metadata->issuer]);
            $this->token = null;
            $this->requestedScopes = [];
        }

        $this->issuer = $metadata->issuer;
        $this->resource = $resource;

        $scopes = $this->selectScopes($challenge, $scopesSupported, $metadata);
        $stored = $this->storage->getToken($metadata->issuer, $resource);

        if (null !== $stored && $this->isUsable($stored, $scopes, $request)) {
            $this->logger->debug('Reusing a stored access token', ['issuer' => $metadata->issuer, 'resource' => $resource]);
            $this->token = $stored;

            return true;
        }

        $client = $this->resolveClient($metadata, $scopes);
        $token = $this->refresh($stored, $metadata, $client, $scopes);

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
     * @return array{string, AuthorizationServerMetadata, ?string[]}
     */
    private function discover(string $endpoint, AuthorizationChallenge $challenge): array
    {
        $metadata = $this->discovery->discoverProtectedResource($endpoint, self::trustedMetadataUrl($endpoint, $challenge));

        if (null === $metadata) {
            return [$this->configuration->resource ?? $endpoint, $this->discoverLegacyServer($endpoint), null];
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

        $server = $this->discovery->discoverAuthorizationServer($issuer);

        if (null === $server) {
            throw new AuthorizationException(\sprintf('No usable metadata was found for the authorization server "%s".', $issuer));
        }

        return [$this->configuration->resource ?? $metadata->resource, $server, $metadata->scopesSupported];
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
            throw new AuthorizationException(\sprintf('The server at "%s" published no protected resource metadata, and discovery of pre-2025-06-18 servers is disabled.', $endpoint));
        }

        $issuer = self::origin($endpoint);
        $this->logger->info('No protected resource metadata; falling back to treating the server as its own authorization server.', ['issuer' => $issuer]);

        return $this->discovery->discoverAuthorizationServer($issuer) ?? AuthorizationServerMetadata::forLegacyServer($issuer);
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
     * @param ?string[] $scopesSupported
     *
     * @return string[]
     */
    private function selectScopes(AuthorizationChallenge $challenge, ?array $scopesSupported, AuthorizationServerMetadata $metadata): array
    {
        $scopes = $this->configuration->scopes;

        if ([] === $scopes) {
            $scopes = $challenge->getScopes() ?: ($scopesSupported ?? []);
        }

        $scopes = array_values(array_unique([...$this->requestedScopes, ...$scopes]));

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
            ]), $this->configuration->privateKeyPem, $this->configuration->signingAlgorithm);
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

            if ($reduced === $scopes || !str_contains($e->getMessage(), 'invalid_scope')) {
                throw $e;
            }

            $this->logger->info('The authorization server refused the offline_access scope; retrying without it, at the cost of a refresh token.', ['exception' => $e]);

            return [$this->grant($metadata, $client, $reduced, $resource), $reduced];
        }
    }

    /**
     * @param string[] $scopes
     */
    private function grant(AuthorizationServerMetadata $metadata, ClientRegistration $client, array $scopes, string $resource): AccessToken
    {
        if (Grant::JwtBearer === $this->configuration->grant) {
            return $this->tokenEndpoint->request($metadata, $client, array_filter([
                'grant_type' => Grant::JwtBearer->value,
                'assertion' => $this->requestAuthorizationGrant($metadata, $resource),
                'scope' => implode(' ', $scopes),
                'resource' => $resource,
            ]), $this->configuration->privateKeyPem, $this->configuration->signingAlgorithm);
        }

        if (Grant::ClientCredentials === $this->configuration->grant) {
            return $this->tokenEndpoint->request($metadata, $client, array_filter([
                'grant_type' => Grant::ClientCredentials->value,
                'scope' => implode(' ', $scopes),
                'resource' => $resource,
            ]), $this->configuration->privateKeyPem, $this->configuration->signingAlgorithm);
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
        ]), $this->configuration->privateKeyPem, $this->configuration->signingAlgorithm);
    }

    /**
     * Trade the user's existing enterprise identity for a grant this server will accept.
     *
     * The identity provider is the one deciding whether this client may act for this
     * user at this resource, so the resource and the authorization server are both named
     * in the exchange (RFC 8693 section 2.1); what comes back is scoped to exactly that
     * pair and is useless anywhere else.
     */
    private function requestAuthorizationGrant(AuthorizationServerMetadata $metadata, string $resource): string
    {
        $crossAppAccess = $this->configuration->crossAppAccess;

        if (null === $crossAppAccess) {
            throw new AuthorizationException('No cross-app access identity is configured.');
        }

        $this->logger->info('Exchanging the enterprise identity for an authorization grant', [
            'identity_provider' => $crossAppAccess->tokenEndpoint,
            'audience' => $metadata->issuer,
            'resource' => $resource,
        ]);

        $response = $this->tokenEndpoint->post($crossAppAccess->tokenEndpoint, array_filter([
            'grant_type' => Grant::TokenExchange->value,
            'subject_token' => $crossAppAccess->identityToken,
            'subject_token_type' => $crossAppAccess->identityTokenType,
            'requested_token_type' => CrossAppAccess::AUTHORIZATION_GRANT_TOKEN_TYPE,
            'audience' => $metadata->issuer,
            'resource' => $resource,
            'client_id' => $crossAppAccess->clientId,
        ]));

        if (!\is_string($response['access_token'] ?? null)) {
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
                    null !== $configuration->privateKeyPem,
                ),
            );
        }

        // A client that can publish its metadata at a URL does not need to register at
        // all: the URL is the client id, and every server it meets reads the same
        // document instead of minting another identity for it.
        if ($metadata->clientIdMetadataDocumentSupported && null !== $configuration->clientMetadataUrl) {
            $this->logger->debug('Using the client metadata document URL as the client id', ['client_id' => $configuration->clientMetadataUrl]);

            return new ClientRegistration($configuration->clientMetadataUrl, null, TokenEndpointAuthMethod::negotiate($metadata->tokenEndpointAuthMethodsSupported, false));
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
