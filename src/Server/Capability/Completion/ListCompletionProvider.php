<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Completion;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ListCompletionProvider implements ProviderInterface
{
    /**
     * @var string[]
     */
    private array $values;

    /**
     * @param array<int|float|string> $values
     */
    public function __construct(array $values)
    {
        $this->values = array_values(array_map(strval(...), $values));
    }

    public function getCompletions(string $currentValue): array
    {
        if ('' === $currentValue) {
            return $this->values;
        }

        $completions = [];
        foreach ($this->values as $value) {
            if (str_starts_with($value, $currentValue)) {
                $completions[] = $value;
            }
        }

        return $completions;
    }
}
