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

use Mcp\Exception\InvalidArgumentException;
use Mcp\Exception\RegistryException;
use Mcp\Schema\Metadata;
use Mcp\Server\ClientAwareInterface;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use Psr\Container\ContainerInterface;

/**
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class ReferenceHandler implements ReferenceHandlerInterface
{
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function handle(ElementReference $reference, array $arguments): mixed
    {
        $session = $arguments['_session'];

        if (\is_string($reference->handler)) {
            if (class_exists($reference->handler) && method_exists($reference->handler, '__invoke')) {
                $reflection = new \ReflectionMethod($reference->handler, '__invoke');
                $instance = $this->getClassInstance($reference->handler);
                $arguments = $this->prepareArguments($reflection, $arguments);

                if ($instance instanceof ClientAwareInterface) {
                    $instance->setClient(new ClientGateway($session));
                }

                return \call_user_func($instance, ...$arguments);
            }

            if (\function_exists($reference->handler)) {
                $reflection = new \ReflectionFunction($reference->handler);
                $arguments = $this->prepareArguments($reflection, $arguments);

                return \call_user_func($reference->handler, ...$arguments);
            }
        }

        if (\is_callable($reference->handler)) {
            $reflection = $this->getReflectionForCallable($reference->handler, $session);
            $arguments = $this->prepareArguments($reflection, $arguments);

            return \call_user_func($reference->handler, ...$arguments);
        }

        if (\is_array($reference->handler)) {
            [$className, $methodName] = $reference->handler;
            $reflection = new \ReflectionMethod($className, $methodName);
            $instance = $this->getClassInstance($className);

            if ($instance instanceof ClientAwareInterface) {
                $instance->setClient(new ClientGateway($session));
            }

            $arguments = $this->prepareArguments($reflection, $arguments);

            return \call_user_func([$instance, $methodName], ...$arguments);
        }

        throw new InvalidArgumentException('Invalid handler type');
    }

    private function getClassInstance(string $className): object
    {
        if (null !== $this->container && $this->container->has($className)) {
            return $this->container->get($className);
        }

        return new $className();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<int, mixed>
     */
    private function prepareArguments(\ReflectionFunctionAbstract $reflection, array $arguments): array
    {
        $finalArgs = [];

        foreach ($reflection->getParameters() as $parameter) {
            // TODO: Handle variadic parameters.
            $paramName = $parameter->getName();
            $paramPosition = $parameter->getPosition();

            // Check if parameter is a special injectable type
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if (ClientGateway::class === $typeName && isset($arguments['_session'])) {
                    $finalArgs[$paramPosition] = new ClientGateway($arguments['_session']);
                    continue;
                }

                // Inject request metadata if requested
                if (Metadata::class === $typeName) {
                    if (isset($arguments['_meta']) && \is_array($arguments['_meta'])) {
                        $finalArgs[$paramPosition] = new Metadata($arguments['_meta']);
                    } elseif ($parameter->allowsNull()) {
                        $finalArgs[$paramPosition] = null;
                    } else {
                        $reflectionName = $reflection instanceof \ReflectionMethod
                            ? $reflection->class.'::'.$reflection->name
                            : 'Closure';
                        throw RegistryException::internalError("Missing required request metadata for parameter `{$paramName}` in {$reflectionName}. Provide `_meta` in request params or make the parameter nullable.");
                    }
                    continue;
                }
            }

            if (isset($arguments[$paramName])) {
                $argument = $arguments[$paramName];
                try {
                    $finalArgs[$paramPosition] = $this->castArgumentType($argument, $parameter);
                } catch (InvalidArgumentException $e) {
                    throw RegistryException::invalidParams($e->getMessage(), $e);
                } catch (\Throwable $e) {
                    throw RegistryException::internalError("Error processing parameter `{$paramName}`: {$e->getMessage()}", $e);
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                $finalArgs[$paramPosition] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $finalArgs[$paramPosition] = null;
            } elseif ($parameter->isOptional()) {
                continue;
            } else {
                $reflectionName = $reflection instanceof \ReflectionMethod
                    ? $reflection->class.'::'.$reflection->name
                    : 'Closure';
                throw RegistryException::internalError("Missing required argument `{$paramName}` for {$reflectionName}.");
            }
        }

        return array_values($finalArgs);
    }

    /**
     * Gets a ReflectionMethod or ReflectionFunction for a callable.
     */
    private function getReflectionForCallable(callable $handler, SessionInterface $session): \ReflectionMethod|\ReflectionFunction
    {
        if (\is_string($handler)) {
            return new \ReflectionFunction($handler);
        }

        if ($handler instanceof \Closure) {
            return new \ReflectionFunction($handler);
        }

        if (\is_array($handler) && 2 === \count($handler)) {
            [$class, $method] = $handler;

            if ($class instanceof ClientAwareInterface) {
                $class->setClient(new ClientGateway($session));
            }

            return new \ReflectionMethod($class, $method);
        }

        throw new InvalidArgumentException('Cannot create reflection for this callable type');
    }

    /**
     * Attempts type casting based on ReflectionParameter type hints.
     *
     * @throws InvalidArgumentException if casting is impossible for the required type
     */
    private function castArgumentType(mixed $argument, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (null === $argument) {
            if ($type && $type->allowsNull()) {
                return null;
            }
        }

        if (!$type instanceof \ReflectionNamedType) {
            return $argument;
        }

        $typeName = $type->getName();

        if (enum_exists($typeName)) {
            if (\is_object($argument) && $argument instanceof $typeName) {
                return $argument;
            }

            if (is_subclass_of($typeName, \BackedEnum::class)) {
                $value = $typeName::tryFrom($argument);
                if (null === $value) {
                    throw new InvalidArgumentException("Invalid value '{$argument}' for backed enum {$typeName}. Expected one of its backing values.");
                }

                return $value;
            } else {
                if (\is_string($argument)) {
                    foreach ($typeName::cases() as $case) {
                        if ($case->name === $argument) {
                            return $case;
                        }
                    }
                    $validNames = array_map(fn ($c) => $c->name, $typeName::cases());
                    throw new InvalidArgumentException("Invalid value '{$argument}' for unit enum {$typeName}. Expected one of: ".implode(', ', $validNames).'.');
                } else {
                    throw new InvalidArgumentException("Invalid value type '{$argument}' for unit enum {$typeName}. Expected a string matching a case name.");
                }
            }
        }

        try {
            return match (strtolower($typeName)) {
                'int', 'integer' => $this->castToInt($argument),
                'string' => (string) $argument,
                'bool', 'boolean' => $this->castToBoolean($argument),
                'float', 'double' => $this->castToFloat($argument),
                'array' => $this->castToArray($argument),
                default => $argument,
            };
        } catch (\TypeError $e) {
            throw new InvalidArgumentException("Value cannot be cast to required type `{$typeName}`.", 0, $e);
        }
    }

    /**
     * Helper to cast strictly to boolean.
     */
    private function castToBoolean(mixed $argument): bool
    {
        if (\is_bool($argument)) {
            return $argument;
        }
        if (1 === $argument || '1' === $argument || 'true' === strtolower((string) $argument)) {
            return true;
        }
        if (0 === $argument || '0' === $argument || 'false' === strtolower((string) $argument)) {
            return false;
        }

        throw new InvalidArgumentException('Cannot cast value to boolean. Use true/false/1/0.');
    }

    /**
     * Helper to cast strictly to integer.
     */
    private function castToInt(mixed $argument): int
    {
        if (\is_int($argument)) {
            return $argument;
        }
        if (is_numeric($argument) && floor((float) $argument) == $argument && !\is_string($argument)) {
            return (int) $argument;
        }
        if (\is_string($argument) && ctype_digit(ltrim($argument, '-'))) {
            return (int) $argument;
        }

        throw new InvalidArgumentException('Cannot cast value to integer. Expected integer representation.');
    }

    /**
     * Helper to cast strictly to float.
     */
    private function castToFloat(mixed $argument): float
    {
        if (\is_float($argument)) {
            return $argument;
        }
        if (\is_int($argument)) {
            return (float) $argument;
        }
        if (is_numeric($argument)) {
            return (float) $argument;
        }

        throw new InvalidArgumentException('Cannot cast value to float. Expected numeric representation.');
    }

    /**
     * Helper to cast strictly to array.
     *
     * @return array<int, mixed>
     */
    private function castToArray(mixed $argument): array
    {
        if (\is_array($argument)) {
            return $argument;
        }

        throw new InvalidArgumentException('Cannot cast value to array. Expected array.');
    }
}
