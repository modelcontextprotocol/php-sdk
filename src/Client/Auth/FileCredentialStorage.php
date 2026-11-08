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
 * Persists credentials as JSON in a single file, so a CLI only has to authorize once.
 *
 * The file holds bearer tokens and, where the authorization server issues them, client
 * secrets, so it is created 0600 and the directory 0700. It is deliberately not
 * encrypted: an application with a keychain or a secrets manager should implement
 * {@see CredentialStorageInterface} against that instead.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class FileCredentialStorage implements CredentialStorageInterface
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

    public function forget(?string $issuer = null): void
    {
        $this->update(static function (array $data) use ($issuer): array {
            if (null === $issuer) {
                return ['registrations' => [], 'tokens' => []];
            }

            unset($data['registrations'][$issuer], $data['tokens'][$issuer]);

            return $data;
        });
    }

    /**
     * @return array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>}
     */
    private function read(): array
    {
        if (!is_file($this->path)) {
            return ['registrations' => [], 'tokens' => []];
        }

        $contents = file_get_contents($this->path);
        $decoded = false === $contents ? null : json_decode($contents, true);

        if (!\is_array($decoded)) {
            // A truncated or hand-edited file is not worth failing a connection over:
            // the worst case is one more trip through the authorization flow.
            return ['registrations' => [], 'tokens' => []];
        }

        return [
            'registrations' => \is_array($decoded['registrations'] ?? null) ? $decoded['registrations'] : [],
            'tokens' => \is_array($decoded['tokens'] ?? null) ? $decoded['tokens'] : [],
        ];
    }

    /**
     * Applies a change under an exclusive lock, so another process can't overwrite it.
     *
     * @param callable(array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>}): array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>} $change
     */
    private function update(callable $change): void
    {
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create the credential directory "%s".', $directory));
        }

        $lockPath = $directory.'/.'.basename($this->path).'.lock';
        $lock = @fopen($lockPath, 'c');

        if (false === $lock) {
            throw new RuntimeException(\sprintf('Could not open the credential lock file "%s".', $lockPath));
        }

        try {
            @chmod($lockPath, 0600);

            if (!flock($lock, \LOCK_EX)) {
                throw new RuntimeException(\sprintf('Could not lock the credential lock file "%s".', $lockPath));
            }

            $this->write($change($this->read()));
        } finally {
            fclose($lock);
        }
    }

    /**
     * @param array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>} $data
     */
    private function write(array $data): void
    {
        $directory = \dirname($this->path);

        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (false === $encoded) {
            throw new RuntimeException(\sprintf('Could not encode the credentials for "%s".', $this->path));
        }

        // Written to a private temporary file and moved into place, rather than written
        // in place and then chmod'ed: the file exists for a moment either way, and this
        // way it never exists world-readable, and never exists half-written.
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
