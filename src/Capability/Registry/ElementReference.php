<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Registry;

/**
 * Base class for element references with default passthrough argument preparation.
 *
 * @phpstan-type Handler \Closure|array{0: object|string, 1: string}|string|object
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class ElementReference implements ArgumentPreparationInterface
{
    /**
     * @param Handler $handler The handler can be a Closure, array method reference,
     *                         string function/class name, or a callable object (implementing __invoke)
     */
    public function __construct(
        public readonly object|array|string $handler,
        public readonly bool $isManual = false,
    ) {
    }

    /**
     * Default passthrough implementation - returns arguments as-is.
     *
     * Subclasses can override via traits or direct implementation to provide
     * custom argument preparation (e.g., reflection-based mapping).
     *
     * @param array<string, mixed> $arguments       Raw arguments from MCP request
     * @param callable             $resolvedHandler The resolved handler callable (unused in passthrough)
     *
     * @return array<int, mixed> The arguments as a list, ready to be spread
     */
    public function prepareArguments(
        array $arguments,
        callable $resolvedHandler,
    ): array {
        unset($arguments['_session']);

        return array_values($arguments);
    }
}
