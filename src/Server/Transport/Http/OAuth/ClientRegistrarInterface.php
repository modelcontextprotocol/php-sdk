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

use Mcp\Exception\ClientRegistrationException;

/**
 * Interface for OAuth 2.0 Dynamic Client Registration (RFC 7591).
 */
interface ClientRegistrarInterface
{
    /**
     * @param array<string, mixed> $registrationRequest
     *
     * @return array<string, mixed>
     *
     * @throws ClientRegistrationException
     */
    public function register(array $registrationRequest): array;
}
