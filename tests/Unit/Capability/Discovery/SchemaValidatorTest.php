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
use Mcp\Capability\Discovery\SchemaValidator;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
    }

    public function testRepeatedValidationDoesNotGrowSchemaLoaderCache(): void
    {
        $loader = new class extends SchemaLoader {
            public function cacheSize(): int
            {
                return \count($this->dataCache) + \count($this->uriCache);
            }
        };
        $validator = new SchemaValidator(new Validator($loader));

        $validator->validateAgainstJsonSchema($this->getValidData(), $this->getSimpleSchema());
        $validator->validateAgainstJsonSchema($this->getValidData(), (object) $this->getSimpleSchema());
        $size = $loader->cacheSize();

        for ($i = 0; $i < 10; ++$i) {
            $validator->validateAgainstJsonSchema($this->getValidData(), $this->getSimpleSchema());
            $validator->validateAgainstJsonSchema($this->getValidData(), (object) $this->getSimpleSchema());
        }

        $this->assertSame($size, $loader->cacheSize());
    }

    // --- Basic Validation Tests ---

    public function testValidDataPassesValidation(): void
    {
        $schema = $this->getSimpleSchema();
        $data = $this->getValidData();

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

        $this->assertEmpty($errors);
    }

    public function testInvalidTypeGeneratesTypeError(): void
    {
        $schema = $this->getSimpleSchema();
        $data = $this->getValidData();
        $data['age'] = 'thirty'; // Invalid type

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

        $this->assertCount(1, $errors);
        $this->assertEquals('/age', $errors[0]['pointer']);
        $this->assertEquals('type', $errors[0]['keyword']);
        $this->assertStringContainsString('Expected `integer`', $errors[0]['message']);
    }

    public function testMissingRequiredPropertyGeneratesRequiredError(): void
    {
        $schema = $this->getSimpleSchema();
        $data = $this->getValidData();
        unset($data['name']); // Missing required

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('required', $errors[0]['keyword']);
        $this->assertStringContainsString('Missing required properties: `name`', $errors[0]['message']);
    }

    public function testAdditionalPropertyGeneratesAdditionalPropertiesError(): void
    {
        $schema = $this->getSimpleSchema();
        $data = $this->getValidData();
        $data['extra'] = 'not allowed'; // Additional property

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('/', $errors[0]['pointer']); // Error reported at the object root
        $this->assertEquals('additionalProperties', $errors[0]['keyword']);
        $this->assertStringContainsString('Object contains unexpected additional properties: `extra`.', $errors[0]['message']);
    }

    // --- Keyword Constraint Tests ---

    public function testEnumConstraintViolation(): void
    {
        $schema = ['type' => 'string', 'enum' => ['A', 'B']];
        $data = 'C';

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('enum', $errors[0]['keyword']);
        $this->assertStringContainsString('must be one of the allowed values: "A", "B"', $errors[0]['message']);
    }

    public function testMinimumConstraintViolation(): void
    {
        $schema = ['type' => 'integer', 'minimum' => 10];
        $data = 5;

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('minimum', $errors[0]['keyword']);
        $this->assertStringContainsString('must be greater than or equal to 10', $errors[0]['message']);
    }

    public function testMaxLengthConstraintViolation(): void
    {
        $schema = ['type' => 'string', 'maxLength' => 5];
        $data = 'toolong';

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('maxLength', $errors[0]['keyword']);
        $this->assertStringContainsString('String must not be longer than 5 characters.', $errors[0]['message']);
    }

    public function testPatternConstraintViolation(): void
    {
        $schema = ['type' => 'string', 'pattern' => '^[a-z]+$'];
        $data = '123';

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('pattern', $errors[0]['keyword']);
        $this->assertStringContainsString('does not match the required pattern: `^[a-z]+$`', $errors[0]['message']);
    }

    public function testMinItemsConstraintViolation(): void
    {
        $schema = ['type' => 'array', 'minItems' => 2];
        $data = ['one'];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('minItems', $errors[0]['keyword']);
        $this->assertStringContainsString('Array must contain at least 2 items.', $errors[0]['message']);
    }

    public function testUniqueItemsConstraintViolation(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $data = ['a', 'b', 'a'];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('uniqueItems', $errors[0]['keyword']);
        $this->assertSame('Array items must be unique.', $errors[0]['message']);
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideCamelCaseKeywordViolations')]
    public function testCamelCaseKeywordViolationUsesSdkMessage(array $schema, mixed $data, string $keyword, string $message): void
    {
        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertSame($keyword, $errors[0]['keyword']);
        $this->assertSame($message, $errors[0]['message']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, mixed, string, string}>
     */
    public static function provideCamelCaseKeywordViolations(): iterable
    {
        yield 'minLength' => [['type' => 'string', 'minLength' => 3], 'ab', 'minLength', 'String must be at least 3 characters long.'];
        yield 'maxLength' => [['type' => 'string', 'maxLength' => 2], 'abc', 'maxLength', 'String must not be longer than 2 characters.'];
        yield 'exclusiveMinimum' => [['type' => 'number', 'exclusiveMinimum' => 5], 5, 'exclusiveMinimum', 'Number must be strictly greater than 5.'];
        yield 'exclusiveMaximum' => [['type' => 'number', 'exclusiveMaximum' => 5], 5, 'exclusiveMaximum', 'Number must be strictly less than 5.'];
        yield 'multipleOf' => [['type' => 'number', 'multipleOf' => 3], 7, 'multipleOf', 'Number must be a multiple of 3.'];
        yield 'minItems' => [['type' => 'array', 'minItems' => 2], ['a'], 'minItems', 'Array must contain at least 2 items.'];
        yield 'maxItems' => [['type' => 'array', 'maxItems' => 1], ['a', 'b'], 'maxItems', 'Array must contain no more than 1 items.'];
        yield 'uniqueItems' => [['type' => 'array', 'uniqueItems' => true], ['a', 'a'], 'uniqueItems', 'Array items must be unique.'];
        yield 'minProperties' => [['type' => 'object', 'minProperties' => 2], ['a' => 1], 'minProperties', 'Object must have at least 2 properties.'];
        yield 'maxProperties' => [['type' => 'object', 'maxProperties' => 1], ['a' => 1, 'b' => 2], 'maxProperties', 'Object must have no more than 1 properties.'];
        yield 'additionalProperties' => [['type' => 'object', 'additionalProperties' => false], ['a' => 1], 'additionalProperties', 'Object contains unexpected additional properties: `a`.'];
    }

    // --- Nested Structures and Pointers ---
    public function testNestedObjectValidationErrorPointer(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ],
            ],
            'required' => ['user'],
        ];
        $data = ['user' => ['id' => 'abc']]; // Invalid nested type

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('/user/id', $errors[0]['pointer']);
    }

    public function testArrayItemValidationErrorPointer(): void
    {
        $schema = [
            'type' => 'array',
            'items' => ['type' => 'integer'],
        ];
        $data = [1, 2, 'three', 4]; // Invalid item type

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('/2', $errors[0]['pointer']); // Pointer to the index of the invalid item
    }

    public function testRefValidationErrorPointerIncludesFullPath(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'addr' => ['$ref' => '#/$defs/address'],
            ],
            '$defs' => [
                'address' => [
                    'type' => 'object',
                    'properties' => ['zip' => ['type' => 'string']],
                ],
            ],
        ];
        $data = ['addr' => ['zip' => 12345]];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertSame('/addr/zip', $errors[0]['pointer']);
        $this->assertSame('type', $errors[0]['keyword']);
    }

    // --- Data Conversion Tests ---
    public function testValidatesDataPassedAsStdClassObject(): void
    {
        $schema = $this->getSimpleSchema();
        $dataObj = json_decode(json_encode($this->getValidData(), \JSON_THROW_ON_ERROR)); // Convert to stdClass

        $errors = $this->validator->validateAgainstJsonSchema($dataObj, $schema);
        $this->assertEmpty($errors);
    }

    public function testValidatesDataWithNestedAssociativeArraysCorrectly(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'nested' => [
                    'type' => 'object',
                    'properties' => ['key' => ['type' => 'string']],
                    'required' => ['key'],
                ],
            ],
            'required' => ['nested'],
        ];
        $data = ['nested' => ['key' => 'value']]; // Nested assoc array

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertEmpty($errors);
    }

    // --- Edge Cases ---
    public function testHandlesInvalidSchemaStructureGracefully(): void
    {
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 123]]]; // Invalid type value
        $data = ['name' => 'test'];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('internal', $errors[0]['keyword']);
        $this->assertStringContainsString('Schema validation process failed', $errors[0]['message']);
    }

    public function testHandlesEmptyDataObjectAgainstSchemaRequiringProperties(): void
    {
        $schema = $this->getSimpleSchema(); // Requires name, age etc.
        $data = new \stdClass(); // Empty data

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

        $this->assertNotEmpty($errors);
        $this->assertEquals('required', $errors[0]['keyword']);
    }

    public function testEmptyArrayValidatesAgainstArraySchema(): void
    {
        $this->assertSame([], $this->validator->validateAgainstJsonSchema([], ['type' => 'array']));
    }

    public function testEmptyArrayIsNotAnObject(): void
    {
        $errors = $this->validator->validateAgainstJsonSchema([], ['type' => 'object']);

        $this->assertNotEmpty($errors);
        $this->assertSame('type', $errors[0]['keyword']);
    }

    public function testEmptyStdClassValidatesAgainstObjectSchema(): void
    {
        $this->assertSame([], $this->validator->validateAgainstJsonSchema(new \stdClass(), ['type' => 'object']));
    }

    /**
     * @param array<string, mixed> $schema
     */
    #[DataProvider('provideEmptyArraysDecodedFromObjects')]
    public function testEmptyArrayMayBeAnObject(string $json, array $schema): void
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame([], $this->validator->validateAgainstJsonSchema($data, $schema, emptyArrayMayBeObject: true));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideEmptyArraysDecodedFromObjects(): iterable
    {
        yield 'root object' => ['{}', ['type' => 'object']];
        yield 'nested object' => ['{"parameters":{}}', [
            'type' => 'object',
            'properties' => ['parameters' => ['type' => 'object']],
        ]];
        yield 'nested array' => ['{"tags":[]}', [
            'type' => 'object',
            'properties' => ['tags' => ['type' => 'array']],
        ]];
        yield 'array item' => ['[{}]', ['type' => 'array', 'items' => ['type' => 'object']]];
        yield 'additionalProperties' => ['{"a":{}}', [
            'type' => 'object',
            'additionalProperties' => ['type' => 'object'],
        ]];
        yield 'local $ref' => ['{"addr":{}}', [
            'type' => 'object',
            'properties' => ['addr' => ['$ref' => '#/$defs/address']],
            '$defs' => ['address' => ['type' => 'object']],
        ]];
        yield 'object or array, sent as object' => ['{"p":{}}', [
            'type' => 'object',
            'properties' => ['p' => ['oneOf' => [['type' => 'object'], ['type' => 'array']]]],
        ]];
        yield 'object or array, sent as array' => ['{"p":[]}', [
            'type' => 'object',
            'properties' => ['p' => ['oneOf' => [['type' => 'object'], ['type' => 'array']]]],
        ]];
        yield 'object or string' => ['{"p":{}}', [
            'type' => 'object',
            'properties' => ['p' => ['anyOf' => [['type' => 'object'], ['type' => 'string']]]],
        ]];
    }

    public function testNestedEmptyArrayIsNotAnObjectByDefault(): void
    {
        $schema = ['type' => 'object', 'properties' => ['parameters' => ['type' => 'object']]];

        $errors = $this->validator->validateAgainstJsonSchema(['parameters' => []], $schema);

        $this->assertCount(1, $errors);
        $this->assertSame('/parameters', $errors[0]['pointer']);
    }

    public function testNonEmptyListIsNotAnObjectEvenIfEmptyArraysMayBe(): void
    {
        $schema = ['type' => 'object', 'properties' => ['parameters' => ['type' => 'object']]];

        $errors = $this->validator->validateAgainstJsonSchema(['parameters' => ['x']], $schema, emptyArrayMayBeObject: true);

        $this->assertCount(1, $errors);
        $this->assertSame('/parameters', $errors[0]['pointer']);
    }

    public function testEmptyObjectStillHasToMeetTheObjectSchema(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['parameters' => ['type' => 'object', 'required' => ['id']]],
        ];

        $errors = $this->validator->validateAgainstJsonSchema(['parameters' => []], $schema, emptyArrayMayBeObject: true);

        $this->assertCount(1, $errors);
        $this->assertSame('required', $errors[0]['keyword']);
    }

    public function testHandlesEmptySchemaAllowsAnything(): void
    {
        $schema = []; // Empty schema object/array implies no constraints
        $data = ['anything' => [1, 2], 'goes' => true];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

        $this->assertNotEmpty($errors);
        $this->assertEquals('internal', $errors[0]['keyword']);
        $this->assertStringContainsString('Invalid schema', $errors[0]['message']);
    }

    public function testValidatesSchemaWithStringFormatConstraintsFromSchemaAttribute(): void
    {
        $emailSchema = (new Schema(format: 'email'))->toArray();

        // Valid email
        $validErrors = $this->validator->validateAgainstJsonSchema('user@example.com', $emailSchema);
        $this->assertEmpty($validErrors);

        // Invalid email
        $invalidErrors = $this->validator->validateAgainstJsonSchema('not-an-email', $emailSchema);
        $this->assertNotEmpty($invalidErrors);
        $this->assertEquals('format', $invalidErrors[0]['keyword']);
        $this->assertStringContainsString('email', $invalidErrors[0]['message']);
    }

    public function testValidatesSchemaWithStringLengthConstraintsFromSchemaAttribute(): void
    {
        $passwordSchema = (new Schema(minLength: 8, pattern: '^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$'))->toArray();

        // Valid password (meets length and pattern)
        $validErrors = $this->validator->validateAgainstJsonSchema('Password123', $passwordSchema);
        $this->assertEmpty($validErrors);

        // Invalid - too short
        $shortErrors = $this->validator->validateAgainstJsonSchema('Pass1', $passwordSchema);
        $this->assertNotEmpty($shortErrors);
        $this->assertEquals('minLength', $shortErrors[0]['keyword']);

        // Invalid - no digit
        $noDigitErrors = $this->validator->validateAgainstJsonSchema('PasswordXYZ', $passwordSchema);
        $this->assertNotEmpty($noDigitErrors);
        $this->assertEquals('pattern', $noDigitErrors[0]['keyword']);
    }

    public function testValidatesSchemaWithNumericConstraintsFromSchemaAttribute(): void
    {
        $ageSchema = (new Schema(minimum: 18, maximum: 120))->toArray();

        // Valid age
        $validErrors = $this->validator->validateAgainstJsonSchema(25, $ageSchema);
        $this->assertEmpty($validErrors);

        // Invalid - too low
        $tooLowErrors = $this->validator->validateAgainstJsonSchema(15, $ageSchema);
        $this->assertNotEmpty($tooLowErrors);
        $this->assertEquals('minimum', $tooLowErrors[0]['keyword']);

        // Invalid - too high
        $tooHighErrors = $this->validator->validateAgainstJsonSchema(150, $ageSchema);
        $this->assertNotEmpty($tooHighErrors);
        $this->assertEquals('maximum', $tooHighErrors[0]['keyword']);
    }

    public function testValidatesSchemaWithArrayConstraintsFromSchemaAttribute(): void
    {
        $tagsSchema = (new Schema(uniqueItems: true, minItems: 2))->toArray();

        // Valid tags array
        $validErrors = $this->validator->validateAgainstJsonSchema(['php', 'javascript', 'python'], $tagsSchema);
        $this->assertEmpty($validErrors);

        // Invalid - duplicate items
        $duplicateErrors = $this->validator->validateAgainstJsonSchema(['php', 'php', 'javascript'], $tagsSchema);
        $this->assertNotEmpty($duplicateErrors);
        $this->assertEquals('uniqueItems', $duplicateErrors[0]['keyword']);

        // Invalid - too few items
        $tooFewErrors = $this->validator->validateAgainstJsonSchema(['php'], $tagsSchema);
        $this->assertNotEmpty($tooFewErrors);
        $this->assertEquals('minItems', $tooFewErrors[0]['keyword']);
    }

    public function testValidatesSchemaWithObjectConstraintsFromSchemaAttribute(): void
    {
        $userSchema = (new Schema(
            properties: [
                'name' => ['type' => 'string', 'minLength' => 2],
                'email' => ['type' => 'string', 'format' => 'email'],
                'age' => ['type' => 'integer', 'minimum' => 18],
            ],
            required: ['name', 'email']
        ))->toArray();

        // Valid user object
        $validUser = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 25,
        ];
        $validErrors = $this->validator->validateAgainstJsonSchema($validUser, $userSchema);
        $this->assertEmpty($validErrors);

        // Invalid - missing required email
        $missingEmailUser = [
            'name' => 'John',
            'age' => 25,
        ];
        $missingErrors = $this->validator->validateAgainstJsonSchema($missingEmailUser, $userSchema);
        $this->assertNotEmpty($missingErrors);
        $this->assertEquals('required', $missingErrors[0]['keyword']);

        // Invalid - name too short
        $shortNameUser = [
            'name' => 'J',
            'email' => 'john@example.com',
            'age' => 25,
        ];
        $nameErrors = $this->validator->validateAgainstJsonSchema($shortNameUser, $userSchema);
        $this->assertNotEmpty($nameErrors);
        $this->assertEquals('minLength', $nameErrors[0]['keyword']);

        // Invalid - age too low
        $youngUser = [
            'name' => 'John',
            'email' => 'john@example.com',
            'age' => 15,
        ];
        $ageErrors = $this->validator->validateAgainstJsonSchema($youngUser, $userSchema);
        $this->assertNotEmpty($ageErrors);
        $this->assertEquals('minimum', $ageErrors[0]['keyword']);
    }

    public function testValidatesSchemaWithNestedConstraintsFromSchemaAttribute(): void
    {
        $orderSchema = (new Schema(
            properties: [
                'customer' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'pattern' => '^CUS-[0-9]{6}$'],
                        'name' => ['type' => 'string', 'minLength' => 2],
                    ],
                ],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'string', 'pattern' => '^PRD-[0-9]{4}$'],
                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'required' => ['product_id', 'quantity'],
                    ],
                ],
            ],
            required: ['customer', 'items']
        ))->toArray();

        // Valid order
        $validOrder = [
            'customer' => [
                'id' => 'CUS-123456',
                'name' => 'John',
            ],
            'items' => [
                [
                    'product_id' => 'PRD-1234',
                    'quantity' => 2,
                ],
            ],
        ];
        $validErrors = $this->validator->validateAgainstJsonSchema($validOrder, $orderSchema);
        $this->assertEmpty($validErrors);

        // Invalid - bad customer ID format
        $badCustomerIdOrder = [
            'customer' => [
                'id' => 'CUST-123', // Wrong format
                'name' => 'John',
            ],
            'items' => [
                [
                    'product_id' => 'PRD-1234',
                    'quantity' => 2,
                ],
            ],
        ];
        $customerIdErrors = $this->validator->validateAgainstJsonSchema($badCustomerIdOrder, $orderSchema);
        $this->assertNotEmpty($customerIdErrors);
        $this->assertEquals('pattern', $customerIdErrors[0]['keyword']);

        // Invalid - empty items array
        $emptyItemsOrder = [
            'customer' => [
                'id' => 'CUS-123456',
                'name' => 'John',
            ],
            'items' => [],
        ];
        $emptyItemsErrors = $this->validator->validateAgainstJsonSchema($emptyItemsOrder, $orderSchema);
        $this->assertNotEmpty($emptyItemsErrors);
        $this->assertEquals('minItems', $emptyItemsErrors[0]['keyword']);

        // Invalid - missing required property in items
        $missingProductIdOrder = [
            'customer' => [
                'id' => 'CUS-123456',
                'name' => 'John',
            ],
            'items' => [
                [
                    // Missing product_id
                    'quantity' => 2,
                ],
            ],
        ];
        $missingProductIdErrors = $this->validator->validateAgainstJsonSchema($missingProductIdOrder, $orderSchema);
        $this->assertNotEmpty($missingProductIdErrors);
        $this->assertEquals('required', $missingProductIdErrors[0]['keyword']);
    }

    /**
     * @return array{
     *     type: 'object',
     *     properties: array<string, array<string, mixed>>,
     *     required: string[],
     *     additionalProperties: false,
     * }
     */
    private function getSimpleSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The name'],
                'age' => ['type' => 'integer', 'minimum' => 0],
                'active' => ['type' => 'boolean'],
                'score' => ['type' => 'number'],
                'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                'status' => ['enum' => [null, 'active', 'inactive']],
                'nullableValue' => ['type' => ['string', 'null']],
                'optionalValue' => ['type' => 'string'],
            ],
            'required' => ['name', 'age', 'active', 'score', 'items', 'nullableValue'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{
     *     name: string,
     *     age: int,
     *     active: bool,
     *     score: float,
     *     items: string[],
     *     status: 'active'|'inactive'|null,
     *     nullableValue: null,
     *     optionalValue: string
     * }
     */
    private function getValidData(): array
    {
        return [
            'name' => 'Tester',
            'age' => 30,
            'active' => true,
            'score' => 99.5,
            'items' => ['a', 'b'],
            'status' => null,
            'nullableValue' => null,
            'optionalValue' => 'present',
        ];
    }
}
