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

/**
 * Reaching an MCP server through the identity the user already has at work.
 *
 * In an enterprise the user has signed in once, to the company identity provider, and
 * an administrator has decided which applications may act for them where. Cross-app
 * access turns that decision into tokens without another consent screen: the client
 * trades the identity token it already holds for an authorization grant scoped to one
 * MCP server (RFC 8693), then presents that grant to the server's authorization server
 * as an assertion (RFC 7523).
 *
 * The identity token itself comes from wherever the application signs its users in --
 * this SDK never obtains it.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CrossAppAccess
{
    /** The identity assertion authorization grant the identity provider mints. */
    public const AUTHORIZATION_GRANT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id-jag';

    public const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';

    /**
     * @param string  $tokenEndpoint     the identity provider's token endpoint
     * @param string  $identityToken     the token identifying the user, usually an OIDC ID token
     * @param string  $identityTokenType the type of that token, as RFC 8693 names it
     * @param ?string $clientId          the client's identifier at the identity provider, when it
     *                                   differs from its identifier at the authorization server
     */
    public function __construct(
        public readonly string $tokenEndpoint,
        public readonly string $identityToken,
        public readonly string $identityTokenType = self::ID_TOKEN_TYPE,
        public readonly ?string $clientId = null,
    ) {
    }
}
