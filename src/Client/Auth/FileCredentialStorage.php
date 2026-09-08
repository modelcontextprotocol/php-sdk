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
    /** @var array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>}|null */
    private ?array $data = null;

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
        $data = $this->read();
        $data['registrations'][$issuer] = $registration->jsonSerialize();

        $this->write($data);
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
        $data = $this->read();
        $data['tokens'][$issuer][$resource] = $token->jsonSerialize();

        $this->write($data);
    }

    public function forget(?string $issuer = null): void
    {
        if (null === $issuer) {
            $this->write(['registrations' => [], 'tokens' => []]);

            return;
        }

        $data = $this->read();
        unset($data['registrations'][$issuer], $data['tokens'][$issuer]);

        $this->write($data);
    }

    /**
     * @return array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>}
     */
    private function read(): array
    {
        if (null !== $this->data) {
            return $this->data;
        }

        if (!is_file($this->path)) {
            return $this->data = ['registrations' => [], 'tokens' => []];
        }

        $contents = file_get_contents($this->path);
        $decoded = false === $contents ? null : json_decode($contents, true);

        if (!\is_array($decoded)) {
            // A truncated or hand-edited file is not worth failing a connection over:
            // the worst case is one more trip through the authorization flow.
            return $this->data = ['registrations' => [], 'tokens' => []];
        }

        return $this->data = [
            'registrations' => \is_array($decoded['registrations'] ?? null) ? $decoded['registrations'] : [],
            'tokens' => \is_array($decoded['tokens'] ?? null) ? $decoded['tokens'] : [],
        ];
    }

    /**
     * @param array{registrations: array<string, array<string, mixed>>, tokens: array<string, array<string, array<string, mixed>>>} $data
     */
    private function write(array $data): void
    {
        $this->data = $data;
        $directory = \dirname($this->path);

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(\sprintf('Could not create the credential directory "%s".', $directory));
        }

        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        if (false === $encoded || false === @file_put_contents($this->path, $encoded, \LOCK_EX)) {
            throw new RuntimeException(\sprintf('Could not write the credential file "%s".', $this->path));
        }

        @chmod($this->path, 0600);
    }
}
