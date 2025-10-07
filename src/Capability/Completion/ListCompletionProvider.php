<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Completion;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListCompletionProvider implements ProviderInterface
{
    /**
     * @param string[] $values
     */
    public function __construct(
        private array $values,
    ) {
    }

    public function getCompletions(string $currentValue): array
    {
        if (empty($currentValue)) {
            return $this->values;
        }

        return array_values(array_filter(
            $this->values,
            fn (string $value) => str_starts_with($value, $currentValue)
        ));
    }
}
