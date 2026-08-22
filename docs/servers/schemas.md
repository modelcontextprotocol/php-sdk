# Schema Generation and Validation

The SDK automatically generates JSON schemas for **tool parameters** using a sophisticated priority system. Schema
generation applies to both attribute-discovered and manually registered tools.

## Schema Generation Priority

The server follows this order of precedence:

1. **`#[Schema]` attribute with `definition`** - Complete schema override (highest priority)
2. **Parameter-level `#[Schema]` attribute** - Parameter-specific enhancements
3. **Method-level `#[Schema]` attribute** - Method-wide configuration
4. **PHP type hints + docblocks** - Automatic inference (lowest priority)

## Automatic Schema from PHP Types

```php
#[McpTool]
public function processUser(
    string $email,           // Required string
    int $age,               // Required integer
    ?string $name = null,   // Optional string
    bool $active = true     // Boolean with default
): array
{
    // Schema auto-generated from method signature
}
```

## Parameter-Level Schema Enhancement

Add validation rules to specific parameters:

```php
use Mcp\Capability\Attribute\Schema;

#[McpTool]
public function validateUser(
    #[Schema(format: 'email')]
    string $email,
    
    #[Schema(minimum: 18, maximum: 120)]
    int $age,
    
    #[Schema(
        pattern: '^[A-Z][a-z]+$',
        description: 'Capitalized first name'
    )]
    string $firstName
): bool
{
    // PHP types provide base validation
    // Schema attributes add constraints
}
```

## Method-Level Schema

Add validation for complex object structures:

```php
#[McpTool]
#[Schema(
    properties: [
        'userData' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 2],
                'email' => ['type' => 'string', 'format' => 'email'],
                'age' => ['type' => 'integer', 'minimum' => 18]
            ],
            'required' => ['name', 'email']
        ]
    ],
    required: ['userData']
)]
public function createUser(array $userData): array
{
    // Method-level schema adds object structure validation
    // PHP array type provides base type
}
```

## Complete Schema Override

**Use sparingly** - bypasses all automatic inference:

```php
#[McpTool]
#[Schema(definition: [
    'type' => 'object',
    'properties' => [
        'endpoint' => ['type' => 'string', 'format' => 'uri'],
        'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE']],
        'headers' => [
            'type' => 'object',
            'patternProperties' => [
                '^[A-Za-z0-9-]+$' => ['type' => 'string']
            ]
        ]
    ],
    'required' => ['endpoint', 'method']
])]
public function makeApiRequest(string $endpoint, string $method, array $headers): array
{
    // Complete definition override - PHP types ignored
}
```

**Warning:** Only use complete schema override if you're well-versed with JSON Schema specification and have complex
validation requirements that cannot be achieved through the priority system.
