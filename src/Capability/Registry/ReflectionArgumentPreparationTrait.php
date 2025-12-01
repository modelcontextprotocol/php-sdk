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

/**
 * Uses reflection to match named parameters and perform type casting.
 *
 * @author Mateu Aguilo Bosch <mateu@mateuaguilo.com>
 */
trait ReflectionArgumentPreparationTrait
{
    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<int, mixed>
     */
    public function prepareArguments(array $arguments, callable $resolvedHandler): array
    {
        $reflection = $this->getReflectionFromCallable($resolvedHandler);
        $finalArgs = [];

        foreach ($reflection->getParameters() as $parameter) {
            $paramName = $parameter->getName();
            $paramPosition = $parameter->getPosition();

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

    private function getReflectionFromCallable(callable $handler): \ReflectionFunctionAbstract
    {
        if ($handler instanceof \Closure) {
            return new \ReflectionFunction($handler);
        }

        if (\is_string($handler)) {
            return new \ReflectionFunction($handler);
        }

        if (\is_array($handler) && 2 === \count($handler)) {
            [$classOrObject, $method] = $handler;

            return new \ReflectionMethod($classOrObject, $method);
        }

        if (\is_object($handler) && method_exists($handler, '__invoke')) {
            return new \ReflectionMethod($handler, '__invoke');
        }

        throw new InvalidArgumentException('Cannot create reflection for this callable type');
    }

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
            return $this->castToEnum($argument, $typeName);
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
     * @param class-string $typeName
     */
    private function castToEnum(mixed $argument, string $typeName): mixed
    {
        if (\is_object($argument) && $argument instanceof $typeName) {
            return $argument;
        }

        if (is_subclass_of($typeName, \BackedEnum::class)) {
            $value = $typeName::tryFrom($argument);
            if (null === $value) {
                throw new InvalidArgumentException("Invalid value '{$argument}' for backed enum {$typeName}.");
            }

            return $value;
        }

        if (\is_string($argument)) {
            foreach ($typeName::cases() as $case) {
                if ($case->name === $argument) {
                    return $case;
                }
            }
            $validNames = array_map(fn ($c) => $c->name, $typeName::cases());
            throw new InvalidArgumentException("Invalid value '{$argument}' for unit enum {$typeName}. Expected one of: ".implode(', ', $validNames).'.');
        }

        throw new InvalidArgumentException("Invalid value type for unit enum {$typeName}.");
    }

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

        throw new InvalidArgumentException('Cannot cast value to boolean.');
    }

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

        throw new InvalidArgumentException('Cannot cast value to integer.');
    }

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

        throw new InvalidArgumentException('Cannot cast value to float.');
    }

    /**
     * @return array<int, mixed>
     */
    private function castToArray(mixed $argument): array
    {
        if (\is_array($argument)) {
            return $argument;
        }

        throw new InvalidArgumentException('Cannot cast value to array.');
    }
}
