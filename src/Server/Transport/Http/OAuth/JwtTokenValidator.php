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

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;

/**
 * Validates JWT access tokens using JWKS from an OAuth 2.0 / OpenID Connect provider.
 *
 * This validator:
 * - Fetches JWKS from the authorization server (auto-discovered or explicit)
 * - Validates signature, audience, issuer, and expiration
 * - Extracts scopes and claims as authorization attributes
 *
 * Requires: firebase/php-jwt
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 */
class JwtTokenValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param string|list<string>   $issuer       Expected token issuer(s) (e.g., "https://auth.example.com/realms/mcp")
     * @param string|list<string>   $audience     Expected audience(s) for the token
     * @param JwksProviderInterface $jwksProvider JWKS provider
     * @param string|null           $jwksUri      Explicit JWKS URI (auto-discovered from first issuer if null)
     * @param list<string>          $algorithms   Allowed JWT algorithms (default: RS256, RS384, RS512)
     * @param string                $scopeClaim   Claim name for scopes (default: "scope")
     */
    public function __construct(
        private readonly string|array $issuer,
        private readonly string|array $audience,
        private readonly JwksProviderInterface $jwksProvider,
        private readonly ?string $jwksUri = null,
        private readonly array $algorithms = ['RS256', 'RS384', 'RS512'],
        private readonly string $scopeClaim = 'scope',
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        try {
            $keys = $this->getJwks();
            $decoded = JWT::decode($accessToken, $keys);
            /** @var array<string, mixed> $claims */
            $claims = (array) $decoded;

            // Validate issuer
            if (!$this->validateIssuer($claims)) {
                return AuthorizationResult::unauthorized(
                    'invalid_token',
                    'Token issuer mismatch.'
                );
            }

            // Validate audience
            if (!$this->validateAudience($claims)) {
                return AuthorizationResult::unauthorized(
                    'invalid_token',
                    'Token audience mismatch.'
                );
            }

            // Extract scopes
            $scopes = $this->extractScopes($claims);

            // Build attributes to attach to request
            $attributes = [
                'oauth.claims' => $claims,
                'oauth.scopes' => $scopes,
            ];

            // Add common claims as individual attributes
            if (isset($claims['sub'])) {
                $attributes['oauth.subject'] = $claims['sub'];
            }

            if (isset($claims['client_id'])) {
                $attributes['oauth.client_id'] = $claims['client_id'];
            }

            // Add azp (authorized party) for OIDC tokens
            if (isset($claims['azp'])) {
                $attributes['oauth.authorized_party'] = $claims['azp'];
            }

            return AuthorizationResult::allow($attributes);
        } catch (ExpiredException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token has expired.');
        } catch (SignatureInvalidException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token signature verification failed.');
        } catch (BeforeValidException) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not yet valid.');
        } catch (\UnexpectedValueException|\DomainException $e) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token validation failed: '.$e->getMessage());
        } catch (\Throwable) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token validation error.');
        }
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
        if (!$result->isAllowed()) {
            return $result;
        }

        $tokenScopes = $result->getAttributes()['oauth.scopes'] ?? [];

        if (!\is_array($tokenScopes)) {
            $tokenScopes = [];
        }

        foreach ($requiredScopes as $required) {
            if (!\in_array($required, $tokenScopes, true)) {
                return AuthorizationResult::forbidden(
                    'insufficient_scope',
                    \sprintf('Required scope: %s', $required),
                    $requiredScopes
                );
            }
        }

        return $result;
    }

    /**
     * @return array<string, \Firebase\JWT\Key>
     */
    private function getJwks(): array
    {
        $issuer = \is_array($this->issuer) ? $this->issuer[0] : $this->issuer;
        $jwksData = $this->jwksProvider->getJwks($issuer, $this->jwksUri);

        /* @var array<string, \Firebase\JWT\Key> */
        return JWK::parseKeySet($jwksData, $this->algorithms[0]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateAudience(array $claims): bool
    {
        if (!isset($claims['aud'])) {
            return false;
        }

        $tokenAudiences = \is_array($claims['aud']) ? $claims['aud'] : [$claims['aud']];
        $expectedAudiences = \is_array($this->audience) ? $this->audience : [$this->audience];

        foreach ($expectedAudiences as $expected) {
            if (\in_array($expected, $tokenAudiences, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function validateIssuer(array $claims): bool
    {
        if (!isset($claims['iss'])) {
            return false;
        }

        $expectedIssuers = \is_array($this->issuer) ? $this->issuer : [$this->issuer];

        return \in_array($claims['iss'], $expectedIssuers, true);
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
}
