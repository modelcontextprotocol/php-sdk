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
final class InMemoryCredentialStorage implements PendingAuthorizationStorageInterface
{
    /** @var array<string, ClientRegistration> */
    private array $registrations = [];

    /** @var array<string, array<string, AccessToken>> */
    private array $tokens = [];

    /** @var array<string, PendingAuthorization> */
    private array $pending = [];

    public function savePendingAuthorization(PendingAuthorization $authorization): void
    {
        foreach ($this->pending as $state => $pending) {
            if ($pending->expiresAt <= time()) {
                unset($this->pending[$state]);
            }
        }

        $this->pending[$authorization->state] = $authorization;
    }

    public function consumePendingAuthorization(string $state): ?PendingAuthorization
    {
        $pending = $this->pending[$state] ?? null;
        unset($this->pending[$state]);

        return $pending;
    }

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
            $this->pending = [];

            return;
        }

        unset($this->registrations[$issuer], $this->tokens[$issuer]);

        foreach ($this->pending as $state => $pending) {
            if ($pending->metadata->issuer === $issuer) {
                unset($this->pending[$state]);
            }
        }
    }

    public function synchronize(callable $operation): mixed
    {
        return $operation();
    }
}
