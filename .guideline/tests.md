# PHP MCP SDK Style Guide

This style guide is based on the analysis of the Model Context Protocol (MCP) PHP SDK test codebase and establishes the
conventions used throughout this framework.

## 1. Project Overview

- **PHP Version**: 8.1+
- **Framework**: Custom MCP SDK (Model Context Protocol)
- **Architecture Pattern**: Modular SDK with Discovery, Registry, and Capability patterns
- **Key Dependencies**: PHPUnit, PHPStan, PHP-CS-Fixer, PHPDocumentor, Symfony Components

## 2. File Structure & Organization

### Header Comment Pattern

Every PHP file must start with this exact header comment:

```php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
```

### Namespace Organization

- Root namespace: `Mcp\`
- Tests namespace: `Mcp\Tests\`
- Directory structure mirrors namespace structure exactly
- Each subdirectory represents a namespace segment

## 3. Naming Conventions

### Class Names

- **PascalCase** for all class names
- Descriptive, purpose-driven names
- Suffix patterns:
    - `Test` for test classes
    - `Fixture` for test fixture classes
    - `Handler` for handler classes
    - `Interface` for interface classes
    - `Exception` for exception classes

```php
// Class naming examples
class DiscoveryTest extends TestCase           // Test class
class DocBlockTestFixture                      // Test fixture
class DiscoverableToolHandler                  // Handler class
class HandlerResolverTest extends TestCase     // Test class
class SchemaGeneratorFixture                   // Comprehensive fixture
```

### Method Names

- **camelCase** for all method names
- Test methods: `test` + descriptive name in camelCase
- Helper methods: descriptive camelCase names
- Magic methods: standard PHP magic method names

```php
// Method naming examples
public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles(): void
public function testDoesNotDiscoverElementsFromExcludedDirectories(): void
protected function setUp(): void
private function getValidData(): array
public function __invoke(): array
```

### Variable and Property Names

- **camelCase** for variables and properties
- Descriptive names without abbreviated forms
- Boolean variables should use `is`, `has`, `can`, or `should` prefixes when appropriate
- Array variables should use plural nouns

```php
// Variable naming examples
private Registry $registry;
private Discoverer $discoverer;
private DocBlockParser $parser;
$discoveredCount = [];
$reflectionClass = new \ReflectionClass($className);
$isManual = false;
$completionProviders = [];
```

### Constants

- **UPPER_SNAKE_CASE** for constants
- Class constants when they belong to a specific class context

```php
// Constant examples (based on common PHP patterns)
const DEFAULT_TIMEOUT = 30;
const MAX_RETRY_ATTEMPTS = 3;
```

## 4. Code Structure Standards

### Type Declarations

- **Return type hints**: Required for all methods except test methods
- **Parameter type hints**: Required where possible
- **Property type hints**: Required for all properties

```php
// Type declaration examples
public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles(): void
protected function setUp(): void
private function getSimpleSchema(): array
public function generate(\ReflectionMethod $method): array
private DocBlockParser $parser;
```

### Class Organization Pattern

Properties and methods should be ordered by visibility and purpose:

```php
class ExampleClass extends TestCase
{
    // 1. Properties (private first, then protected, then public)
    private Registry $registry;
    private Discoverer $discoverer;
    
    // 2. setUp/tearDown methods
    protected function setUp(): void
    {
        // initialization code
    }
    
    // 3. Test methods (for test classes) or public methods (for regular classes)
    public function testMethodName(): void
    {
        // test implementation
    }
    
    // 4. Private/protected helper methods
    private function getValidData(): array
    {
        // helper implementation
    }
}
```

### Import Organization

- Group imports by type: built-in PHP classes first, then external dependencies, then internal project classes
- Alphabetical order within each group
- Use individual imports, avoid group imports

## 5. Method & Function Patterns

### Method Signatures

```php
// Public test methods - no return type for tests
public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles()

// Helper methods - explicit return types
private function getSimpleSchema(): array
protected function getValidData(): array

// Methods with complex parameters
public function testAppliesStringConstraintsFromParameterLevelSchemaAttributes(): void

// Methods with nullable parameters
public function parseDocBlock(?string $docComment): ?DocBlock
```

### Parameter Patterns

- Use nullable types (`?Type`) instead of union with null when appropriate
- Default values should be meaningful
- Complex objects should be type-hinted with full namespaces when needed

```php
// Parameter examples
public function __construct(
    private readonly Registry $registry,
    private readonly LoggerInterface $logger = new NullLogger(),
    private ?DocBlockParser $docBlockParser = null,
    private ?SchemaGenerator $schemaGenerator = null,
) {
    // initialization
}
```

## 6. Testing Patterns

### Test Class Structure

```php
class ExampleTest extends TestCase
{
    private ExampleClass $subjectUnderTest;
    
    protected function setUp(): void
    {
        $this->subjectUnderTest = new ExampleClass();
    }
    
    public function testSpecificBehavior(): void
    {
        // Arrange
        $input = $this->getTestData();
        
        // Act
        $result = $this->subjectUnderTest->process($input);
        
        // Assert
        $this->assertInstanceOf(ExpectedClass::class, $result);
        $this->assertEquals($expectedValue, $result->getValue());
    }
    
