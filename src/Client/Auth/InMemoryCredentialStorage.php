<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Client\Auth;

/**
 * Keeps credentials for the lifetime of the process.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InMemoryCredentialStorage implements CredentialStorageInterface
{
    /** @var array<string, ClientRegistration> */
    private array $registrations = [];

    /** @var array<string, array<string, AccessToken>> */
    private array $tokens = [];

    public function getClientRegistration(string $issuer): ?ClientRegistration
    {
        return $this->registrations[$issuer] ?? null;
    }

    public function saveClientRegistration(string $issuer, ClientRegistration $registration): void
    {
        $this->registrations[$issuer] = $registration;
    }

    public function getToken(string $issuer, string $resource): ?AccessToken
    {
        return $this->tokens[$issuer][$resource] ?? null;
    }

    public function saveToken(string $issuer, string $resource, AccessToken $token): void
    {
        $this->tokens[$issuer][$resource] = $token;
    }

    public function forget(?string $issuer = null): void
    {
        if (null === $issuer) {
            $this->registrations = [];
            $this->tokens = [];

            return;
        }

        unset($this->registrations[$issuer], $this->tokens[$issuer]);
    }

    public function synchronize(callable $operation): mixed
    {
        return $operation();
    }
}
