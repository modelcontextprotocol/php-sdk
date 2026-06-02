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
 * The authenticated end user (resource owner) on whose behalf an authorization
 * code is issued. The id becomes the JWT "sub" claim; claims are optional extra
 * data the host may want carried into the token.
 */
final class ResourceOwner
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public readonly string $id,
        public readonly array $claims = [],
    ) {
    }
}
