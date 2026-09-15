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
 * The server's response to a skills/list request from the client.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ListSkillsResult implements ResultInterface
{
    /**
     * @param Skill[]     $skills
     * @param string|null $nextCursor an opaque token for the next page, present when more results follow
     * @param ?int        $ttlMs      how long a client may consider this fresh, in milliseconds. Null
     *                                leaves it to the server's configured {@see \Mcp\Server\Wire\CachePolicy}.
     * @param ?CacheScope $cacheScope who may cache it. Null defers to the policy.
     */
    public function __construct(
        public readonly array $skills,
        public readonly ?string $nextCursor = null,
        public readonly ?int $ttlMs = null,
        public readonly ?CacheScope $cacheScope = null,
    ) {
        if (null !== $this->ttlMs && $this->ttlMs < 0) {
            throw new InvalidArgumentException(\sprintf('A skills/list "ttlMs" must be zero or more, got %d.', $this->ttlMs));
        }
    }

    /**
     * @return array{
     *     skills: array<Skill>,
     *     nextCursor?: string,
     *     ttlMs?: int,
     *     cacheScope?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['skills' => array_values($this->skills)];

        if (null !== $this->nextCursor) {
            $data['nextCursor'] = $this->nextCursor;
        }

        // Only what this result actually decided; the wire codec fills the rest
        // from policy, and an absent member is the signal for it to do so.
        if (null !== $this->ttlMs) {
            $data['ttlMs'] = $this->ttlMs;
        }

        if (null !== $this->cacheScope) {
            $data['cacheScope'] = $this->cacheScope->value;
        }

        return $data;
    }
}
