# Schema Generation and Validation

The SDK automatically generates JSON schemas for **tool parameters** using a sophisticated priority system. Schema
generation applies to both attribute-discovered and manually registered tools.

## Schema Generation Priority

The server follows this order of precedence:

1. **Method-level `#[Schema]` with `definition`**: replaces the whole input schema (highest priority)
2. **Parameter-level `#[Schema]`**: adds constraints to one parameter. With `definition`, it replaces the schema of
   that parameter only
3. **Method-level `#[Schema]`**: method-wide configuration
4. **PHP type hints + docblocks**: automatic inference (lowest priority)

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

The generator also reads these parts of the signature:

```php
use Mcp\Server\RequestContext;

enum Unit: string
{
    case Celsius = 'celsius';
    case Fahrenheit = 'fahrenheit';
}

/**
 * @param string   $city   The city to look up
 * @param string[] $fields The fields to return
 */
#[McpTool]
public function getWeather(string $city, Unit $unit, array $fields, RequestContext $context): array
{
    // ...
}
```

In this example:

- The `@param` descriptions become the `description` of each property.
- The `string[]` docblock type becomes `{"type": "array", "items": {"type": "string"}}`.
- The `Unit` enum becomes `{"type": "string", "enum": ["celsius", "fahrenheit"]}`. A backed enum lists its backing
  values, a unit enum lists its case names. The SDK passes the matching enum case to your method.
- `$context` is not part of the schema. The SDK injects `RequestContext` and `ClientGateway` parameters itself, see
  [Talking back to the client](../handlers/client-communication.md).

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

To replace the schema of one parameter only, put `definition` on the parameter. The parameter's default value is
still added:

```php
#[McpTool]
public function getForecast(
    #[Schema(definition: ['type' => 'string', 'format' => 'date'])]
    string $date = '2026-01-01',
): array {
    // ...
}
```

## Argument Validation

Before a `tools/call` reaches your method, the SDK validates the arguments against the tool's input schema. If they
don't match, your method is not called and the client gets a JSON-RPC error with code `-32602`:

```json
{
  "jsonrpc": "2.0",
  "id": 3,
  "error": {
    "code": -32602,
    "message": "Invalid parameters for tool 'validateUser': Property '/age': Number must be greater than or equal to 18.",
    "data": {
      "validation_errors": [
        { "pointer": "/age", "keyword": "minimum", "message": "Number must be greater than or equal to 18." }
      ]
    }
  }
}
```

The message lists the first three errors. `validation_errors` contains all of them.

The SDK also validates structured tool output against the tool's `outputSchema`, see
[Output validation](tools.md#output-validation).

## Customizing the Generator and Validator

The SDK validates with [opis/json-schema](https://opis.io/json-schema/). To configure it, for example to resolve
external `$ref` schemas, pass your own `SchemaValidator` to `Builder::setSchemaValidator()`:

```php
use Mcp\Capability\Discovery\SchemaValidator;
use Opis\JsonSchema\Validator;

$validator = new Validator();
// resolves "https://example.com/schemas/address.json" from schemas/address.json
$validator->resolver()->registerPrefix('https://example.com/schemas/', __DIR__.'/schemas');

$server = Server::builder()
    ->setSchemaValidator(new SchemaValidator($validator))
    // ...
    ->build();
```

To build input schemas in a different way, pass your own `SchemaGeneratorInterface` implementation to
`Builder::setSchemaGenerator()`.
