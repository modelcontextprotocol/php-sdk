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

use Psr\Clock\ClockInterface;

/**
 * Default {@see AuthorizationCodeIssuerInterface}: generates a random,
 * short-lived, single-use code and persists it bound to the request context.
 */
final class NativeAuthorizationCodeIssuer implements AuthorizationCodeIssuerInterface
{
    public function __construct(
        private readonly AuthorizationCodeStoreInterface $codes,
        private readonly int $codeTtl = 60,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function issueCode(
        Client $client,
        ResourceOwner $resourceOwner,
        string $redirectUri,
        array $scopes,
        string $codeChallenge,
        string $codeChallengeMethod,
        ?string $resource = null,
    ): string {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->codes->store($code, new AuthorizationCode(
            clientId: $client->clientId,
            redirectUri: $redirectUri,
            scopes: array_values($scopes),
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            userId: $resourceOwner->id,
            userClaims: $resourceOwner->claims,
            resource: $resource,
            expiresAt: $now->modify(\sprintf('+%d seconds', $this->codeTtl)),
        ));

        return $code;
    }
}
