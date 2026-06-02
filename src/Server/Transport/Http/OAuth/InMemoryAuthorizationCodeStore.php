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

/**
 * In-memory {@see AuthorizationCodeStoreInterface}. Consuming a code deletes it,
 * enforcing single use. Expiry is enforced by the granter.
 */
final class InMemoryAuthorizationCodeStore implements AuthorizationCodeStoreInterface
{
    /** @var array<string, AuthorizationCode> */
    private array $codes = [];

    public function store(string $code, AuthorizationCode $authorizationCode): void
    {
        $this->codes[$code] = $authorizationCode;
    }

    public function consume(string $code): ?AuthorizationCode
    {
        $stored = $this->codes[$code] ?? null;
        unset($this->codes[$code]);

        return $stored;
    }
}
