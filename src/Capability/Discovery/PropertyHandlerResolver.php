<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Capability\Discovery;

/**
 * Resolves the registered {@see PropertyHandlerInterface} that handles a given
 * class for a given concern (describe / denormalize / normalize).
 *
 * Handlers are materialized once and consulted in registration order; the first
 * one that both implements the requested concern interface and supports the
 * class (the class itself or a subtype of its {@see PropertyHandlerInterface::supportedClass()})
 * wins. Resolution is memoized per concrete class and concern, so repeated types
 * — and the common "no handler" case — are resolved at most once.
 */
final class PropertyHandlerResolver
{
    /**
     * @var list<PropertyHandlerInterface>
     */
    private readonly array $handlers;

    /**
     * Holds the matching handler or `false` when none matched, keyed by
     * `"$className\0$concern"`.
     *
     * @var array<string, PropertyHandlerInterface|false>
     */
    private array $cache = [];

    /**
     * @param iterable<PropertyHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        $this->handlers = \is_array($handlers)
            ? array_values($handlers)
            : iterator_to_array($handlers, false);
    }

    /**
     * @template T of PropertyHandlerInterface
     *
     * @param class-string    $className the concrete type to resolve a handler for
     * @param class-string<T> $concern   the concern interface the handler must implement
     *
     * @return T|null
     */
    public function resolve(string $className, string $concern): ?PropertyHandlerInterface
    {
        $key = $className."\0".$concern;
        $cached = $this->cache[$key] ??= $this->find($className, $concern) ?? false;

        return $cached ?: null;
    }

    /**
     * @param class-string $className
     * @param class-string $concern
     */
    private function find(string $className, string $concern): ?PropertyHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler instanceof $concern && is_a($className, $handler::supportedClass(), true)) {
                return $handler;
            }
        }

        return null;
    }
}
