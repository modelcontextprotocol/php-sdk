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

use Psr\Http\Message\ResponseInterface;

/**
 * The parsed `WWW-Authenticate` challenge a protected resource answers with.
 *
 * The interesting parameters are `resource_metadata`, which points straight at the
 * document that would otherwise have to be guessed at, and `scope`, which names what
 * the rejected request actually needed.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AuthorizationChallenge
{
    /**
     * @param array<string, string> $parameters the challenge's auth-param list, keys lowercased
     */
    private function __construct(public readonly array $parameters)
    {
    }

    public static function fromResponse(ResponseInterface $response): self
    {
        foreach ($response->getHeader('WWW-Authenticate') as $header) {
            if (preg_match('/^\s*Bearer\b\s*(.*)$/is', $header, $matches)) {
                return new self(self::parseParameters($matches[1]));
            }
        }

        return new self([]);
    }

    /**
     * The URL of the resource's metadata document, when the challenge named one.
     */
    public function getResourceMetadataUrl(): ?string
    {
        return $this->parameters['resource_metadata'] ?? null;
    }

    /**
     * The scopes the rejected request needed.
     *
     * @return string[]
     */
    public function getScopes(): array
    {
        $scope = $this->parameters['scope'] ?? '';

        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }

    public function getError(): ?string
    {
        return $this->parameters['error'] ?? null;
    }

    /**
     * Parse a comma-separated auth-param list, tolerating both quoted and bare values.
     *
     * @return array<string, string>
     */
    private static function parseParameters(string $parameters): array
    {
        if (!preg_match_all('/([A-Za-z0-9_-]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^,\s]*))/', $parameters, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $parsed = [];

        foreach ($matches as $match) {
            $value = '' !== $match[2] ? $match[2] : ($match[3] ?? '');
            $parsed[strtolower($match[1])] = stripcslashes($value);
        }

        return $parsed;
    }
}
