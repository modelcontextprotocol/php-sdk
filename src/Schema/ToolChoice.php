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
use Mcp\Schema\Enum\ToolChoiceMode;

/**
 * Controls how the model uses tools during sampling.
 *
 * @deprecated since protocol revision 2026-07-28 (SEP-2577), earliest removal 2027-07-28.
 *  Integrate with an LLM provider's API directly instead.
 */
final class ToolChoice implements \JsonSerializable
{
    public function __construct(
        public readonly ToolChoiceMode $mode = ToolChoiceMode::Auto,
    ) {
    }

    /**
     * @param array{mode?: string} $data
     */
    public static function fromArray(array $data): self
    {
        if (\array_key_exists('mode', $data) && !\is_string($data['mode'])) {
            throw new InvalidArgumentException('Invalid "mode" in ToolChoice data.');
        }

        $mode = \array_key_exists('mode', $data) ? ToolChoiceMode::tryFrom($data['mode']) : ToolChoiceMode::Auto;
        if (null === $mode) {
            throw new InvalidArgumentException(\sprintf('Invalid tool choice mode "%s".', $data['mode']));
        }

        return new self($mode);
    }

    /**
     * @return array{mode: string}
     */
    public function jsonSerialize(): array
    {
        return ['mode' => $this->mode->value];
    }
}
