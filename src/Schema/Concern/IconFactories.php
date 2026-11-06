<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Schema\Concern;

use Mcp\Exception\InvalidArgumentException;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
trait IconFactories
{
    private function validateIcon(): void
    {
        if ('' === $this->src) {
            throw new InvalidArgumentException('Icon "src" must be a non-empty string.');
        }
        if (!preg_match('#^(https?://|data:)#', $this->src)) {
            throw new InvalidArgumentException('Icon "src" must be a valid URL or data URI.');
        }

        foreach ($this->sizes ?? [] as $size) {
            if (!preg_match('/^(any|\d+x\d+)$/', $size)) {
                throw new InvalidArgumentException(\sprintf('Invalid size format "%s" in "sizes". Expected "WxH" or "any".', $size));
            }
        }
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
}
