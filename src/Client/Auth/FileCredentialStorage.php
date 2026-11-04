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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RuntimeException;

/**
 * Persists credentials unencrypted in a JSON file with the mode 0600, in a directory with the mode 0700.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class FileCredentialStorage implements PendingAuthorizationStorageInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function getClientRegistration(string $issuer): ?ClientRegistration
    {
        $registration = $this->read()['registrations'][$issuer] ?? null;

        if (!\is_array($registration)) {
            return null;
        }

        try {
            return ClientRegistration::fromArray($registration);
        } catch (InvalidArgumentException) {
            // Half-written or hand-edited: registering again costs one request.
            return null;
        }
    }

    public function saveClientRegistration(string $issuer, ClientRegistration $registration): void
    {
        $this->update(static function (array $data) use ($issuer, $registration): array {
            $data['registrations'][$issuer] = $registration->jsonSerialize();

            return $data;
        });
    }

    public function getToken(string $issuer, string $resource): ?AccessToken
    {
        $token = $this->read()['tokens'][$issuer][$resource] ?? null;

        if (!\is_array($token)) {
            return null;
        }

        try {
            return AccessToken::fromArray($token);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function saveToken(string $issuer, string $resource, AccessToken $token): void
    {
        $this->update(static function (array $data) use ($issuer, $resource, $token): array {
            $data['tokens'][$issuer][$resource] = $token->jsonSerialize();

            return $data;
        });
    }

    public function savePendingAuthorization(PendingAuthorization $authorization): void
    {
        $this->update(static function (array $data) use ($authorization): array {
            foreach ($data['pending'] as $state => $pending) {
                if (($pending['expires_at'] ?? 0) <= time()) {
                    unset($data['pending'][$state]);
                }
            }

            $data['pending'][$authorization->state] = $authorization->jsonSerialize();

            return $data;
        });
    }

    public function consumePendingAuthorization(string $state): ?PendingAuthorization
    {
        $pending = null;
        $this->update(static function (array $data) use ($state, &$pending): array {
            $pending = $data['pending'][$state] ?? null;
            unset($data['pending'][$state]);

            return $data;
        });

        if (!\is_array($pending)) {
            return null;
        }

        try {
            return PendingAuthorization::fromArray($pending);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function forget(?string $issuer = null): void
    {
        $this->update(static function (array $data) use ($issuer): array {
            if (null === $issuer) {
                return ['registrations' => [], 'tokens' => [], 'pending' => []];
            }

            unset($data['registrations'][$issuer], $data['tokens'][$issuer]);

            foreach ($data['pending'] as $state => $pending) {
                if (($pending['metadata']['issuer'] ?? null) === $issuer) {
                    unset($data['pending'][$state]);
                }
            }

            return $data;
        });
    }

    public function synchronize(callable $operation): mixed
    {
        // Its own lock file, as the operation saves credentials under the other one.
        return $this->underLock('.refresh.lock', $operation);
    }

    /**
     * @return array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>}
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return ['registrations' => [], 'tokens' => [], 'pending' => []];
        }

        $contents = file_get_contents($this->path);
        $decoded = false === $contents ? null : json_decode($contents, true);

        if (!\is_array($decoded)) {
            // Not worth failing a connection over: the worst case is one more authorization.
            return ['registrations' => [], 'tokens' => [], 'pending' => []];
        }

        return [
            'registrations' => \is_array($decoded['registrations'] ?? null) ? $decoded['registrations'] : [],
            'tokens' => \is_array($decoded['tokens'] ?? null) ? array_filter($decoded['tokens'], 'is_array') : [],
            'pending' => \is_array($decoded['pending'] ?? null) ? array_filter($decoded['pending'], 'is_array') : [],
        ];
    }

    /**
     * Applies a change under an exclusive lock, so another process can't overwrite it.
     *
     * @param callable(array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>}): array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>} $change
     */
    private function update(callable $change): void
    {
        $this->underLock('.lock', function () use ($change): void {
            $this->write($change($this->read()));
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function underLock(string $suffix, callable $operation): mixed
    {
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create the credential directory "%s".', $directory));
        }

        $lockPath = $directory.'/.'.basename($this->path).$suffix;
        $lock = @fopen($lockPath, 'c');

        if (false === $lock) {
            throw new RuntimeException(\sprintf('Could not open the credential lock file "%s".', $lockPath));
        }

        try {
            @chmod($lockPath, 0600);

            if (!flock($lock, \LOCK_EX)) {
                throw new RuntimeException(\sprintf('Could not lock the credential lock file "%s".', $lockPath));
            }

            return $operation();
        } finally {
            fclose($lock);
        }
    }

    /**
     * @param array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>} $data
     */
    private function write(array $data): void
    {
        $directory = \dirname($this->path);

        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (false === $encoded) {
            throw new RuntimeException(\sprintf('Could not encode the credentials for "%s".', $this->path));
        }

        // A private file moved into place is never world-readable or half-written.
        $temporary = @tempnam($directory, '.credentials');

        if (false === $temporary) {
            throw new RuntimeException(\sprintf('Could not create a temporary file next to "%s".', $this->path));
        }

        if (false === @file_put_contents($temporary, $encoded) || !@chmod($temporary, 0600) || !@rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RuntimeException(\sprintf('Could not write the credential file "%s".', $this->path));
        }
    }
}
