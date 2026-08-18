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
 * The wire codec for 2026-07-28.
 *
 * Applies the three additions this revision makes to every outbound result, in
 * a fixed order:
 *
 *  1. the `resultType` discriminator;
 *  2. the SEP-2549 caching hints, on cacheable methods only;
 *  3. the `_meta` serverInfo identity (spec PR #3002).
 *
 * The order is load-bearing rather than incidental: the cache fill only runs
 * on a result that came out of step 1 as `complete`, so a result still asking
 * for input never acquires a TTL for content it has not produced.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Rev2026Codec implements WireCodecInterface
{
    /**
     * Methods whose result vocabulary goes beyond `complete`, because they can
     * come back asking for input (MRTR).
     *
     * `subscriptions/listen` is deliberately absent: it never sends an ordinary
     * result, so there is nothing for the stamp to reach.
     */
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

    public function encodeResult(string $method, array $result): array
    {
        return $this->stampServerInfo(
            $this->fillCacheHints($method, $this->stampResultType($method, $result)),
        );
    }

    /**
     * A result that already names its own type keeps it, but only where the
     * method's vocabulary allows more than `complete`. Elsewhere the value
     * would be meaningless to the client, and quietly rewriting it would bury
     * a server bug that is better surfaced by the handler.
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
     * Fills `ttlMs`/`cacheScope`, most-specific author first: a valid value the
     * result already carries wins over the server's configured policy, which
     * wins over the conservative default of "private, do not cache".
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

        // An invalid authored value is dropped rather than repaired: it cannot
        // go on the wire, and the next author down already has a usable answer.
        if (!\is_int($ttl) || $ttl < 0) {
            $ttl = $this->defaultTtlMs;
        }

        if (!\is_string($scope) || null === CacheScope::tryFrom($scope)) {
            $scope = $this->defaultCacheScope->value;
        }

        return [...$result, 'ttlMs' => $ttl, 'cacheScope' => $scope];
    }

    /**
     * Servers SHOULD identify themselves on every response. A result that
     * already carries an identity keeps it; without a configured one this is
     * the identity function.
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
