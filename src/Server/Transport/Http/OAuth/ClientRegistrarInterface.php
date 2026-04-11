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
 *
 * Implementations are responsible for persisting client credentials and
 * returning a registration response as defined in RFC 7591 Section 3.2.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc7591
 */
interface ClientRegistrarInterface
{
    /**
     * Registers a new OAuth 2.0 client.
     *
     * The registration request contains metadata fields as defined in RFC 7591
     * Section 2 (e.g. redirect_uris, client_name, token_endpoint_auth_method).
     *
     * The returned array MUST include at least "client_id" and should include
     * "client_secret" when the token endpoint auth method requires one.
     *
     * @param array<string, mixed> $registrationRequest Client metadata from the registration request body
     *
     * @return array<string, mixed> Registration response including client_id and optional client_secret
     *
     * @throws ClientRegistrationException If registration fails (e.g. invalid metadata, storage error).
     *                                     The exception message is returned to the client as error_description —
     *                                     do not include internal details (database errors, stack traces, etc.).
     */
    public function register(array $registrationRequest): array;
}
