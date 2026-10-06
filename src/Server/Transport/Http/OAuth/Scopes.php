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
 * Validates and dedupes a list of OAuth scopes.
 *
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Scopes
{
    /**
     * @param array<mixed> $scopes
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!\is_string($scope) || !preg_match('/^[^\s"\\\\]+$/D', $scope)) {
                throw new InvalidArgumentException('Scopes must be non-empty strings without whitespace, quotes or backslashes.');
            }

            // Keyed by scope to dedupe, read back by value: keys would turn "42" into an int.
            $normalized[$scope] = $scope;
        }

        return array_values($normalized);
    }
}
