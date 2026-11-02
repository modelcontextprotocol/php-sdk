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

use Mcp\Exception\InvalidArgumentException;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class EnumCompletionProvider implements ProviderInterface
{
    /**
     * @var string[]
     */
    private array $values;

    /**
     * @param class-string $enumClass
     */
    public function __construct(string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" is not an enum.', $enumClass));
        }

        $this->values = array_map(
            static fn ($case) => isset($case->value) && \is_string($case->value) ? $case->value : $case->name,
            $enumClass::cases()
        );
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
