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
 * @internal
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
            $parameters = self::parseBearerParameters($header);

            if (null !== $parameters) {
                return new self($parameters);
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
        return preg_split('/\s+/', $this->parameters['scope'] ?? '', -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public function getError(): ?string
    {
        return $this->parameters['error'] ?? null;
    }

    /**
     * Read the Bearer challenge out of a field value that may list several challenges.
     *
     * @return array<string, string>|null null when the field value holds no Bearer challenge
     */
    private static function parseBearerParameters(string $header): ?array
    {
        if (!preg_match_all('/(?:[^",]|"(?:[^"\\\\]|\\\\.)*")+/s', $header, $members)) {
            return null;
        }

        $parameters = null;

        foreach ($members[0] as $member) {
            if (preg_match('/^\s*([^\s=,"]+)(?:\s++(?!=)|\s*$)(.*)$/s', $member, $scheme)) {
                if (null !== $parameters) {
                    break;
                }

                if (0 !== strcasecmp($scheme[1], 'Bearer')) {
                    continue;
                }

                $parameters = [];
                $member = $scheme[2];
            }

            if (null === $parameters) {
                continue;
            }

            if (preg_match('/^\s*([^\s=,"]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|((?!=)[^,\s]*))\s*$/s', $member, $parameter)) {
                // RFC 9110 quoted-pair: only the backslash goes, unlike stripslashes() which maps \0 to NUL.
                $parameters[strtolower($parameter[1])] = $parameter[3] ?? (preg_replace('/\\\\(.)/s', '$1', $parameter[2] ?? '') ?? '');
            }
        }

        return $parameters;
    }
}
