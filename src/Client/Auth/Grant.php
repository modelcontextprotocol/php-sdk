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
 * The OAuth grant types this SDK can drive.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum Grant: string
{
    /** A user approves the request in a browser; the default for anything with a human behind it. */
    case AuthorizationCode = 'authorization_code';

    /** The client acts as itself, with no user involved -- daemons, cron jobs, service accounts. */
    case ClientCredentials = 'client_credentials';

    /** Trades a still-valid refresh token for a fresh access token. */
    case RefreshToken = 'refresh_token';

    /** Exchanges a token the client already holds for one scoped to this resource (RFC 8693). */
    case TokenExchange = 'urn:ietf:params:oauth:grant-type:token-exchange';

    /** Presents an assertion signed by a party the authorization server trusts (RFC 7523). */
    case JwtBearer = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
}
