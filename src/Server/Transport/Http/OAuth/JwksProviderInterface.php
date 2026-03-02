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
 * Contract for loading JWKS key sets used for JWT signature verification.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
interface JwksProviderInterface
{
    /**
     * @param string      $issuer  authorization server issuer URL
     * @param string|null $jwksUri Optional explicit JWKS URI. If null, implementation may resolve via discovery.
     *
     * @return array<string, mixed>
     */
    public function getJwks(string $issuer, ?string $jwksUri = null): array;
}
