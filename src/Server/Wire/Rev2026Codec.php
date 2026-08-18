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

use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\Enum\ResultType;
use Mcp\Schema\Implementation;
use Mcp\Server\Stateless\RequestMeta;

/**
 * The wire codec for 2026-07-28: stamps `resultType`, then the SEP-2549 caching
 * hints, then the `_meta` serverInfo identity.
 *
 * The order matters — the cache fill only runs on a result that came out of the
 * stamp as `complete`, so one still asking for input never gets a TTL.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Rev2026Codec implements WireCodecInterface
{
    /** Methods that can come back asking for input (MRTR). */
    public const EXTENDED_RESULT_TYPE_METHODS = [
        'tools/call',
        'prompts/get',
        'resources/read',
    ];

    /** Methods whose results a client may cache, and which therefore MUST carry hints. */
    public const CACHEABLE_METHODS = [
        'server/discover',
        'tools/list',
        'prompts/list',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];

    public function __construct(
        private readonly ?Implementation $serverInfo = null,
        private readonly CacheScope $defaultCacheScope = CacheScope::Private,
        private readonly int $defaultTtlMs = 0,
    ) {
    }

    public function encodeResult(string $method, array $result, bool $cacheable = true): array
    {
        $stamped = $this->stampResultType($method, $result);

        return $this->stampServerInfo(
            $cacheable ? $this->fillCacheHints($method, $stamped) : $stamped,
        );
    }

    /**
     * A result naming its own type keeps it, but only where the method's
     * vocabulary allows more than `complete`.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function stampResultType(string $method, array $result): array
    {
        $provided = $result['resultType'] ?? null;

        if (null === $provided) {
            return [...$result, 'resultType' => ResultType::Complete->value];
        }

        if (ResultType::Complete->value === $provided || \in_array($method, self::EXTENDED_RESULT_TYPE_METHODS, true)) {
            return $result;
        }

        return [...$result, 'resultType' => ResultType::Complete->value];
    }

    /**
     * Fills `ttlMs`/`cacheScope`, most-specific author first: an authored value,
     * then configured policy, then "private, do not cache".
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function fillCacheHints(string $method, array $result): array
    {
        if (ResultType::Complete->value !== ($result['resultType'] ?? null)) {
            return $result;
        }

        if (!\in_array($method, self::CACHEABLE_METHODS, true)) {
            return $result;
        }

        $ttl = $result['ttlMs'] ?? null;
        $scope = $result['cacheScope'] ?? null;

        // Invalid authored values fall through to the next author down.
        if (!\is_int($ttl) || $ttl < 0) {
            $ttl = $this->defaultTtlMs;
        }

        if (!\is_string($scope) || null === CacheScope::tryFrom($scope)) {
            $scope = $this->defaultCacheScope->value;
        }

        return [...$result, 'ttlMs' => $ttl, 'cacheScope' => $scope];
    }

    /**
     * Servers SHOULD identify themselves on every response; an identity the
     * result already carries wins.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function stampServerInfo(array $result): array
    {
        if (null === $this->serverInfo) {
            return $result;
        }

        $meta = \is_array($result['_meta'] ?? null) ? $result['_meta'] : [];

        if (isset($meta[RequestMeta::SERVER_INFO])) {
            return $result;
        }

        $meta[RequestMeta::SERVER_INFO] = $this->serverInfo;

        return [...$result, '_meta' => $meta];
    }
}
