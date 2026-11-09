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
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
enum Grant: string
{
    /** A user approves the request in a browser; the default for anything with a human behind it. */
    case AuthorizationCode = 'authorization_code';

    /** Trades a still-valid refresh token for a fresh access token. */
    case RefreshToken = 'refresh_token';
}
