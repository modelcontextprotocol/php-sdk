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
 * A successful token endpoint response (RFC 6749 Section 5.1).
 */
final class TokenResponse
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly int $expiresIn,
        public readonly array $scopes = [],
        public readonly ?string $refreshToken = null,
        public readonly string $tokenType = 'Bearer',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'access_token' => $this->accessToken,
            'token_type' => $this->tokenType,
            'expires_in' => $this->expiresIn,
        ];

        if ([] !== $this->scopes) {
            $data['scope'] = implode(' ', $this->scopes);
        }

        if (null !== $this->refreshToken) {
            $data['refresh_token'] = $this->refreshToken;
        }

        return $data;
    }
}
