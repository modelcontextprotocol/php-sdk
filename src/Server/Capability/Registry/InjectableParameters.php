<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Registry;

use Mcp\Server\ClientGateway;
use Mcp\Server\RequestContext;

/**
 * Single source of truth for handler parameter types the SDK injects itself.
 *
 * Both schema generation (which must exclude these parameters from the
 * published inputSchema) and argument preparation (which must inject them)
 * read this list, so the two cannot drift apart.
 *
 * @internal
 */
final class InjectableParameters
{
    private const TYPES = [
        RequestContext::class,
        ClientGateway::class,
    ];

    private function __construct()
    {
    }

    public static function supports(string $typeName): bool
    {
        foreach (self::TYPES as $type) {
            if (is_a($typeName, $type, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $arguments the raw argument bag including the internal "_session" and "_request" entries
     */
    public static function resolve(string $typeName, array $arguments): ?object
    {
        if (RequestContext::class === $typeName && isset($arguments['_session'], $arguments['_request'])) {
            return new RequestContext($arguments['_session'], $arguments['_request']);
        }

        if (ClientGateway::class === $typeName && isset($arguments['_session'])) {
            return new ClientGateway($arguments['_session']);
        }

        return null;
    }
}
