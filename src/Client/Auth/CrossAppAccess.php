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

/**
 * Reaches an MCP server through the identity a user already has at their company.
 *
 * The client trades the identity token for an authorization grant for one MCP server
 * (RFC 8693) and presents that grant to the authorization server (RFC 7523). The
 * application gets the identity token from its own sign-in; pass a closure to hand
 * over a fresh one each time the client needs a new grant.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CrossAppAccess
{
    public const AUTHORIZATION_GRANT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id-jag';

    public const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';

    /**
     * @param string                    $tokenEndpoint     the token endpoint of the identity provider
     * @param string|\Closure(): string $identityToken     the token identifying the user, usually an OIDC ID token
     * @param string                    $identityTokenType the RFC 8693 type of that token
     * @param ?string                   $clientId          the client id at the identity provider, when it differs
     *                                                     from the client id at the authorization server
     * @param ?string                   $clientSecret      the client secret at the identity provider, when it requires one
     *
     * @throws InvalidArgumentException when the token endpoint is neither https nor loopback
     */
    public function __construct(
        public readonly string $tokenEndpoint,
        private readonly string|\Closure $identityToken,
        public readonly string $identityTokenType = self::ID_TOKEN_TYPE,
        public readonly ?string $clientId = null,
        public readonly ?string $clientSecret = null,
    ) {
        if (!AuthorizationServerMetadata::isTransportSecure($tokenEndpoint)) {
            throw new InvalidArgumentException(\sprintf('The identity provider token endpoint "%s" is neither an HTTPS URL nor a loopback address.', $tokenEndpoint));
        }
    }

    /**
     * @throws AuthorizationException when the closure returns no token
     */
    public function getIdentityToken(): string
    {
        if (\is_string($this->identityToken)) {
            return $this->identityToken;
        }

        $token = ($this->identityToken)();

        if (!\is_string($token) || '' === $token) {
            throw new AuthorizationException('The identity token closure must return a non-empty string.');
        }

        return $token;
    }
}
