<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Attribute;

use Mcp\Capability\Completion\ProviderInterface;
use Mcp\Exception\InvalidArgumentException;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class CompletionProvider
{
    /**
     * @param class-string<ProviderInterface>|ProviderInterface|null $provider if a class-string, it will be resolved
     *                                                                         from the container at the point of use
     * @param ?array<int, int|float|string>                          $values   a list of values to use for completion
     */
    public function __construct(
        public ?string $providerClass = null,
        public string|ProviderInterface|null $provider = null,
        public ?array $values = null,
        public ?string $enum = null,
    ) {
        if (1 !== \count(array_filter([$provider, $values, $enum]))) {
            throw new InvalidArgumentException('Only one of provider, values, or enum can be set.');
        }
    }
}
