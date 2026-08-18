<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Wire;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;

/**
 * How long, and to whom, a server's answers may be cached (SEP-2549).
 *
 * The default says "do not cache": `ttlMs: 0` and `private`. That is the honest
 * answer for a server nobody has told us about — `public` lets a shared proxy
 * serve one caller's answer to another, which is a disclosure decision only the
 * operator can make.
 *
 * Granularity is per method, because that is what the caching hint describes: a
 * `tools/list` is usually identical for everyone and worth a long public TTL,
 * while a `resources/read` usually is not.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CachePolicy
{
    /**
     * @param array<string, array{int, CacheScope}> $perMethod
     */
    private function __construct(
        private readonly int $ttlMs,
        private readonly CacheScope $scope,
        private readonly array $perMethod = [],
    ) {
    }

    /**
     * The conservative default: nothing is fresh, nothing is shared.
     */
    public static function none(): self
    {
        return new self(0, CacheScope::Private);
    }

    /**
     * @param int $ttlMs how long an answer stays fresh, in milliseconds
     */
    public static function default(int $ttlMs, CacheScope $scope = CacheScope::Private): self
    {
        if ($ttlMs < 0) {
            throw new InvalidArgumentException(\sprintf('A cache TTL must be zero or more milliseconds, got %d.', $ttlMs));
        }

        return new self($ttlMs, $scope);
    }

    /**
     * Overrides the policy for one method.
     *
     * Only the methods the specification makes cacheable are worth naming; any
     * other is accepted and simply never consulted.
     */
    public function withMethod(string $method, int $ttlMs, CacheScope $scope = CacheScope::Private): self
    {
        if ($ttlMs < 0) {
            throw new InvalidArgumentException(\sprintf('A cache TTL must be zero or more milliseconds, got %d.', $ttlMs));
        }

        return new self($this->ttlMs, $this->scope, [...$this->perMethod, $method => [$ttlMs, $scope]]);
    }

    public function ttlFor(string $method): int
    {
        return $this->perMethod[$method][0] ?? $this->ttlMs;
    }

    public function scopeFor(string $method): CacheScope
    {
        return $this->perMethod[$method][1] ?? $this->scope;
    }
}
