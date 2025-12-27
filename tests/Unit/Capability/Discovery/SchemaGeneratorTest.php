<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Unit\Capability\Discovery;

use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaGeneratorTest extends TestCase
{
    private SchemaGenerator $schemaGenerator;

    protected function setUp(): void
    {
        $this->schemaGenerator = new SchemaGenerator(new DocBlockParser());
    }

    public function testGeneratesEmptyPropertiesObjectForMethodWithNoParameters()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'noParams');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals([
            'type' => 'object',
            'properties' => new \stdClass(),
        ], $schema);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testInfersBasicTypesFromPhpTypeHints()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintsOnly');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'string'], $schema['properties']['name']);
        $this->assertEquals(['type' => 'integer'], $schema['properties']['age']);
        $this->assertEquals(['type' => 'boolean'], $schema['properties']['active']);
        $this->assertEquals(['type' => 'array'], $schema['properties']['tags']);
        $this->assertEquals(['type' => ['null', 'object'], 'default' => null], $schema['properties']['config']);
        $this->assertEqualsCanonicalizing(['name', 'age', 'active', 'tags'], $schema['required']);
    }

    public function testInfersTypesAndDescriptionsFromDocBlockTags()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'docBlockOnly');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'string', 'description' => 'The username'], $schema['properties']['username']);
        $this->assertEquals(['type' => 'integer', 'description' => 'Number of items'], $schema['properties']['count']);
        $this->assertEquals(['type' => 'boolean', 'description' => 'Whether enabled'], $schema['properties']['enabled']);
        $this->assertEquals(['type' => 'array', 'description' => 'Some data'], $schema['properties']['data']);
        $this->assertEqualsCanonicalizing(['username', 'count', 'enabled', 'data'], $schema['required']);
    }

    public function testUsesPhpTypeHintsForTypeAndDocBlockForDescriptions()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintsWithDocBlock');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'string', 'description' => 'User email address'], $schema['properties']['email']);
        $this->assertEquals(['type' => 'integer', 'description' => 'User score'], $schema['properties']['score']);
        $this->assertEquals(['type' => 'boolean', 'description' => 'Whether user is verified'], $schema['properties']['verified']);
        $this->assertEqualsCanonicalizing(['email', 'score', 'verified'], $schema['required']);
    }

    public function testUsesCompleteSchemaDefinitionFromMethodLevelSchemaAttribute()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelCompleteDefinition');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals([
            'type' => 'object',
            'description' => 'Creates a custom filter with complete definition',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => ['name', 'date', 'status']],
                'operator' => ['type' => 'string', 'enum' => ['eq', 'gt', 'lt', 'contains']],
                'value' => ['description' => 'Value to filter by, type depends on field and operator'],
            ],
            'required' => ['field', 'operator', 'value'],
            'if' => [
                'properties' => ['field' => ['const' => 'date']],
            ],
            'then' => [
                'properties' => ['value' => ['type' => 'string', 'format' => 'date']],
            ],
        ], $schema);
    }

    public function testGeneratesSchemaFromMethodLevelSchemaAttributeWithProperties()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelWithProperties');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals('Creates a new user with detailed information.', $schema['description']);
        $this->assertEquals(['type' => 'string', 'minLength' => 3, 'pattern' => '^[a-zA-Z0-9_]+$'], $schema['properties']['username']);
        $this->assertEquals(['type' => 'string', 'format' => 'email'], $schema['properties']['email']);
        $this->assertEquals(['type' => 'integer', 'minimum' => 18, 'description' => 'Age in years.'], $schema['properties']['age']);
        $this->assertEquals(['type' => 'boolean', 'default' => true], $schema['properties']['isActive']);
        $this->assertEqualsCanonicalizing(['age', 'username', 'email'], $schema['required']);
    }

    public function testGeneratesSchemaForSingleArrayArgumentFromMethodLevelSchemaAttribute()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'methodLevelArrayArgument');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals([
            'type' => 'array',
            'description' => 'An array of user profiles to update.',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'data' => ['type' => 'object', 'additionalProperties' => true],
                ],
                'required' => ['id', 'data'],
            ],
        ], $schema['properties']['profiles']);
        $this->assertEquals(['profiles'], $schema['required']);
    }

    public function testGeneratesSchemaFromIndividualParameterLevelSchemaAttributes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'parameterLevelOnly');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['description' => 'Recipient ID', 'pattern' => '^user_', 'type' => 'string'], $schema['properties']['recipientId']);
        $this->assertEquals(['maxLength' => 1024, 'type' => 'string'], $schema['properties']['messageBody']);
        $this->assertEquals(['type' => 'integer', 'enum' => [1, 2, 5], 'default' => 1], $schema['properties']['priority']);
        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['sms', 'email', 'push']],
                'deviceToken' => ['type' => 'string', 'description' => 'Required if type is push'],
            ],
            'required' => ['type'],
            'default' => null,
        ], $schema['properties']['notificationConfig']);
        $this->assertEqualsCanonicalizing(['recipientId', 'messageBody'], $schema['required']);
    }

    public function testAppliesStringConstraintsFromParameterLevelSchemaAttributes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'parameterStringConstraints');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['format' => 'email', 'type' => 'string'], $schema['properties']['email']);
        $this->assertEquals(['minLength' => 8, 'pattern' => '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$', 'type' => 'string'], $schema['properties']['password']);
        $this->assertEquals(['type' => 'string'], $schema['properties']['regularString']);
        $this->assertEqualsCanonicalizing(['email', 'password', 'regularString'], $schema['required']);
    }

    public function testAppliesNumericConstraintsFromParameterLevelSchemaAttributes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'parameterNumericConstraints');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['minimum' => 18, 'maximum' => 120, 'type' => 'integer'], $schema['properties']['age']);
        $this->assertEquals(['minimum' => 0, 'maximum' => 5, 'exclusiveMaximum' => true, 'type' => 'number'], $schema['properties']['rating']);
        $this->assertEquals(['multipleOf' => 10, 'type' => 'integer'], $schema['properties']['count']);
        $this->assertEqualsCanonicalizing(['age', 'rating', 'count'], $schema['required']);
    }

    public function testAppliesArrayConstraintsFromParameterLevelSchemaAttributes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'parameterArrayConstraints');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'uniqueItems' => true], $schema['properties']['tags']);
        $this->assertEquals(['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'minItems' => 1, 'maxItems' => 5], $schema['properties']['scores']);
        $this->assertEqualsCanonicalizing(['tags', 'scores'], $schema['required']);
    }

    public function testMergesMethodLevelAndParameterLevelSchemaAttributes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'methodAndParameterLevel');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'string', 'description' => 'The key of the setting.'], $schema['properties']['settingKey']);
        $this->assertEquals(['description' => 'The specific new boolean value.', 'type' => 'boolean'], $schema['properties']['newValue']);
        $this->assertEqualsCanonicalizing(['settingKey', 'newValue'], $schema['required']);
    }

    public function testCombinesPhpTypeHintsDocBlockDescriptionsAndParameterLevelSchemaConstraints()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'typeHintDocBlockAndParameterSchema');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['minLength' => 3, 'pattern' => '^[a-zA-Z0-9_]+$', 'type' => 'string', 'description' => "The user's name"], $schema['properties']['username']);
        $this->assertEquals(['minimum' => 1, 'maximum' => 10, 'type' => 'integer', 'description' => 'Task priority level'], $schema['properties']['priority']);
        $this->assertEqualsCanonicalizing(['username', 'priority'], $schema['required']);
    }

    public function testGeneratesCorrectSchemaForEnumParameters()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'enumParameters');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'string', 'description' => 'Backed string enum', 'enum' => ['A', 'B']], $schema['properties']['stringEnum']);
        $this->assertEquals(['type' => 'integer', 'description' => 'Backed int enum', 'enum' => [1, 2]], $schema['properties']['intEnum']);
        $this->assertEquals(['type' => 'string', 'description' => 'Unit enum', 'enum' => ['Yes', 'No']], $schema['properties']['unitEnum']);
        $this->assertEquals(['type' => ['null', 'string'], 'enum' => ['A', 'B'], 'default' => null], $schema['properties']['nullableEnum']);
        $this->assertEquals(['type' => 'integer', 'enum' => [1, 2], 'default' => 1], $schema['properties']['enumWithDefault']);
        $this->assertEqualsCanonicalizing(['stringEnum', 'intEnum', 'unitEnum'], $schema['required']);
    }

    public function testGeneratesCorrectSchemaForArrayTypeDeclarations()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'arrayTypeScenarios');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'array', 'description' => 'Generic array'], $schema['properties']['genericArray']);
        $this->assertEquals(['type' => 'array', 'description' => 'Array of strings', 'items' => ['type' => 'string']], $schema['properties']['stringArray']);
        $this->assertEquals(['type' => 'array', 'description' => 'Array of integers', 'items' => ['type' => 'integer']], $schema['properties']['intArray']);
        $this->assertEquals(['type' => 'array', 'description' => 'Mixed array map'], $schema['properties']['mixedMap']);
        $this->assertArrayHasKey('type', $schema['properties']['objectLikeArray']);
        $this->assertEquals('object', $schema['properties']['objectLikeArray']['type']);
        $this->assertArrayHasKey('properties', $schema['properties']['objectLikeArray']);
        $this->assertArrayHasKey('name', $schema['properties']['objectLikeArray']['properties']);
        $this->assertArrayHasKey('age', $schema['properties']['objectLikeArray']['properties']);
        $this->assertEqualsCanonicalizing(['genericArray', 'stringArray', 'intArray', 'mixedMap', 'objectLikeArray', 'nestedObjectArray'], $schema['required']);
    }

    public function testHandlesNullableTypeHintsAndOptionalParameters()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'nullableAndOptional');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => ['null', 'string'], 'description' => 'Nullable string'], $schema['properties']['nullableString']);
        $this->assertEquals(['type' => ['null', 'integer'], 'description' => 'Nullable integer', 'default' => null], $schema['properties']['nullableInt']);
        $this->assertEquals(['type' => 'string', 'default' => 'default'], $schema['properties']['optionalString']);
        $this->assertEquals(['type' => 'boolean', 'default' => true], $schema['properties']['optionalBool']);
        $this->assertEquals(['type' => 'array', 'default' => []], $schema['properties']['optionalArray']);
        $this->assertEqualsCanonicalizing(['nullableString'], $schema['required']);
    }

    public function testGeneratesSchemaForPhpUnionTypes()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'unionTypes');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => ['integer', 'string'], 'description' => 'String or integer'], $schema['properties']['stringOrInt']);
        $this->assertEquals(['type' => ['null', 'boolean', 'string'], 'description' => 'Bool, string or null'], $schema['properties']['multiUnion']);
        $this->assertEqualsCanonicalizing(['stringOrInt', 'multiUnion'], $schema['required']);
    }

    public function testRepresentsVariadicStringParametersAsArrayOfStrings()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'variadicStrings');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'array', 'description' => 'Variadic strings', 'items' => ['type' => 'string']], $schema['properties']['items']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testAppliesItemConstraintsToVariadicParameters()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'variadicWithConstraints');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['items' => ['type' => 'integer', 'minimum' => 0], 'type' => 'array', 'description' => 'Variadic integers'], $schema['properties']['numbers']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testHandlesMixedTypeHintsOmittingExplicitType()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'mixedTypes');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['description' => 'Any value'], $schema['properties']['anyValue']);
        $this->assertEquals(['description' => 'Optional any value', 'default' => 'default'], $schema['properties']['optionalAny']);
        $this->assertEqualsCanonicalizing(['anyValue'], $schema['required']);
    }

    public function testGeneratesSchemaForComplexNestedObjectAndArrayStructures()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'complexNestedSchema');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'customer' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'pattern' => '^CUS-[0-9]{6}$'],
                        'name' => ['type' => 'string', 'minLength' => 2],
                        'email' => ['type' => 'string', 'format' => 'email'],
                    ],
                    'required' => ['id', 'name'],
                ],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'string', 'pattern' => '^PRD-[0-9]{4}$'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                            'price' => ['type' => 'number', 'minimum' => 0],
                        ],
                        'required' => ['product_id', 'quantity', 'price'],
                    ],
                ],
                'metadata' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['customer', 'items'],
        ], $schema['properties']['order']);
        $this->assertEquals(['order'], $schema['required']);
    }

    public function testTypePrecedenceParameterSchemaOverridesDocBlockOverridesPhpTypeHint()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'typePrecedenceTest');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['type' => 'integer', 'description' => 'DocBlock says integer despite string type hint'], $schema['properties']['numericString']);
        $this->assertEquals(['format' => 'email', 'minLength' => 5, 'type' => 'string', 'description' => 'String with Schema constraints'], $schema['properties']['stringWithConstraints']);
        $this->assertEquals(['items' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'type' => 'array', 'description' => 'Array with Schema item overrides'], $schema['properties']['arrayWithItems']);
        $this->assertEqualsCanonicalizing(['numericString', 'stringWithConstraints', 'arrayWithItems'], $schema['required']);
    }

    public function testGeneratesEmptyPropertiesObjectForMethodWithNoParametersEvenWithMethodLevelSchema()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'noParamsWithSchema');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals('Gets server status. Takes no arguments.', $schema['description']);
        $this->assertInstanceOf(\stdClass::class, $schema['properties']);
        $this->assertArrayNotHasKey('required', $schema);
    }

    public function testInfersParameterTypeAsAnyIfOnlyConstraintsAreGiven()
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, 'parameterSchemaInferredType');
        $schema = $this->schemaGenerator->generate($method);
        $this->assertEquals(['description' => 'Some parameter', 'minLength' => 3], $schema['properties']['inferredParam']);
        $this->assertEquals(['inferredParam'], $schema['required']);
    }

    public static function methodsWithForbiddenParameter(): array
    {
        return [
            ['withParameterNamedSession'],
            ['withParameterNamedSessionWithWeirdCase'],
            ['withParameterNamedRequest'],
        ];
    }

    #[DataProvider('methodsWithForbiddenParameter')]
    public function testGenerateWithForbiddenParameterNames(string $methodName)
    {
        $method = new \ReflectionMethod(SchemaGeneratorFixture::class, $methodName);
        $this->expectException(InvalidArgumentException::class);
        $this->schemaGenerator->generate($method);
    }
}
