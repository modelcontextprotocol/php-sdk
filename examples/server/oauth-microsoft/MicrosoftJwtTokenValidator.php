<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Example\Server\OAuthMicrosoft;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;

/**
 * Validates Microsoft Entra ID access tokens.
 *
 * This validator supports two Microsoft token modes:
 * - Standard JWT validation via JWKS (delegated/client credentials tokens)
 * - Claim-based Microsoft Graph token validation for tokens with a "nonce" header
 *
 * Security notice:
 * Tokens treated as Graph tokens (header contains "nonce") are validated by
 * claims only in this example and are NOT signature-verified.
 * This mode is intended for local/demo interoperability only and should not be
 * used as-is in production deployments.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class MicrosoftJwtTokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param JwtTokenValidator $jwtTokenValidator      Base JWT validator used for non-Graph tokens
     * @param string            $scopeClaim             Claim name for scopes in Graph tokens
     * @param list<string>      $trustedGraphIssuers    Allowed Graph issuer host markers
     * @param int               $notBeforeLeewaySeconds Allowed clock skew for "nbf" claim
     */
    public function __construct(
        private readonly JwtTokenValidator $jwtTokenValidator,
        private readonly string $scopeClaim = 'scp',
        private readonly array $trustedGraphIssuers = ['sts.windows.net', 'login.microsoftonline.com'],
        private readonly int $notBeforeLeewaySeconds = 60,
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        $parts = explode('.', $accessToken);
        if (!$this->isGraphToken($parts)) {
            return $this->jwtTokenValidator->validate($accessToken);
        }

        return $this->validateGraphToken($parts);
    }

    /**
     * Validates a token has the required scopes.
     *
     * Use this after validation to check specific scope requirements.
     *
     * @param AuthorizationResult $result         The result from validate()
     * @param list<string>        $requiredScopes Scopes required for this operation
     *
     * @return AuthorizationResult The original result if scopes are sufficient, forbidden otherwise
     */
    public function requireScopes(AuthorizationResult $result, array $requiredScopes): AuthorizationResult
    {
        return $this->jwtTokenValidator->requireScopes($result, $requiredScopes);
    }

    /**
     * @param array<string> $parts
     */
    private function isGraphToken(array $parts): bool
    {
        if ([] === $parts) {
            return false;
        }

        $header = $this->decodePartToArray($parts[0]);
        if (null === $header) {
            return false;
        }

        return isset($header['nonce']);
    }

    /**
     * @param array<string> $parts
     */
    private function validateGraphToken(array $parts): AuthorizationResult
    {
        // Intentionally claim-based only for example Graph token compatibility.
        if (\count($parts) < 2) {
            return AuthorizationResult::unauthorized('invalid_token', 'Invalid token format.');
        }

        $payload = $this->decodePartToArray($parts[1]);
        if (null === $payload) {
            return AuthorizationResult::unauthorized('invalid_token', 'Invalid token payload.');
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int) $payload['exp'] < time()) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token has expired.');
        }

        if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int) $payload['nbf'] > time() + $this->notBeforeLeewaySeconds) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not yet valid.');
        }

        $issuer = $payload['iss'] ?? '';
        if (!\is_string($issuer) || !$this->isTrustedGraphIssuer($issuer)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Invalid token issuer for Graph token.');
        }

        $scopes = $this->extractScopes($payload);

        $attributes = [
            'oauth.claims' => $payload,
            'oauth.scopes' => $scopes,
            'oauth.graph_token' => true,
        ];

        if (isset($payload['sub'])) {
            $attributes['oauth.subject'] = $payload['sub'];
        }

        if (isset($payload['oid'])) {
            $attributes['oauth.object_id'] = $payload['oid'];
        }

        if (isset($payload['name'])) {
            $attributes['oauth.name'] = $payload['name'];
        }

        return AuthorizationResult::allow($attributes);
    }

    private function isTrustedGraphIssuer(string $issuer): bool
    {
        foreach ($this->trustedGraphIssuers as $marker) {
            if (str_contains($issuer, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>
     */
    private function extractScopes(array $claims): array
    {
        if (!isset($claims[$this->scopeClaim])) {
            return [];
        }

        $scopeValue = $claims[$this->scopeClaim];

        if (\is_array($scopeValue)) {
            return array_values(array_filter($scopeValue, 'is_string'));
        }

        if (\is_string($scopeValue)) {
            return array_values(array_filter(explode(' ', $scopeValue)));
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePartToArray(string $part): ?array
    {
        $decoded = base64_decode(strtr($part, '-_', '+/'));
        if (false === $decoded) {
            return null;
        }

        $data = json_decode($decoded, true);

        return \is_array($data) ? $data : null;
    }
}
