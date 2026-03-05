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
 * Contract for resolving OAuth/OIDC endpoint metadata from an issuer.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
interface OidcDiscoveryInterface
{
    /**
     * @return array<string, mixed>
     */
    public function discover(string $issuer): array;

    public function getAuthorizationEndpoint(string $issuer): string;

    public function getTokenEndpoint(string $issuer): string;

    public function getJwksUri(string $issuer): string;
}
