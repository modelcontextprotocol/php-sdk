<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Generated;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\Generated\Enum\IconTheme;

/**
 * An optionally-sized icon that can be displayed in a user interface.
 *
 * Generated from the MCP specification by tools/schema-generator, do not edit.
 */
final class Icon implements \JsonSerializable
{
    /**
     * @param list<string>|null $sizes
     */
    public function __construct(
        public readonly string $src,
        public readonly ?string $mimeType = null,
        public readonly ?array $sizes = null,
        public readonly ?IconTheme $theme = null,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['src'])) {
            throw new InvalidArgumentException('Missing "src" in Icon data.');
        }
        if (!\is_string($data['src'])) {
            throw new InvalidArgumentException('Invalid "src" in Icon data.');
        }
        $src = $data['src'];
        $mimeType = null;
        if (isset($data['mimeType'])) {
            if (!\is_string($data['mimeType'])) {
                throw new InvalidArgumentException('Invalid "mimeType" in Icon data.');
            }
            $mimeType = $data['mimeType'];
        }
        $sizes = null;
        if (isset($data['sizes'])) {
            if (!\is_array($data['sizes']) || !array_is_list($data['sizes'])) {
                throw new InvalidArgumentException('Invalid "sizes" in Icon data.');
            }
            $sizes = [];
            foreach ($data['sizes'] as $item) {
                if (!\is_string($item)) {
                    throw new InvalidArgumentException('Invalid "sizes" in Icon data.');
                }
                $value = $item;
                $sizes[] = $value;
            }
        }
        $theme = null;
        if (isset($data['theme'])) {
            $theme = \is_string($data['theme']) ? IconTheme::tryFrom($data['theme']) : null;
            if (null === $theme) {
                throw new InvalidArgumentException('Invalid "theme" in Icon data.');
            }
        }

        return new self(
            src: $src,
            mimeType: $mimeType,
            sizes: $sizes,
            theme: $theme,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $data = [
            'src' => $this->src,
        ];

        if (null !== $this->mimeType) {
            $data['mimeType'] = $this->mimeType;
        }

        if (null !== $this->sizes) {
            $data['sizes'] = $this->sizes;
        }

        if (null !== $this->theme) {
            $data['theme'] = $this->theme->value;
        }

        return $data;
    }
}
