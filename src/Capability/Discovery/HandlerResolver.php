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

namespace Mcp\Capability\Discovery;

use ReflectionException;
use Closure;
use Mcp\Exception\InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;

/**
 * Utility class to validate and resolve MCP element handlers.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class HandlerResolver
{
    /**
     * Validates and resolves a handler to a ReflectionMethod or ReflectionFunction instance.
     *
     * A handler can be:
     * - A Closure: function() { ... }
     * - An array: [ClassName::class, 'methodName'] (instance method)
     * - An array: [ClassName::class, 'staticMethod'] (static method, if callable)
     * - A string: InvokableClassName::class (which will resolve to its '__invoke' method)
     *
     * @param Closure|array{0: object|string, 1: string}|string $handler the handler to resolve
     *
     * @throws InvalidArgumentException If the handler format is invalid, the class/method doesn't exist,
     *                                  or the method is unsuitable (e.g., private, abstract).
     */
    public static function resolve(Closure|array|string $handler): ReflectionMethod|ReflectionFunction
    {
        if ($handler instanceof Closure) {
            return self::resolveClosure($handler);
        }

        if (\is_string($handler)) {
            return self::resolveInvokableClass($handler);
        }

        if (\is_array($handler)) {
            return self::resolveArrayHandler($handler);
        }

        throw new InvalidArgumentException(\sprintf('Invalid handler type "%s". Expected Closure, invokable class string, or [class|object, method] array.', get_debug_type($handler)));
    }

    /**
     * Resolves a Closure handler to ReflectionFunction or ReflectionMethod.
     */
    private static function resolveClosure(Closure $handler): ReflectionMethod|ReflectionFunction
    {
        $reflectionFunction = new ReflectionFunction($handler);

        // Check if this closure was created from a class method (first-class callable syntax)
        $scopeClass = $reflectionFunction->getClosureScopeClass();
        if (null !== $scopeClass) {
            $className = $scopeClass->getName();
            $methodName = $reflectionFunction->getName();

            // Only treat as method closure if the method actually exists
            // and the closure name doesn't contain "{closure" (which indicates an inline closure)
            if (self::isMethodClosure($className, $methodName)) {
                $reflectionMethod = self::tryCreateReflectionMethod($className, $methodName);
                if ($reflectionMethod instanceof ReflectionMethod) {
                    self::validateMethod($reflectionMethod, $className, $methodName);

                    return $reflectionMethod;
                }
            }
        }

        return $reflectionFunction;
    }

    /**
     * Resolves an invokable class string to ReflectionMethod.
     */
    private static function resolveInvokableClass(string $handler): ReflectionMethod
    {
        if (!class_exists($handler)) {
            throw new InvalidArgumentException('Invalid handler format. Expected Closure, [ClassName::class, \'methodName\'] or InvokableClassName::class string.');
        }

        return self::resolveArrayHandler([$handler, '__invoke']);
    }

    /**
     * Resolves an array handler [class|object, method] to ReflectionMethod.
     *
     * @param array{0: class-string|object, 1: string} $handler
     */
    private static function resolveArrayHandler(array $handler): ReflectionMethod
    {
        self::validateArrayHandler($handler);

        [$target, $methodName] = $handler;
        $className = \is_object($target) ? $target::class : $target;

        self::validateClass($className);
        self::validateMethodName($methodName);
        self::validateMethodExists($className, $methodName);

        try {
            $reflectionMethod = new ReflectionMethod($className, $methodName);
            self::validateMethod($reflectionMethod, $className, $methodName);

            return $reflectionMethod;
        } catch (ReflectionException $e) {
            throw new InvalidArgumentException(\sprintf('Reflection error while resolving handler "%s::%s": %s', $className, $methodName, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Checks if a closure represents a method closure (first-class callable).
     */
    private static function isMethodClosure(string $className, string $methodName): bool
    {
        return method_exists($className, $methodName) && !str_contains($methodName, '{closure');
    }

    /**
     * Attempts to create a ReflectionMethod, returning null on failure.
     */
    private static function tryCreateReflectionMethod(string $className, string $methodName): ?ReflectionMethod
    {
        try {
            return new ReflectionMethod($className, $methodName);
        } catch (ReflectionException) {
            return null;
        }
    }

    /**
     * Validates that an array handler has the correct format.
     *
     * @param array{0: class-string|object, 1: string} $handler
     */
    private static function validateArrayHandler(array $handler): void
    {
        if (2 !== \count($handler)) {
            throw new InvalidArgumentException('Invalid array handler format. Expected [ClassName::class, \'methodName\'].');
        }

        if (!\is_string($handler[1])) {
            throw new InvalidArgumentException('Invalid array handler format. Expected [ClassName::class, \'methodName\'].');
        }
    }

    /**
     * Validates that a class exists.
     */
    private static function validateClass(string $className): void
    {
        if (!class_exists($className)) {
            throw new InvalidArgumentException(\sprintf('Handler class "%s" not found for array handler.', $className));
        }
    }

    /**
     * Validates that a method name is not empty.
     */
    private static function validateMethodName(string $methodName): void
    {
        if ('' === $methodName) {
            throw new InvalidArgumentException('Handler method name must be a non-empty string.');
        }
    }

    /**
     * Validates that a method exists on a class.
     */
    private static function validateMethodExists(string $className, string $methodName): void
    {
        if (!method_exists($className, $methodName)) {
            // Special case for __invoke to provide a better error message
            if ('__invoke' === $methodName) {
                throw new InvalidArgumentException(\sprintf('Invokable handler class "%s" must have a public "__invoke" method.', $className));
            }

            throw new InvalidArgumentException(\sprintf('Handler method "%s" not found in class "%s" for array handler.', $methodName, $className));
        }
    }

    /**
     * Validates that a method is suitable for use as a handler.
     */
    private static function validateMethod(ReflectionMethod $reflectionMethod, string $className, string $methodName): void
    {
        if ($reflectionMethod->isConstructor() || $reflectionMethod->isDestructor()) {
            throw new InvalidArgumentException(\sprintf('Handler method "%s::%s" cannot be a constructor or destructor.', $className, $methodName));
        }

        if (!$reflectionMethod->isPublic()) {
            throw new InvalidArgumentException(\sprintf('Handler method "%s::%s" must be public.', $className, $methodName));
        }

        if ($reflectionMethod->isAbstract()) {
            throw new InvalidArgumentException(\sprintf('Handler method "%s::%s" must not be abstract.', $className, $methodName));
        }
    }
}
