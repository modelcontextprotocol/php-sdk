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

use Mcp\Exception\InvalidArgumentException;

/**
 * Parses an absolute URL that must use https, accepting plain http for loopback hosts only.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class SecureUrl
{
    /**
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string}
     *
     * @throws InvalidArgumentException
     */
    public static function parse(mixed $url, string $label): array
    {
        $parts = \is_string($url) ? parse_url($url) : false;

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(\sprintf('The %s must be an absolute URL.', $label));
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(trim($parts['host'], '[]'));
        if ('https' !== $scheme && ('http' !== $scheme || !\in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new InvalidArgumentException(\sprintf('The %s "%s" must use https; plain http is only accepted for loopback hosts.', $label, $url));
        }

        return $parts;
    }
}
