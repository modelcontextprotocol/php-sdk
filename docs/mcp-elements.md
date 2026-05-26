# MCP Elements

MCP elements are the core capabilities of your server: Tools, Resources, Resource Templates, and Prompts. These elements
define what your server can do and how clients can interact with it. The PHP MCP SDK provides both attribute-based
discovery and manual registration methods.

## Table of Contents

- [Overview](#overview)
- [Tools](#tools)
- [Resources](#resources)
- [Resource Templates](#resource-templates)
- [Prompts](#prompts)
- [Logging](#logging)
- [Completion Providers](#completion-providers)
- [Schema Generation and Validation](#schema-generation-and-validation)
- [Discovery vs Manual Registration](#discovery-vs-manual-registration)

## Overview

MCP defines four types of capabilities:

- **Tools**: Functions that can be called by clients to perform actions
- **Resources**: Data sources that clients can read (static URIs)  
- **Resource Templates**: URI templates for dynamic resources with variables
- **Prompts**: Template generators for AI prompts

### Registration Methods

Each capability can be registered using two methods:

1. **Attribute-Based Discovery**: Use PHP attributes (`#[McpTool]`, `#[McpResource]`, etc.) on methods or classes. The
   server automatically discovers and registers them.

2. **Manual Registration**: Explicitly register capabilities using `ServerBuilder` methods (`addTool()`, `addResource()`, etc.).

**Priority**: Manual registrations **always override** discovered elements with the same identifier:
- **Tools**: Same `name`
- **Resources**: Same `uri`
- **Resource Templates**: Same `uriTemplate`  
- **Prompts**: Same `name`

For manual registration details, see [Server Builder Manual Registration](server-builder.md#manual-capability-registration).

## Tools

Tools are callable functions that perform actions and return results.

```php
use Mcp\Capability\Attribute\McpTool;

class Calculator
{
    /**
     * Performs arithmetic operations with validation.
     */
    #[McpTool(name: 'calculate')]
    public function performCalculation(float $a, float $b, string $operation): float
    {
        return match($operation) {
            'add' => $a + $b,
            'subtract' => $a - $b,
            'multiply' => $a * $b,
            'divide' => $b != 0 ? $a / $b : throw new \InvalidArgumentException('Division by zero'),
            default => throw new \InvalidArgumentException('Invalid operation')
        };
    }
}
```

### Parameters

- **`name`** (optional): Tool identifier. Defaults to method name if not provided.
- **`description`** (optional): Tool description. Defaults to docblock summary if not provided, otherwise uses method name.
- **`annotations`** (optional): `ToolAnnotations` object for additional metadata.
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

**Priority for name/description**: Attribute parameters → DocBlock content → Method name

For tool parameter validation and JSON schema generation, see [Schema Generation and Validation](#schema-generation-and-validation).

### Tool Return Values

Tools can return any data type and the SDK will automatically wrap them in appropriate MCP content types.

#### Automatic Content Wrapping

```php
// Primitive types → TextContent
public function getString(): string { return "Hello"; }           // TextContent
public function getNumber(): int { return 42; }                  // TextContent  
public function getBool(): bool { return true; }                 // TextContent
public function getArray(): array { return ['key' => 'value']; } // TextContent (JSON)

// Special cases
public function getNull(): ?string { return null; }              // TextContent("(null)")
public function returnVoid(): void { /* no return */ }           // Empty content
```

#### Explicit Content Types

For fine control over output formatting:

```php
use Mcp\Schema\Content\{TextContent, ImageContent, AudioContent, EmbeddedResource};

public function getFormattedCode(): TextContent
{
    return TextContent::code('<?php echo "Hello";', 'php');
}

public function getMarkdown(): TextContent  
{
    return new TextContent('# Title\n\nMarkdown content');
}

public function getImage(): ImageContent
{
    return new ImageContent(
        data: base64_encode(file_get_contents('image.png')),
        mimeType: 'image/png'
    );
}

public function getAudio(): AudioContent
{
    return new AudioContent(
        data: base64_encode(file_get_contents('audio.mp3')),
        mimeType: 'audio/mpeg'
    );
}

public function getEmbeddedResource(): EmbeddedResource
{
    return new EmbeddedResource(
        type: 'resource',
        resource: ['uri' => 'file://data.json', 'text' => 'File content']
    );
}
```

#### Multiple Content Items

Return an array of content items:

```php
public function getMultipleContent(): array
{
    return [
        new TextContent('Here is the analysis:'),
        TextContent::code($code, 'php'),
        new TextContent('And here is the summary.')
    ];
}
```

#### Error Handling

Tool handlers can throw any exception, but the type determines how it's handled:

- **`ToolCallException`**: Converted to JSON-RPC response with `CallToolResult` where `isError: true`, allowing the LLM to see the error message and self-correct
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Exception\ToolCallException;

#[McpTool]
public function divideNumbers(float $a, float $b): float
{
    if ($b === 0.0) {
        throw new ToolCallException('Division by zero is not allowed');
    }

    return $a / $b;
}

#[McpTool]
public function processFile(string $filename): string
{
    if (!file_exists($filename)) {
        throw new ToolCallException("File not found: {$filename}");
    }

    return file_get_contents($filename);
}
```

**Recommendation**: Use `ToolCallException` when you want to communicate specific errors to clients. Any other exception will still be converted to JSON-RPC compliant errors but with generic error messages.


## Resources

Resources provide access to static data that clients can read.

```php
use Mcp\Capability\Attribute\McpResource;

class ConfigProvider
{
    /**
     * Provides the current application configuration.
     */
    #[McpResource(uri: 'config://app/settings', name: 'app_settings')]
    public function getSettings(): array
    {
        return [
            'version' => '1.0.0',
            'debug' => false,
            'features' => ['auth', 'logging']
        ];
    }
}
```

### Parameters

- **`uri`** (required): Unique resource identifier. Must comply with [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986).
- **`name`** (optional): Human-readable name. Defaults to method name if not provided.
- **`description`** (optional): Resource description. Defaults to docblock summary if not provided.
- **`mimeType`** (optional): MIME type of the resource content.
- **`size`** (optional): Size in bytes if known.
- **`annotations`** (optional): Additional metadata.
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

**Standard Protocol URI Schemes**: `https://` (web resources), `file://` (filesystem), `git://` (version control).
**Custom schemes**: `config://`, `data://`, `db://`, `api://` or any RFC 3986 compliant scheme.

### Resource Return Values

Resource handlers can return various data types that are automatically formatted into appropriate MCP resource content types.

#### Supported Return Types

```php
// String content - converted to text resource
public function getTextFile(): string 
{
    return "File content here";
}

// Array content - converted to JSON
public function getConfig(): array 
{
    return ['debug' => true, 'version' => '1.0'];
}

// Stream resource - read and converted to blob
public function getImageStream(): resource
{
    return fopen('image.png', 'r');
}

// SplFileInfo - file content with MIME type detection
public function getFileInfo(): \SplFileInfo
{
    return new \SplFileInfo('document.pdf');
}
```

**Explicit resource content types**

```php
use Mcp\Schema\Content\{TextResourceContents, BlobResourceContents};

public function getExplicitText(): TextResourceContents
{
    return new TextResourceContents(
        uri: 'config://app/settings',
        mimeType: 'application/json',
        text: json_encode(['setting' => 'value'])
    );
}

public function getExplicitBlob(): BlobResourceContents
{
    return new BlobResourceContents(
        uri: 'file://image.png',
        mimeType: 'image/png',
        blob: base64_encode(file_get_contents('image.png'))
    );
}
```

**Special Array Formats**

```php
// Array with 'text' key - used as text content
public function getTextArray(): array
{
    return ['text' => 'Content here', 'mimeType' => 'text/plain'];
}

// Array with 'blob' key - used as blob content  
public function getBlobArray(): array
{
    return ['blob' => base64_encode($data), 'mimeType' => 'image/png'];
}

// Multiple resource contents
public function getMultipleResources(): array
{
    return [
        new TextResourceContents('file://readme.txt', 'text/plain', 'README content'),
        new TextResourceContents('file://config.json', 'application/json', '{"key": "value"}')
    ];
}
```

#### Error Handling

Resource handlers can throw any exception, but the type determines how it's handled:

- **`ResourceReadException`**: Converted to JSON-RPC error response with the actual exception message
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Exception\ResourceReadException;

#[McpResource(uri: 'file://{path}')]
public function getFile(string $path): string
{
    if (!file_exists($path)) {
        throw new ResourceReadException("File not found: {$path}");
    }

    if (!is_readable($path)) {
        throw new ResourceReadException("File not readable: {$path}");
    }

    return file_get_contents($path);
}
```

**Recommendation**: Use `ResourceReadException` when you want to communicate specific errors to clients. Any other exception will still be converted to JSON-RPC compliant errors but with generic error messages.

## Resource Templates

Resource templates are **dynamic resources** that use parameterized URIs with variables. They follow all the same rules
as static resources (URI schemas, return values, MIME types, etc.) but accept variables using [RFC 6570 URI template syntax](https://datatracker.ietf.org/doc/html/rfc6570).

```php
use Mcp\Capability\Attribute\McpResourceTemplate;

class UserProvider
{
    /**
     * Retrieves user profile information by ID.
     */
    #[McpResourceTemplate(
        uriTemplate: 'user://{userId}/profile/{section}',
        name: 'user_profile',
        description: 'User profile data by section',
        mimeType: 'application/json'
    )]
    public function getUserProfile(string $userId, string $section): array
    {
        return $this->users[$userId][$section] ?? throw new \InvalidArgumentException("Profile section not found");
    }
}
```

### Parameters

- **`uriTemplate`** (required): URI template with `{variables}` using RFC 6570 syntax. Must comply with RFC 3986.
- **`name`** (optional): Human-readable name. Defaults to method name if not provided.
- **`description`** (optional): Template description. Defaults to docblock summary if not provided.
- **`mimeType`** (optional): MIME type of the resource content.
- **`annotations`** (optional): Additional metadata.

### Variable Rules

1. **Variable names must match exactly** between URI template and method parameters
2. **Parameter order matters** - variables are passed in the order they appear in the URI template  
3. **All variables are required** - no optional parameters supported
4. **Type hints work normally** - parameters can be typed (string, int, etc.)

**Example mapping**: `user://123/profile/settings` → `getUserProfile("123", "settings")`

## Prompts

Prompts generate templates for AI interactions.

```php
use Mcp\Capability\Attribute\McpPrompt;

class PromptGenerator
{
    /**
     * Generates a code review request prompt.
     */
    #[McpPrompt(name: 'code_review']
    public function reviewCode(string $language, string $code, string $focus = 'general'): array
    {
        return [
            ['role' => 'system', 'content' => 'You are an expert code reviewer.'],
            ['role' => 'user', 'content' => "Review this {$language} code focusing on {$focus}:\n\n```{$language}\n{$code}\n```"]
        ];
    }
}
```

### Parameters

- **`name`** (optional): Prompt identifier. Defaults to method name if not provided.
- **`description`** (optional): Prompt description. Defaults to docblock summary if not provided.
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

### Prompt Return Values

Prompt handlers must return an array of message structures that are automatically formatted into MCP prompt messages.

#### Supported Return Formats

```php
// Array of message objects with role and content
public function basicPrompt(): array
{
    return [
        ['role' => 'assistant', 'content' => 'You are a helpful assistant'],
        ['role' => 'user', 'content' => 'Hello, how are you?']
    ];
}

// Single message (automatically wrapped in array)
public function singleMessage(): array
{
    return [
        ['role' => 'user', 'content' => 'Write a poem about PHP']
    ];
}

// Associative array with user/assistant keys
public function userAssistantFormat(): array
{
    return [
        'user' => 'Explain how arrays work in PHP',
        'assistant' => 'Arrays in PHP are ordered maps...'
    ];
}

// Mixed content types in messages
use Mcp\Schema\Content\{TextContent, ImageContent};

public function mixedContent(): array
{
    return [
        [
            'role' => 'user', 
            'content' => [
                new TextContent('Analyze this image:'),
                new ImageContent(data: $imageData, mimeType: 'image/png')
            ]
        ]
    ];
}

// Using explicit PromptMessage objects
use Mcp\Schema\PromptMessage;
use Mcp\Schema\Enum\Role;

public function explicitMessages(): array
{
    return [
        new PromptMessage(Role::Assistant, [new TextContent('System instructions')]),
        new PromptMessage(Role::User, [new TextContent('User question')])
    ];
}
```

The SDK automatically validates that all messages have valid roles and converts the result into the appropriate MCP prompt message format.

#### Valid Message Roles

- **`user`**: User input or questions  
- **`assistant`**: Assistant responses/system 

#### Error Handling

Prompt handlers can throw any exception, but the type determines how it's handled:
- **`PromptGetException`**: Converted to JSON-RPC error response with the actual exception message
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Exception\PromptGetException;

#[McpPrompt]
public function generatePrompt(string $topic, string $style): array
{
    $validStyles = ['casual', 'formal', 'technical'];

    if (!in_array($style, $validStyles)) {
        throw new PromptGetException(
            "Invalid style '{$style}'. Must be one of: " . implode(', ', $validStyles)
        );
    }

    return [
        ['role' => 'user', 'content' => "Write about {$topic} in a {$style} style"]
    ];
}
```

**Recommendation**: Use `PromptGetException` when you want to communicate specific errors to clients. Any other exception will still be converted to JSON-RPC compliant errors but with generic error messages.

## Logging

The SDK provides support to send structured log messages to clients. All standard PSR-3 log levels are supported.
Level **warning** as the default level.

### Usage

The SDK automatically injects a `RequestContext` instance into handlers. This can be used to create a `ClientLogger`.

```php
use Mcp\Capability\Logger\ClientLogger;
use Mcp\Server\RequestContext;

#[McpTool]
public function processData(string $input, RequestContext $context): array {
    $logger = $context->getClientLogger();

    $logger->info('Processing started', ['input' => $input]);
    $logger->warning('Deprecated API used');
    
    // ... processing logic ...
    
    $logger->info('Processing completed');
    return ['result' => 'processed'];
}
```

## Completion Providers

Completion providers help MCP clients offer auto-completion suggestions for Resource Templates and Prompts. Unlike Tools and static Resources (which can be listed via `tools/list` and `resources/list`), Resource Templates and Prompts have dynamic parameters that benefit from completion hints.

### Completion Provider Types

#### 1. Value Lists

Provide a static list of possible values:

```php
use Mcp\Capability\Attribute\CompletionProvider;

#[McpPrompt]
public function generateContent(
    #[CompletionProvider(values: ['blog', 'article', 'tutorial', 'guide'])]
    string $contentType,
    
    #[CompletionProvider(values: ['beginner', 'intermediate', 'advanced'])]
    string $difficulty
): array
{
    return [
        ['role' => 'user', 'content' => "Create a {$difficulty} level {$contentType}"]
    ];
}
```

#### 2. Enum Classes

Use enum values for completion:

```php
enum Priority: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}

enum Status  // Unit enum
{
    case DRAFT;
    case PUBLISHED;
    case ARCHIVED;
}

#[McpResourceTemplate(uriTemplate: 'tasks/{taskId}')]
public function getTask(
    string $taskId,
    
    #[CompletionProvider(enum: Priority::class)]  // Uses backing values
    string $priority,
    
    #[CompletionProvider(enum: Status::class)]    // Uses case names
    string $status
): array
{
    // Implementation
}
```

#### 3. Custom Provider Classes

For dynamic completion logic:

```php
use Mcp\Capability\Prompt\Completion\ProviderInterface;

class UserIdCompletionProvider implements ProviderInterface
{
    public function __construct(private DatabaseService $db) {}

    public function getCompletions(string $currentValue): array
    {
        // Return dynamic completions based on current input
        return $this->db->searchUserIds($currentValue);
    }
}

#[McpResourceTemplate(uriTemplate: 'user://{userId}/profile')]
public function getUserProfile(
    #[CompletionProvider(provider: UserIdCompletionProvider::class)]
    string $userId
): array
{
    // Implementation
}
```

**Provider Resolution:**
- **Class strings** (`Provider::class`) → Resolved from PSR-11 container
- **Instances** (`new Provider()`) → Used directly
- **Values** (`['a', 'b']`) → Wrapped in `ListCompletionProvider`
- **Enums** (`MyEnum::class`) → Wrapped in `EnumCompletionProvider`

> **Important**
> 
> Completion providers only offer **suggestions** to users. Users can still input any value, so **always validate
> parameters** in your handlers. Providers don't enforce validation - they're purely for UX improvement.

## Schema Generation and Validation

The SDK automatically generates JSON schemas for **tool parameters** using a sophisticated priority system. Schema
generation applies to both attribute-discovered and manually registered tools.

### Schema Generation Priority

The server follows this order of precedence:

1. **`#[Schema]` attribute with `definition`** - Complete schema override (highest priority)
2. **Parameter-level `#[Schema]` attribute** - Parameter-specific enhancements
3. **Method-level `#[Schema]` attribute** - Method-wide configuration
4. **PHP type hints + docblocks** - Automatic inference (lowest priority)

### Automatic Schema from PHP Types

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

### Parameter-Level Schema Enhancement

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

### Method-Level Schema

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

### Complete Schema Override

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

### Custom Type Describers

When a tool parameter or return value is type-hinted with a class, the generator falls back to `{type: "object"}` and
the SDK has no idea how to turn the client's JSON into that class (or that class back into JSON). For value-object types
(timestamps, identifiers, money, whole DTOs, …) you register a **property handler** that teaches the SDK about the type
in up to three directions. Each direction is its own interface, so a handler opts into only what it needs; a single
class may implement any combination:

```php
use Mcp\Capability\Discovery\PropertyDescriberInterface;
use Mcp\Capability\Discovery\PropertyDenormalizerInterface;
use Mcp\Capability\Discovery\PropertyNormalizerInterface;

// All three share PropertyHandlerInterface::supportedClass(): class-string

interface PropertyDescriberInterface       // type → JSON Schema (input + output schema)
{
    public function describe(): array;
}

interface PropertyDenormalizerInterface    // client input → instance (tool arguments)
{
    public function denormalize(mixed $value, string $class): mixed;
}

interface PropertyNormalizerInterface      // instance → JSON (tool results)
{
    public function normalize(object $value): mixed;
}
```

A type is dispatched to a handler when it is `supportedClass()` **or any subtype of it** — so a handler for
`\DateTimeInterface` also covers `\DateTimeImmutable`, and one for `Uuid` covers `UuidV4`, `UuidV7`, etc. Handlers are
consulted in **registration order**; the first whose supported class matches wins.

Two handlers ship with the SDK (both opt-in), each implementing all three directions:

| Handler | Handles | Schema | Upcasts / normalizes |
| --- | --- | --- | --- |
| `Mcp\Capability\Discovery\PropertyDescriber\DateTimePropertyDescriber` | any `\DateTimeInterface` | `{type: "string", format: "date-time"}` | string ⇄ `\DateTime(Immutable)` (ISO-8601) |
| `Mcp\Capability\Discovery\PropertyDescriber\UuidPropertyDescriber` | `Symfony\Component\Uid\Uuid` (and subclasses) | `{type: "string", format: "uuid"}` | string ⇄ `Uuid` (RFC 4122) |

Register them — and your own — on the builder:

```php
use Mcp\Capability\Discovery\PropertyDescriber\DateTimePropertyDescriber;
use Mcp\Capability\Discovery\PropertyDescriber\UuidPropertyDescriber;

$server = Server::builder()
    ->setServerInfo('my-server', '1.0.0')
    ->addPropertyDescriber(new DateTimePropertyDescriber())
    ->addPropertyDescriber(new UuidPropertyDescriber())
    ->build();
```

With these registered, a tool like:

```php
public function getTownShopList(Uuid $id): \DateTimeImmutable
```

generates `{type: "string", format: "uuid"}` for `$id`, upcasts the client's `"id"` string into a real `Uuid` before the
method is called, and normalizes the returned `\DateTimeImmutable` to an ISO-8601 string in the result content. Docblock
descriptions, defaults and nullability are still layered on top of the describer's schema fragment for input parameters.

**Schema vs. value — and the object rule.** A describer fragment is used directly as a tool's `outputSchema` **only when
it is an `object` schema**, because per the MCP spec an `outputSchema` describes the object-typed `structuredContent`.
A scalar fragment (uuid/date-time strings) is therefore *not* advertised as an output schema; such a return is
normalized to a string and carried in the result's text `content` instead. This is what makes the **DTO** case the
primary use of output schemas: a handler whose `describe()` returns `{type: "object", properties: {...}}` for your DTO
class gets that emitted as the tool's `outputSchema`, while its `normalize()` produces the matching
`structuredContent`. Note that normalization is applied to the **top-level** returned value; values nested inside a DTO
are the registered DTO handler's responsibility (e.g. delegated to a serializer — see below).

Writing a custom handler for a domain value object — implement only the directions you need:

```php
use Mcp\Capability\Discovery\PropertyDescriberInterface;
use Mcp\Capability\Discovery\PropertyDenormalizerInterface;
use Mcp\Capability\Discovery\PropertyNormalizerInterface;

final class MoneyPropertyHandler implements PropertyDescriberInterface, PropertyDenormalizerInterface, PropertyNormalizerInterface
{
    public static function supportedClass(): string
    {
        return \App\Money::class;
    }

    public function describe(): array
    {
        return ['type' => 'string', 'pattern' => '^\d+(\.\d{2})? [A-Z]{3}$'];
    }

    public function denormalize(mixed $value, string $class): \App\Money
    {
        return \App\Money::fromString((string) $value);
    }

    public function normalize(object $value): string
    {
        return (string) $value;
    }
}

$builder->addPropertyDescriber(new MoneyPropertyHandler());
```

#### Delegating whole DTOs to a serializer

Because `describe()` may return any schema fragment and `denormalize()`/`normalize()` receive the concrete class, a
single handler registered against a DTO base class (or marker interface) can cover **all** your DTOs by delegating to a
serializer you already use — e.g. `symfony/serializer` — instead of the SDK reflecting your objects:

```php
use Mcp\Capability\Discovery\PropertyDenormalizerInterface;
use Mcp\Capability\Discovery\PropertyNormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class SerializerDtoHandler implements PropertyDenormalizerInterface, PropertyNormalizerInterface
{
    public function __construct(private NormalizerInterface&DenormalizerInterface $serializer)
    {
    }

    public static function supportedClass(): string
    {
        return \App\Dto\AbstractDto::class;
    }

    public function denormalize(mixed $value, string $class): object
    {
        return $this->serializer->denormalize($value, $class);
    }

    public function normalize(object $value): mixed
    {
        return $this->serializer->normalize($value);
    }
}
```

(For the output **schema** of such DTOs, also implement `PropertyDescriberInterface` and return the nested schema —
assembled however you like, e.g. via `symfony/property-info` or `api-platform/json-schema`. The SDK itself does not
reflect class properties.)

To override a shipped handler, register your own for the same class **before** it — the first match wins. Note that
`addPropertyDescriber()` cannot be combined with `setSchemaGenerator()` (configure describers on your own generator
instead) nor with `setReferenceHandler()` (wire the handlers onto your own reference handler instead).

## Discovery vs Manual Registration

### Attribute-Based Discovery

**Advantages:**
- Declarative and readable
- Automatic parameter inference
- DocBlock integration
- Type-safe by default
- Caching support

**Example:**
```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])  // Automatic discovery
    ->build();
```

### Manual Registration

**Advantages:**
- Fine-grained control
- Runtime configuration
- Conditional registration
- External handler support

**Example:**
```php
$server = Server::builder()
    ->addTool([Calculator::class, 'add'], 'add_numbers')
    ->addResource([Config::class, 'get'], 'config://app')
    ->addPrompt([Prompts::class, 'email'], 'write_email')
    ->build();
```

For detailed information on manual registration, see [Server Builder](server-builder.md#manual-capability-registration).

### Hybrid Approach

Combine both methods for maximum flexibility:

```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])  // Discover most capabilities
    ->addTool([ExternalService::class, 'process'], 'external')  // Add specific ones
    ->build();
```

Manual registrations always take precedence over discovered elements with the same identifier.
