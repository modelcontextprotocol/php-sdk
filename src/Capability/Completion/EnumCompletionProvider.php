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
        if (empty($currentValue)) {
            return $this->values;
        }

        return array_values(array_filter(
            $this->values,
            static fn (string $value) => str_starts_with($value, $currentValue)
        ));
    }
}
