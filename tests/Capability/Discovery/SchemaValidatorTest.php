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

namespace Mcp\Tests\Capability\Discovery;

use Mcp\Capability\Attribute\Schema;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\TestCase;

class SchemaValidatorTest extends TestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SchemaValidator();
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
        $this->assertStringContainsString('Additional object properties are not allowed: ["extra"]', $errors[0]['message']);
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
        $this->assertStringContainsString('Maximum string length is 5, found 7', $errors[0]['message']);
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
        $this->assertStringContainsString('Array should have at least 2 items, 1 found', $errors[0]['message']);
    }

    public function testUniqueItemsConstraintViolation(): void
    {
        $schema = ['type' => 'array', 'uniqueItems' => true];
        $data = ['a', 'b', 'a'];

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);
        $this->assertCount(1, $errors);
        $this->assertEquals('uniqueItems', $errors[0]['keyword']);
        $this->assertStringContainsString('Array must have unique items', $errors[0]['message']);
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

    // --- Data Conversion Tests ---
    public function testValidatesDataPassedAsStdClassObject(): void
    {
        $schema = $this->getSimpleSchema();
        $dataObj = json_decode(json_encode($this->getValidData())); // Convert to stdClass

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
        $data = []; // Empty data

        $errors = $this->validator->validateAgainstJsonSchema($data, $schema);

        $this->assertNotEmpty($errors);
        $this->assertEquals('required', $errors[0]['keyword']);
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
        $tagsSchema = (new Schema(minItems: 2, uniqueItems: true))->toArray();

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
            'nullableValue' => null,
            'optionalValue' => 'present',
        ];
    }
}