    private function getTestData(): array
    {
        return ['key' => 'value'];
    }
}
```

### Test Method Naming

- Must start with `test`
- Followed by clear description of what is being tested
- Use camelCase after the `test` prefix
- Be descriptive - method names can be long if necessary

```php
// Test method naming examples
public function testDiscoversAllElementTypesCorrectlyFromFixtureFiles(): void
public function testDoesNotDiscoverElementsFromExcludedDirectories(): void
public function testHandlesEmptyDirectoriesOrDirectoriesWithNoPhpFiles(): void
public function testCorrectlyInfersNamesAndDescriptionsFromMethodsOrClassesIfNotSetInAttribute(): void
```

### Assertion Patterns

- Use specific assertions over generic ones
- Group related assertions together
- Use `assertInstanceOf` for type checking
- Use `assertCount` for array/collection size checking
- Use `assertEqualsCanonicalizing` for arrays where order doesn't matter

```php
// Assertion examples
$this->assertCount(4, $tools);
$this->assertInstanceOf(ToolReference::class, $greetUserTool);
$this->assertFalse($greetUserTool->isManual);
$this->assertEquals('greet_user', $greetUserTool->tool->name);
$this->assertArrayHasKey('name', $greetUserTool->tool->inputSchema['properties'] ?? []);
$this->assertEqualsCanonicalizing(['name', 'age', 'active', 'tags'], $schema['required']);
```

### Test Data Organization

- Use private helper methods for test data generation
- Follow naming pattern: `get{DataType}Data()` or `get{Purpose}Schema()`
- Return type-hinted arrays with PHPDoc when complex

```php
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
```

## 7. PHPDoc Documentation Standards

### Class Documentation

```php
/**
 * A stub class for testing DocBlock parsing.
 *
 * @author Author Name <email@example.com>
 */
class DocBlockTestFixture
{
}
```

### Method Documentation

- Always include `@param` for parameters with descriptions
- Include `@return` for non-void methods
- Use `@throws` for exceptions
- Include method description for complex methods

```php
/**
 * Method with various parameter tags.
 *
 * @param string               $param1 description for string param
 * @param int|null             $param2 description for nullable int param
 * @param bool                 $param3 nothing to say
 * @param                      $param4 Missing type
 * @param array<string, mixed> $param5 array description
 * @param \stdClass            $param6 object param
 */
public function methodWithParams(string $param1, ?int $param2, bool $param3, $param4, array $param5, \stdClass $param6): void
```

### Complex Type Documentation

Use PHPStan-style type definitions for complex structures:

```php
/**
 * @phpstan-type DiscoveredCount array{
 *     tools: int,
 *     resources: int,  
 *     prompts: int,
 *     resourceTemplates: int,
 * }
 */
```

## 8. Code Quality Rules

### Error Handling

- Use specific exception types where available
- Always provide meaningful error messages
- Log errors appropriately in service classes

```php
try {
    $reflectionClass = new \ReflectionClass($className);
    // process class
} catch (\ReflectionException $e) {
    $this->logger->error('Reflection error processing file for MCP discovery', [
        'file' => $filePath, 
        'class' => $className, 
        'exception' => $e->getMessage()
    ]);
}
```

### Method Complexity

- Keep methods focused on single responsibilities
- Extract complex logic into private helper methods
- Test methods can be longer but should remain readable
- Use early returns to reduce nesting

### Null Safety

- Use nullable type hints (`?Type`) appropriately
- Check for null values before usage
- Prefer null coalescing operator (`??`) when appropriate

```php
$docComment = $method->getDocComment() ?: null;
$docBlock = $this->parser->parseDocBlock($docComment);
$name = $instance->name ?? ('__invoke' === $methodName ? $classShortName : $methodName);
```

## 9. Framework-Specific Guidelines

### Attribute Usage

The codebase uses PHP 8 attributes extensively:

```php
#[McpTool(name: 'greet_user', description: 'Greets a user by name.')]
public function greet(string $name): string
{
    return "Hello, {$name}!";
}

#[McpResource(
    uri: 'app://info/version',
    name: 'app_version', 
    description: 'The current version of the application.',
    mimeType: 'text/plain',
    size: 10
)]
public function getAppVersion(): string
{
    return '1.2.3-discovered';
}
```

### Registry Pattern

Use the registry pattern for managing discovered elements:

```php
$this->registry->registerTool($tool, [$className, $methodName]);
$this->registry->registerResource($resource, [$className, $methodName]);
```

### Reflection Usage

Use reflection appropriately for discovery mechanisms:

```php
$reflectionClass = new \ReflectionClass($className);
if ($reflectionClass->isAbstract() || $reflectionClass->isInterface()) {
    return;
}

foreach ($reflectionClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
    // process methods
}
```

## 10. Example Templates

### Basic Test Class Template

```php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Example;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    private ExampleService $service;
    
    protected function setUp(): void
    {
        $this->service = new ExampleService();
    }
    
    public function testExampleBehavior(): void
    {
        $result = $this->service->performAction();
        
        $this->assertInstanceOf(ExpectedResult::class, $result);
    }
    
    private function getTestData(): array
    {
        return ['key' => 'value'];
    }
}
```

### Fixture Class Template

```php
<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Tests\Capability\Example;

/**
 * Fixture class for testing example functionality.
 */
class ExampleFixture
{
    public function methodWithParameters(string $param1, int $param2, bool $param3 = true): array
    {
        return compact('param1', 'param2', 'param3');
    }
    
    public function methodWithComplexReturn(): \stdClass
    {
        $result = new \stdClass();
        $result->value = 'test';
        return $result;
    }
}
```