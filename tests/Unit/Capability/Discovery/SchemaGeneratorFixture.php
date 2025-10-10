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

use Mcp\Capability\Attribute\Schema;
use Mcp\Tests\Unit\Fixtures\Enum\BackedIntEnum;
use Mcp\Tests\Unit\Fixtures\Enum\BackedStringEnum;
use Mcp\Tests\Unit\Fixtures\Enum\UnitEnum;

/**
 * Comprehensive fixture for testing SchemaGenerator with various scenarios.
 */
class SchemaGeneratorFixture
{
    // ===== BASIC SCENARIOS =====

    public function noParams(): void
    {
    }

    /**
     * Type hints only - no Schema attributes.
     */
    public function typeHintsOnly(string $name, int $age, bool $active, array $tags, ?\stdClass $config = null): void
    {
    }

    /**
     * DocBlock types only - no PHP type hints, no Schema attributes.
     *
     * @param string $username The username
     * @param int    $count    Number of items
     * @param bool   $enabled  Whether enabled
     * @param array  $data     Some data
     */
    public function docBlockOnly($username, $count, $enabled, $data): void
    {
    }

    /**
     * Type hints with DocBlock descriptions.
     *
     * @param string $email    User email address
     * @param int    $score    User score
     * @param bool   $verified Whether user is verified
     */
    public function typeHintsWithDocBlock(string $email, int $score, bool $verified): void
    {
    }

    // ===== METHOD-LEVEL SCHEMA SCENARIOS =====

