<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthMicrosoft;

use Mcp\Server\Transport\Http\OAuth\OidcDiscoveryMetadataPolicyInterface;

/**
 * Lenient metadata policy for Microsoft discovery quirks.
 *
 * `code_challenge_methods_supported` is optional in this policy.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
final class MicrosoftOidcMetadataPolicy implements OidcDiscoveryMetadataPolicyInterface
{
    public function isValid(mixed $metadata): bool
    {
        return \is_array($metadata)
            && isset($metadata['authorization_endpoint'], $metadata['token_endpoint'], $metadata['jwks_uri'])
            && \is_string($metadata['authorization_endpoint'])
            && '' !== trim($metadata['authorization_endpoint'])
            && \is_string($metadata['token_endpoint'])
            && '' !== trim($metadata['token_endpoint'])
            && \is_string($metadata['jwks_uri'])
            && '' !== trim($metadata['jwks_uri']);
    }
}
