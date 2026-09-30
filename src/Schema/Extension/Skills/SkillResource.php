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

/**
 * A file belonging to a skill, with the digest and size of its content.
 *
 * @phpstan-type SkillResourceData array{uri: string, digest: string, size: int}
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillResource implements \JsonSerializable
{
    /**
     * @param string $uri    resource URI of the file
     * @param string $digest SHA-256 digest of the file's raw bytes, formatted as `sha256:{hex}`
     * @param int    $size   length in bytes of the file's raw content (the same bytes `digest` covers)
     */
    public function __construct(
        public readonly string $uri,
        public readonly string $digest,
        public readonly int $size,
    ) {
        if (1 !== preg_match('/^sha256:[0-9a-f]{64}$/', $digest)) {
            throw new InvalidArgumentException(\sprintf('A skill resource digest must be "sha256:" followed by 64 lowercase hex characters, got "%s".', $digest));
        }

        if ($size < 0) {
            throw new InvalidArgumentException(\sprintf('A skill resource "size" must be zero or more, got %d.', $size));
        }
    }

    /**
     * @param SkillResourceData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['uri']) || !\is_string($data['uri'])) {
            throw new InvalidArgumentException('Invalid or missing "uri" in skill resource.');
        }
        if (empty($data['digest']) || !\is_string($data['digest'])) {
            throw new InvalidArgumentException('Invalid or missing "digest" in skill resource.');
        }
        if (!isset($data['size']) || !\is_int($data['size'])) {
            throw new InvalidArgumentException('Invalid or missing "size" in skill resource.');
        }

        return new self($data['uri'], $data['digest'], $data['size']);
    }

    /**
     * @return SkillResourceData
     */
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri,
            'digest' => $this->digest,
            'size' => $this->size,
        ];
    }
}
