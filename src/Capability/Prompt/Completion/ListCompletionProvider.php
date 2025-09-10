<?php

/**
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * Copyright (c) 2025 PHP SDK for Model Context Protocol
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 *
 * @see https://github.com/modelcontextprotocol/php-sdk
 */

namespace Mcp\Capability\Prompt\Completion;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListCompletionProvider implements ProviderInterface
{
    /**
     * @param string[] $values
     */
    public function __construct(
        private readonly array $values,
    ) {
    }

    public function getCompletions(string $currentValue): array
    {
        if ('' === $currentValue || '0' === $currentValue) {
            return $this->values;
        }

        return array_values(array_filter(
            $this->values,
            fn (string $value): bool => str_starts_with($value, $currentValue)
        ));
    }
}
