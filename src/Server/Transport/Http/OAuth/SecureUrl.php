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

use Mcp\Schema\Exception\InvalidArgumentException as SchemaInvalidArgumentException;
use Mcp\Schema\SecureUrl as SchemaSecureUrl;
use Mcp\Server\Exception\InvalidArgumentException;

/**
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
        try {
            return SchemaSecureUrl::parse($url, $label);
        } catch (SchemaInvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }
}
