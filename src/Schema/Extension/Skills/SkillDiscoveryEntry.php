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
 * A single entry in the Agent Skills discovery index served at `skill://index.json`.
 *
 * @phpstan-type SkillDiscoveryEntryData array{name: string, type: string, description?: string, url: string}
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillDiscoveryEntry implements \JsonSerializable
{
    public function __construct(
        public readonly string $name,
        public readonly SkillType $type,
        public readonly string $url,
        public readonly ?string $description = null,
    ) {
    }

    /**
     * @param SkillDiscoveryEntryData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['name']) || !\is_string($data['name'])) {
            throw new InvalidArgumentException('Invalid or missing "name" in skill discovery entry.');
        }
        if (empty($data['type']) || !\is_string($data['type'])) {
            throw new InvalidArgumentException('Invalid or missing "type" in skill discovery entry.');
        }
        if (empty($data['url']) || !\is_string($data['url'])) {
            throw new InvalidArgumentException('Invalid or missing "url" in skill discovery entry.');
        }

        return new self(
            name: $data['name'],
            type: SkillType::from($data['type']),
            url: $data['url'],
            description: isset($data['description']) && \is_string($data['description']) ? $data['description'] : null,
        );
    }

    /**
     * @return SkillDiscoveryEntryData
     */
    public function jsonSerialize(): array
    {
        $data = [
            'name' => $this->name,
            'type' => $this->type->value,
        ];
        if (null !== $this->description) {
            $data['description'] = $this->description;
        }
        $data['url'] = $this->url;

        return $data;
    }
}
