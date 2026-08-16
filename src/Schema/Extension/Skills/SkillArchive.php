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
 * A pre-packed archive form of a complete skill directory, listed under a discovery entry's
 * `archives`. Reading the archive resource via `resources/read` retrieves the whole skill —
 * `SKILL.md` and every supporting file — in a single round trip.
 *
 * @phpstan-type SkillArchiveData array{url: string, mimeType: string, digest: string}
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillArchive implements \JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly string $mimeType,
        public readonly string $digest,
    ) {
    }

    /**
     * @param SkillArchiveData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['url']) || !\is_string($data['url'])) {
            throw new InvalidArgumentException('Invalid or missing "url" in skill archive entry.');
        }
        if (empty($data['mimeType']) || !\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Invalid or missing "mimeType" in skill archive entry.');
        }
        if (empty($data['digest']) || !\is_string($data['digest'])) {
            throw new InvalidArgumentException('Invalid or missing "digest" in skill archive entry.');
        }

        return new self($data['url'], $data['mimeType'], $data['digest']);
    }

    /**
     * @return SkillArchiveData
     */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'mimeType' => $this->mimeType,
            'digest' => $this->digest,
        ];
    }
}
