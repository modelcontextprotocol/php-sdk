<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema;

use Mcp\Schema\Enum\IconTheme;
use Mcp\Schema\Exception\InvalidArgumentException;

/**
 * A url pointing to an icon URL or a base64-encoded data URI.
 *
 * @phpstan-type IconData array{
 *     src: string,
 *     mimeType?: string,
 *     sizes?: string[],
 *     theme?: string,
 * }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class Icon implements \JsonSerializable
{
    /**
     * @param string     $src      a standard URI pointing to an icon resource
     * @param ?string    $mimeType optional override if the server's MIME type is missing or generic
     * @param ?string[]  $sizes    optional array of strings that specify sizes at which the icon can be used.
     *                             Each string should be in WxH format (e.g., `"48x48"`, `"96x96"`) or `"any"` for
     *                             scalable formats like SVG.
     * @param ?IconTheme $theme    Optional background this icon is designed for. When omitted, the icon is
     *                             assumed to work against any background.
     */
    public function __construct(
        public readonly string $src,
        public readonly ?string $mimeType = null,
        public readonly ?array $sizes = null,
        public readonly ?IconTheme $theme = null,
    ) {
        if (empty($src)) {
            throw new InvalidArgumentException('Icon "src" must be a non-empty string.');
        }
        if (!preg_match('#^(https?://|data:)#', $src)) {
            throw new InvalidArgumentException('Icon "src" must be a valid URL or data URI.');
        }

        if (null !== $sizes) {
            foreach ($sizes as $size) {
                if (!\is_string($size)) {
                    throw new InvalidArgumentException('Each size in "sizes" must be a string.');
                }
                if (!preg_match('/^(any|\d+x\d+)$/', $size)) {
                    throw new InvalidArgumentException(\sprintf('Invalid size format "%s" in "sizes". Expected "WxH" or "any".', $size));
                }
            }
        }
    }

    /**
     * @param IconData $data
     */
    public static function fromArray(array $data): self
    {
        if (empty($data['src']) || !\is_string($data['src'])) {
            throw new InvalidArgumentException('Invalid or missing "src" in Icon data.');
        }
        if (isset($data['mimeType']) && !\is_string($data['mimeType'])) {
            throw new InvalidArgumentException('Invalid "mimeType" in Icon data.');
        }
        if (isset($data['sizes']) && !\is_array($data['sizes'])) {
            throw new InvalidArgumentException('Invalid "sizes" in Icon data.');
        }

        $theme = null;
        if (isset($data['theme'])) {
            if (!\is_string($data['theme']) || null === $theme = IconTheme::tryFrom($data['theme'])) {
                throw new InvalidArgumentException('Invalid "theme" in Icon data.');
            }
        }

        return new self($data['src'], $data['mimeType'] ?? null, $data['sizes'] ?? null, $theme);
    }

    /**
     * Hydrates an "icons" list, rejecting entries that are not objects.
     *
     * @param array<mixed> $icons
     * @param string       $context the surrounding schema type, used for the error message
     *
     * @return self[]
     */
    public static function listFromArray(array $icons, string $context): array
    {
        return array_map(
            static function (mixed $icon) use ($context): self {
                if (!\is_array($icon)) {
                    throw new InvalidArgumentException(\sprintf('Each entry in "icons" of %s data must be an array.', $context));
                }

                return self::fromArray($icon);
            },
            $icons,
        );
    }

    /**
     * @return IconData
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
