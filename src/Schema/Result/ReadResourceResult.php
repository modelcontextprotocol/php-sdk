<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Result;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Content\BlobResourceContents;
use Mcp\Schema\Content\ResourceContents;
use Mcp\Schema\Content\TextResourceContents;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Schema\JsonRpc\ResultInterface;

/**
 * The server's response to a resources/read request from the client.
 *
 * @phpstan-import-type TextResourceContentsData from TextResourceContents
 * @phpstan-import-type BlobResourceContentsData from BlobResourceContents
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ReadResourceResult implements ResultInterface
{
    /**
     * Create a new ReadResourceResult.
     *
     * @param ResourceContents[] $contents   The contents of the resource
     * @param ?int               $ttlMs      How long a client may consider this fresh, in milliseconds. Null
     *                                       leaves it to the server's configured {@see \Mcp\Server\Wire\CachePolicy};
     *                                       set it when one resource's freshness differs from the rest.
     * @param ?CacheScope        $cacheScope Who may cache it. Null defers to the policy. `Private` for anything
     *                                       shaped by who asked.
     */
    public function __construct(
        public readonly array $contents,
        public readonly ?int $ttlMs = null,
        public readonly ?CacheScope $cacheScope = null,
    ) {
        if (null !== $this->ttlMs && $this->ttlMs < 0) {
            throw new InvalidArgumentException(\sprintf('A resource "ttlMs" must be zero or more, got %d.', $this->ttlMs));
        }
    }

    /**
     * @param array{
     *     contents: array<TextResourceContentsData|BlobResourceContentsData>,
     *     ttlMs?: int,
     *     cacheScope?: string,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['contents']) || !\is_array($data['contents'])) {
            throw new InvalidArgumentException('Missing or invalid "contents" array in ReadResourceResult data.');
        }

        $contents = [];
        foreach ($data['contents'] as $content) {
            if (isset($content['text'])) {
                $contents[] = TextResourceContents::fromArray($content);
            } elseif (isset($content['blob'])) {
                $contents[] = BlobResourceContents::fromArray($content);
            } else {
                throw new InvalidArgumentException('Invalid content type in ReadResourceResult data: '.json_encode($content));
            }
        }

        $scope = isset($data['cacheScope']) && \is_string($data['cacheScope']) ? CacheScope::tryFrom($data['cacheScope']) : null;

        return new self($contents, isset($data['ttlMs']) && \is_int($data['ttlMs']) ? $data['ttlMs'] : null, $scope);
    }

    /**
     * @return array{
     *     contents: array<BlobResourceContents|TextResourceContents>,
     *     ttlMs?: int,
     *     cacheScope?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = ['contents' => $this->contents];

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
