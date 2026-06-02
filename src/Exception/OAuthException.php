<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Exception;

/**
 * OAuth 2.0 protocol error (RFC 6749 Section 5.2 / RFC 6750).
 *
 * Carries the OAuth error code and the HTTP status code that the authorization
 * server endpoints should respond with. The message is the human-readable
 * error_description returned to the client — never include internal details.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc6749#section-5.2
 */
class OAuthException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $error,
        string $errorDescription,
        public readonly int $httpStatus = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorDescription, 0, $previous);
    }

    public static function invalidRequest(string $description): self
    {
        return new self('invalid_request', $description, 400);
    }

    public static function invalidClient(string $description): self
    {
        return new self('invalid_client', $description, 401);
    }

    public static function invalidGrant(string $description): self
    {
        return new self('invalid_grant', $description, 400);
    }

    public static function unauthorizedClient(string $description): self
    {
        return new self('unauthorized_client', $description, 400);
    }

    public static function unsupportedGrantType(string $description): self
    {
        return new self('unsupported_grant_type', $description, 400);
    }

    public static function invalidScope(string $description): self
    {
        return new self('invalid_scope', $description, 400);
    }

    public static function serverError(string $description): self
    {
        return new self('server_error', $description, 500);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return [
            'error' => $this->error,
            'error_description' => $this->getMessage(),
        ];
    }
}
