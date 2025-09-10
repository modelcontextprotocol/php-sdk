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

use UnitEnum;
use Mcp\Exception\InvalidArgumentException;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class EnumCompletionProvider implements ProviderInterface
{
    /**
     * @var string[]
     */
    private readonly array $values;

    /**
     * @param class-string $enumClass
     */
    public function __construct(string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" is not an enum.', $enumClass));
        }

        $this->values = array_map(
            fn (UnitEnum $case): string => property_exists($case, 'value') && null !== $case->value && \is_string($case->value) ? $case->value : $case->name,
            $enumClass::cases()
        );
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
