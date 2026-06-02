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

/**
 * A key used to sign access tokens and to publish the corresponding public key
 * as a JWK at the JWKS endpoint.
 */
interface SigningKeyInterface
{
    /**
     * The "kid" advertised in the JWKS and the token header.
     */
    public function getKeyId(): string;

    /**
     * PEM-encoded private key passed to the signer.
     */
    public function getPrivateKeyPem(): string;

    /**
     * The public key as a JWK (RFC 7517), including "kid", "alg" and "use".
     *
     * @return array<string, mixed>
     */
    public function getPublicJwk(): array;
}
