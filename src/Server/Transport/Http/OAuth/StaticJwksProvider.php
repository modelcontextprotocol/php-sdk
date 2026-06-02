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
 * Serves a fixed JWK Set from in-process signing keys, without any HTTP fetch.
 *
 * Use this to let {@see JwtTokenValidator} verify access tokens that this same
 * process issued (issuer = self), closing the loop between the authorization
 * server and the resource server without a network round-trip to the JWKS
 * endpoint.
 */
final class StaticJwksProvider implements JwksProviderInterface
{
    /** @var list<array<string, mixed>> */
    private array $keys;

    /**
     * @param SigningKeyInterface|iterable<SigningKeyInterface> $signingKeys
     */
    public function __construct(SigningKeyInterface|iterable $signingKeys)
    {
        $keys = $signingKeys instanceof SigningKeyInterface ? [$signingKeys] : $signingKeys;

        $this->keys = array_map(
            static fn (SigningKeyInterface $key): array => $key->getPublicJwk(),
            array_values([...$keys]),
        );
    }

    public function getJwks(string $issuer, ?string $jwksUri = null): array
    {
        return ['keys' => $this->keys];
    }
}
