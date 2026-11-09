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
 * Reads the parameters of an authorization response from the query of the redirect URI.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class AuthorizationCallback
{
    /**
     * @return array<string, string>
     */
    public static function parameters(string $query): array
    {
        parse_str($query, $parameters);

        $callback = [];

        foreach ($parameters as $name => $value) {
            if (\is_string($name) && \is_scalar($value)) {
                $callback[$name] = (string) $value;
            }
        }

        return $callback;
    }
}
