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
 * Loopback hosts are `localhost`, names under the `.localhost` TLD (RFC 6761, section 6.3),
 * `127.0.0.0/8` and `::1`.
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
        // parse_url() reads a host differently than browsers do around a backslash, and turns control characters into underscores.
        $parts = \is_string($url) && 1 !== preg_match('/[\x00-\x20\x7f\\\\]/', $url) ? parse_url($url) : false;

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException(\sprintf('The %s must be an absolute URL.', $label));
        }

        // Browsers and IDNA decode these into another host, e.g. "%31%32%37.1" or "１２７.0.0.1" into a loopback address.
        if (1 === preg_match('/[%\x80-\xff]/', $parts['host'])) {
            throw new InvalidArgumentException(\sprintf('The %s "%s" must write its host in ASCII without percent-encoding, an internationalized name in punycode.', $label, $url));
        }

        $scheme = strtolower($parts['scheme']);
        if ('https' !== $scheme && ('http' !== $scheme || !self::isLoopbackHost($parts['host']))) {
            throw new InvalidArgumentException(\sprintf('The %s "%s" must use https; plain http is only accepted for loopback hosts.', $label, $url));
        }

        if (self::hidesItsAddress($parts['host'])) {
            throw new InvalidArgumentException(\sprintf('The %s "%s" must name its host, or an IP address in standard form that is neither unspecified nor IPv4 within IPv6.', $label, $url));
        }

        return $parts;
    }

    /**
     * Whether a URL points at the machine it is requested from, on any scheme.
     */
    public static function isLoopback(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);

        return \is_string($host) && self::isLoopbackHost($host);
    }

    // Each of these can reach this machine or a private network without looking like it.
    private static function hidesItsAddress(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return '0.0.0.0' === $host;
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            $bytes = (string) inet_pton($host);

            return '::1' !== $host && str_starts_with($bytes, str_repeat("\0", 10)) && \in_array(substr($bytes, 10, 2), ["\0\0", "\xff\xff"], true);
        }

        return 1 === preg_match('/^(?:0x[0-9a-f]*|[0-9]+)(?:\.(?:0x[0-9a-f]*|[0-9]+)){0,3}\.?$/', $host);
    }

    private static function isLoopbackHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ('::1' === $host) {
            return true;
        }

        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            return str_starts_with($host, '127.');
        }

        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        return 1 === preg_match('/^(?:[a-z0-9_-]+\.)*localhost$/', $host);
    }
}
