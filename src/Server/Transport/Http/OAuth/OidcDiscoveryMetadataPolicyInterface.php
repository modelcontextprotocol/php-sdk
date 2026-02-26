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
 * Validation policy for OIDC discovery metadata.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
interface OidcDiscoveryMetadataPolicyInterface
{
    public function isValid(mixed $metadata): bool;
}
