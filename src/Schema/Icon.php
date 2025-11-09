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

use Mcp\Exception\InvalidArgumentException;

/**
 * A url pointing to an icon URL or a base64-encoded data URI.
 *
 * @phpstan-type IconData array{
 *     src: string,
 *     mimeType?: string,
 *     sizes?: string[],
 * }
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class Icon implements \JsonSerializable
{
    /**
     * @param string    $src      a standard URI pointing to an icon resource
     * @param ?string   $mimeType optional override if the server's MIME type is missing or generic
     * @param ?string[] $sizes    optional array of strings that specify sizes at which the icon can be used.
     *                            Each string should be in WxH format (e.g., `"48x48"`, `"96x96"`) or `"any"` for
     *                            scalable formats like SVG.
     */
    public function __construct(
        public readonly string $src,
        public readonly ?string $mimeType = null,
        public readonly ?array $sizes = null,
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

        return new self($data['src'], $data['mimeTypes'] ?? null, $data['sizes'] ?? null);
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

        return $data;
    }
}
