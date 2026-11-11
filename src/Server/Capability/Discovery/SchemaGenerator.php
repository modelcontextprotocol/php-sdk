<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Capability\Discovery;

use Mcp\Server\Capability\Attribute\McpTool;
use Mcp\Server\Capability\Attribute\Schema;
use Mcp\Server\Capability\Registry\InjectableParameters;
use Mcp\Server\Exception\BadMethodCallException;
use Mcp\Server\Exception\InvalidArgumentException;
use Symfony\Component\JsonSchema\Configuration;
use Symfony\Component\JsonSchema\Schema as JsonSchema;
use Symfony\Component\JsonSchema\SchemaGenerator as JsonSchemaGenerator;
use Symfony\Component\JsonSchema\SchemaGeneratorInterface as JsonSchemaGeneratorInterface;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * Generates tool schemas with symfony/json-schema.
 *
 * The component describes the parameters, this class adds what is MCP specific: injectable
 * parameters, reserved names and the {@see Schema} attribute.
 */
final class SchemaGenerator implements SchemaGeneratorInterface
{
    private readonly JsonSchemaGeneratorInterface $generator;
    private readonly TypeResolverInterface $typeResolver;

    public function __construct(
        ?JsonSchemaGeneratorInterface $generator = null,
        ?TypeResolverInterface $typeResolver = null,
        private readonly Configuration $configuration = new Configuration(),
        private readonly bool $outputSchemaFromReturnType = false,
    ) {
        $this->generator = $generator ?? JsonSchemaGenerator::create();
        $this->typeResolver = $typeResolver ?? TypeResolver::create();
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(\Reflector $reflection): array
    {
        $reflection = self::assertFunction($reflection);

        $methodSchema = self::schemaAttribute($reflection);
        if (isset($methodSchema['definition'])) {
            return $methodSchema['definition'];
        }

        $parameters = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && InjectableParameters::supports($type->getName())) {
                continue;
            }

            if (\in_array(strtolower($parameter->name), ['_session', '_request'], true)) {
                throw new InvalidArgumentException(\sprintf('Handler "%s" has parameter named "%s" which is not allowed. Please change the name of that parameter.', self::handlerName($reflection), $parameter->name));
            }

            $parameters[$parameter->name] = $parameter;
        }

        $schema = self::toArray($this->generator->generateForParameters(array_values($parameters), $this->configuration)->flatten());
        unset($schema['$schema']);

        $methodProperties = $methodSchema['properties'] ?? [];
        $methodRequired = $methodSchema['required'] ?? [];
        unset($methodSchema['properties'], $methodSchema['required']);
        $schema = [...$schema, ...$methodSchema ?? []];

        $schema['properties'] = [...$schema['properties'] ?? [], ...array_diff_key($methodProperties, $parameters)];
        if ($required = array_values(array_unique([...$schema['required'] ?? [], ...array_diff($methodRequired, array_keys($parameters))]))) {
            $schema['required'] = $required;
        }

        foreach ($parameters as $name => $parameter) {
            $property = (array) $schema['properties'][$name];
            if (isset($methodProperties[$name])) {
                $property = [...$property, ...$methodProperties[$name]];
            }

            $parameterSchema = self::schemaAttribute($parameter) ?? [];
            if (isset($parameterSchema['definition'])) {
                $property = $parameterSchema['definition'] + array_intersect_key($property, ['default' => true]);
            } elseif (isset($parameterSchema['type'])) {
                $property = [...array_intersect_key($property, ['description' => true, 'default' => true]), ...$parameterSchema];
            } else {
                $property = [...$property, ...$parameterSchema];
            }

            $schema['properties'][$name] = $property ?: new \stdClass();
        }

        if ([] === $schema['properties']) {
            $schema['properties'] = new \stdClass();
        }

        return $schema;
    }

    public function generateOutputSchema(\Reflector $reflection): ?array
    {
        $reflection = self::assertFunction($reflection);

        foreach ($reflection->getAttributes(McpTool::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            if (null !== $outputSchema = $attribute->newInstance()->outputSchema) {
                return $outputSchema;
            }
        }

        if (!$this->outputSchemaFromReturnType) {
            return null;
        }

        // Only classes: `array` says nothing about the shape and scalars are not objects before 2026-07-28.
        $type = $this->typeResolver->resolve($reflection);
        if (!$type instanceof ObjectType) {
            return null;
        }

        $schema = self::toArray($this->generator->generate($type, $this->configuration)->flatten());
        unset($schema['$schema']);

        return $schema;
    }

    /**
     * Empty schemas come back as ArrayObject, the SDK keeps them as stdClass.
     *
     * @return array<string, mixed>
     */
    private static function toArray(JsonSchema $schema): array
    {
        $convert = static function (mixed $value) use (&$convert): mixed {
            return match (true) {
                $value instanceof \ArrayObject => new \stdClass(),
                \is_array($value) => array_map($convert, $value),
                default => $value,
            };
        };

        return $convert($schema->jsonSerialize());
    }

    private static function assertFunction(\Reflector $reflection): \ReflectionMethod|\ReflectionFunction
    {
        if (!$reflection instanceof \ReflectionMethod && !$reflection instanceof \ReflectionFunction) {
            throw new BadMethodCallException(\sprintf('Schema generation from %s is not supported. Use ReflectionMethod or ReflectionFunction instead.', $reflection::class));
        }

        return $reflection;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function schemaAttribute(\ReflectionFunctionAbstract|\ReflectionParameter $reflection): ?array
    {
        $attributes = $reflection->getAttributes(Schema::class, \ReflectionAttribute::IS_INSTANCEOF);

        return $attributes ? $attributes[0]->newInstance()->toArray() : null;
    }

    private static function handlerName(\ReflectionFunctionAbstract $reflection): string
    {
        return $reflection instanceof \ReflectionMethod ? $reflection->class.'::'.$reflection->name : $reflection->name;
    }
}
