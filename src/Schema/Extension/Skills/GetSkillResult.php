<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Extension\Skills;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's response to a skills/get request from the client.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GetSkillResult implements ResultInterface
{
    /**
     * @param ?int        $ttlMs      how long a client may consider this fresh, in milliseconds. Null
     *                                leaves it to the server's configured {@see \Mcp\Server\Wire\CachePolicy}.
     * @param ?CacheScope $cacheScope who may cache it. Null defers to the policy.
     */
    public function __construct(
        public readonly Skill $skill,
        public readonly ?int $ttlMs = null,
        public readonly ?CacheScope $cacheScope = null,
    ) {
        if (null !== $this->ttlMs && $this->ttlMs < 0) {
            throw new InvalidArgumentException(\sprintf('A skills/get "ttlMs" must be zero or more, got %d.', $this->ttlMs));
        }
    }

    /**
     * @return array{
     *     skill: Skill,
     *     ttlMs?: int,
     *     cacheScope?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['skill' => $this->skill];

        if (null !== $this->ttlMs) {
            $data['ttlMs'] = $this->ttlMs;
        }

        if (null !== $this->cacheScope) {
            $data['cacheScope'] = $this->cacheScope->value;
        }

        return $data;
    }
}
