<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\JsonRpc;

/**
 * @internal
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Json
{
    public static function objectsToArrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $member) {
            $value[$key] = self::objectsToArrays($member);
        }

        return $value;
    }
}
