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
final class FileCredentialStorage implements PendingAuthorizationStorageInterface
{
    public function __construct(private readonly string $path)
    {
    }

    public function getClientRegistration(string $issuer): ?ClientRegistration
    {
        $registration = $this->read()['registrations'][$issuer] ?? null;

        if (null === $registration) {
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
        $this->update(static function (array &$data) use ($issuer, $registration): void {
            $data['registrations'][$issuer] = $registration->jsonSerialize();
        });
    }

    public function getToken(string $issuer, string $resource): ?AccessToken
    {
        $token = $this->read()['tokens'][$issuer][$resource] ?? null;

        if (null === $token) {
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
        $this->update(static function (array &$data) use ($issuer, $resource, $token): void {
            $data['tokens'][$issuer][$resource] = $token->jsonSerialize();
        });
    }

    public function savePendingAuthorization(PendingAuthorization $authorization): void
    {
        $this->update(static function (array &$data) use ($authorization): void {
            foreach ($data['pending'] as $state => $pending) {
                if (($pending['expires_at'] ?? 0) <= time()) {
                    unset($data['pending'][$state]);
                }
            }

            $data['pending'][$authorization->state] = $authorization->jsonSerialize();
        });
    }

    public function consumePendingAuthorization(string $state): ?PendingAuthorization
    {
        $pending = null;
        $this->update(static function (array &$data) use ($state, &$pending): void {
            $pending = $data['pending'][$state] ?? null;
            unset($data['pending'][$state]);
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
        $this->update(static function (array &$data) use ($issuer): void {
            if (null === $issuer) {
                $data = ['registrations' => [], 'tokens' => [], 'pending' => []];

                return;
            }

            unset($data['registrations'][$issuer], $data['tokens'][$issuer]);

            foreach ($data['pending'] as $state => $pending) {
                if (($pending['metadata']['issuer'] ?? null) === $issuer) {
                    unset($data['pending'][$state]);
                }
            }
        });
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
            // A truncated or hand-edited file is not worth failing a connection over:
            // the worst case is one more trip through the authorization flow.
            return ['registrations' => [], 'tokens' => [], 'pending' => []];
        }

        return [
            'registrations' => \is_array($decoded['registrations'] ?? null) ? $decoded['registrations'] : [],
            'tokens' => \is_array($decoded['tokens'] ?? null) ? $decoded['tokens'] : [],
            'pending' => \is_array($decoded['pending'] ?? null) ? $decoded['pending'] : [],
        ];
    }

    /**
     * @param \Closure(array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>, pending: array<string, array<string, mixed>>}&): void $mutate
     */
    private function update(\Closure $mutate): void
    {
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create the credential directory "%s".', $directory));
        }

        $lock = @fopen($this->path.'.lock', 'c');

        if (false === $lock) {
            throw new RuntimeException('Could not open the credential storage lock.');
        }

        try {
            if (!flock($lock, \LOCK_EX)) {
                throw new RuntimeException('Could not lock the credential storage.');
            }

            $data = $this->read();
            $mutate($data);
            $this->write($data);
        } finally {
            flock($lock, \LOCK_UN);
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