    /**
     * Method-level Schema with complete definition.
     */
    #[Schema(definition: [
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
    ])]
    public function methodLevelCompleteDefinition(string $field, string $operator, mixed $value): array
    {
        return compact('field', 'operator', 'value');
    }

    /**
     * Method-level Schema defining properties.
     */
    #[Schema(
        description: 'Creates a new user with detailed information.',
        properties: [
            'username' => ['type' => 'string', 'minLength' => 3, 'pattern' => '^[a-zA-Z0-9_]+$'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'age' => ['type' => 'integer', 'minimum' => 18, 'description' => 'Age in years.'],
            'isActive' => ['type' => 'boolean', 'default' => true],
        ],
        required: ['username', 'email']
    )]
    public function methodLevelWithProperties(string $username, string $email, int $age, bool $isActive = true): array
    {
        return compact('username', 'email', 'age', 'isActive');
    }

    /**
     * Method-level Schema for complex array argument.
     */
    #[Schema(
        properties: [
            'profiles' => [
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
            ],
        ],
        required: ['profiles']
    )]
    public function methodLevelArrayArgument(array $profiles): array
    {
        return ['updated_count' => \count($profiles)];
    }

    // ===== PARAMETER-LEVEL SCHEMA SCENARIOS =====

    /**
     * Parameter-level Schema attributes only.
     */
    public function parameterLevelOnly(
        #[Schema(description: 'Recipient ID', pattern: '^user_')]
        string $recipientId,
        #[Schema(maxLength: 1024)]
        string $messageBody,
        #[Schema(type: 'integer', enum: [1, 2, 5])]
        int $priority = 1,
        #[Schema(
            type: 'object',
            properties: [
                'type' => ['type' => 'string', 'enum' => ['sms', 'email', 'push']],
                'deviceToken' => ['type' => 'string', 'description' => 'Required if type is push'],
            ],
            required: ['type']
        )]
        ?array $notificationConfig = null,
    ): array {
        return compact('recipientId', 'messageBody', 'priority', 'notificationConfig');
    }

    /**
     * Parameter-level Schema with string constraints.
     */
    public function parameterStringConstraints(
        #[Schema(format: 'email')]
        string $email,
        #[Schema(minLength: 8, pattern: '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$')]
        string $password,
        string $regularString,
    ): void {
    }

    /**
     * Parameter-level Schema with numeric constraints.
     */
    public function parameterNumericConstraints(
        #[Schema(minimum: 18, maximum: 120)]
        int $age,
        #[Schema(minimum: 0, maximum: 5, exclusiveMaximum: true)]
        float $rating,
        #[Schema(multipleOf: 10)]
        int $count,
    ): void {
    }

    /**
     * Parameter-level Schema with array constraints.
     */
    public function parameterArrayConstraints(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1, uniqueItems: true)]
        array $tags,
        #[Schema(type: 'array', items: ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], minItems: 1, maxItems: 5)]
        array $scores,
    ): void {
    }

    // ===== COMBINED SCENARIOS =====

    /**
     * Method-level + Parameter-level Schema combination.
     *
     * @param string $settingKey The key of the setting
     * @param mixed  $newValue   The new value for the setting
     */
    #[Schema(
        properties: [
            'settingKey' => ['type' => 'string', 'description' => 'The key of the setting.'],
            'newValue' => ['description' => 'The new value for the setting (any type).'],
        ],
        required: ['settingKey', 'newValue']
    )]
    public function methodAndParameterLevel(
        string $settingKey,
        #[Schema(description: 'The specific new boolean value.', type: 'boolean')]
        mixed $newValue,
    ): array {
        return compact('settingKey', 'newValue');
    }

    /**
     * Type hints + DocBlock + Parameter-level Schema.
     *
     * @param string $username The user's name
     * @param int    $priority Task priority level
     */
    public function typeHintDocBlockAndParameterSchema(
        #[Schema(minLength: 3, pattern: '^[a-zA-Z0-9_]+$')]
        string $username,
        #[Schema(minimum: 1, maximum: 10)]
        int $priority,
    ): void {
    }

    // ===== ENUM SCENARIOS =====

    /**
     * Various enum parameter types.
     *
     * @param BackedStringEnum $stringEnum Backed string enum
     * @param BackedIntEnum    $intEnum    Backed int enum
     * @param UnitEnum         $unitEnum   Unit enum
     */
    public function enumParameters(
        BackedStringEnum $stringEnum,
        BackedIntEnum $intEnum,
        UnitEnum $unitEnum,
        ?BackedStringEnum $nullableEnum = null,
        BackedIntEnum $enumWithDefault = BackedIntEnum::First,
    ): void {
    }

    // ===== ARRAY TYPE SCENARIOS =====

    /**
     * Various array type scenarios.
     *
     * @param array                                                   $genericArray      Generic array
     * @param string[]                                                $stringArray       Array of strings
     * @param int[]                                                   $intArray          Array of integers
     * @param array<string, mixed>                                    $mixedMap          Mixed array map
     * @param array{name: string, age: int}                           $objectLikeArray   Object-like array
     * @param array{user: array{id: int, name: string}, items: int[]} $nestedObjectArray Nested object array
     */
    public function arrayTypeScenarios(
        array $genericArray,
        array $stringArray,
        array $intArray,
        array $mixedMap,
        array $objectLikeArray,
        array $nestedObjectArray,
    ): void {
    }

    // ===== NULLABLE AND OPTIONAL SCENARIOS =====

    /**
     * Nullable and optional parameter scenarios.
     *
     * @param string|null $nullableString Nullable string
     * @param int|null    $nullableInt    Nullable integer
     */
    public function nullableAndOptional(
        ?string $nullableString,
        ?int $nullableInt = null,
        string $optionalString = 'default',
        bool $optionalBool = true,
        array $optionalArray = [],
    ): void {
    }

    // ===== UNION TYPE SCENARIOS =====

    /**
     * Union type parameters.
     *
     * @param string|int       $stringOrInt String or integer
     * @param bool|string|null $multiUnion  Bool, string or null
     */
    public function unionTypes(
        string|int $stringOrInt,
        bool|string|null $multiUnion,
    ): void {
    }

    // ===== VARIADIC SCENARIOS =====

    /**
     * Variadic parameter scenarios.
     *
     * @param string ...$items Variadic strings
     */
    public function variadicStrings(string ...$items): void
    {
    }

    /**
     * Variadic with Schema constraints.
     *
     * @param int ...$numbers Variadic integers
     */
    public function variadicWithConstraints(
        #[Schema(items: ['type' => 'integer', 'minimum' => 0])]
        int ...$numbers,
    ): void {
    }

    // ===== MIXED TYPE SCENARIOS =====

    /**
     * Mixed type scenarios.
     *
     * @param mixed $anyValue    Any value
     * @param mixed $optionalAny Optional any value
     */
    public function mixedTypes(
        mixed $anyValue,
        mixed $optionalAny = 'default',
    ): void {
    }

    // ===== COMPLEX NESTED SCENARIOS =====

    /**
     * Complex nested Schema constraints.
     */
    public function complexNestedSchema(
        #[Schema(
            type: 'object',
            properties: [
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
            required: ['customer', 'items']
        )]
        array $order,
    ): array {
        return ['order_id' => uniqid()];
    }

    // ===== TYPE PRECEDENCE SCENARIOS =====

    /**
     * Testing type precedence between PHP, DocBlock, and Schema.
     *
     * @param int           $numericString         DocBlock says integer despite string type hint
     * @param string        $stringWithConstraints String with Schema constraints
     * @param array<string> $arrayWithItems        Array with Schema item overrides
     */
    public function typePrecedenceTest(
        string $numericString,
        #[Schema(format: 'email', minLength: 5)]
        string $stringWithConstraints,
        #[Schema(items: ['type' => 'integer', 'minimum' => 1, 'maximum' => 100])]
        array $arrayWithItems,
    ): void {
    }

    // ===== ERROR EDGE CASES =====

    /**
     * Method with no parameters but Schema description.
     */
    #[Schema(description: 'Gets server status. Takes no arguments.', properties: [])]
    public function noParamsWithSchema(): array
    {
        return ['status' => 'OK'];
    }

    /**
     * Parameter with Schema but inferred type.
     */
    public function parameterSchemaInferredType(
        #[Schema(description: 'Some parameter', minLength: 3)]
        $inferredParam,
    ): void {
    }

    /**
     * Method with ClientLogger that should be excluded from schema.
     *
     * @param string                              $message The message to process
     * @param \Mcp\Capability\Logger\ClientLogger $logger  Auto-injected logger
     */
    public function withClientLogger(string $message, \Mcp\Capability\Logger\ClientLogger $logger): string
    {
        return $message;
    }
}
