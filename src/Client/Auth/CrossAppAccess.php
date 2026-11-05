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

use Mcp\Exception\InvalidArgumentException;

/**
 * Reaches an MCP server through the identity a user already has at their company.
 *
 * The client trades the identity token for an authorization grant for one MCP server
 * (RFC 8693) and presents that grant to the authorization server (RFC 7523). The
 * application gets the identity token from its own sign-in.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CrossAppAccess
{
    public const AUTHORIZATION_GRANT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id-jag';

    public const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';

    /**
     * @param string  $tokenEndpoint     the token endpoint of the identity provider
     * @param string  $identityToken     the token identifying the user, usually an OIDC ID token
     * @param string  $identityTokenType the RFC 8693 type of that token
     * @param ?string $clientId          the client id at the identity provider, when it differs
     *                                   from the client id at the authorization server
     *
     * @throws InvalidArgumentException when the token endpoint is neither https nor loopback
     */
    public function __construct(
        public readonly string $tokenEndpoint,
        public readonly string $identityToken,
        public readonly string $identityTokenType = self::ID_TOKEN_TYPE,
        public readonly ?string $clientId = null,
    ) {
        if (!AuthorizationServerMetadata::isTransportSecure($tokenEndpoint)) {
            throw new InvalidArgumentException(\sprintf('The identity provider token endpoint "%s" is neither an HTTPS URL nor a loopback address.', $tokenEndpoint));
        }
    }
}
