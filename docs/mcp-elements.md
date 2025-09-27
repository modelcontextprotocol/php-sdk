# MCP Elements

MCP elements are the core capabilities of your server: Tools, Resources, Resource Templates, and Prompts. These elements define what your server can do and how clients can interact with it. The PHP MCP SDK provides both attribute-based discovery and manual registration methods.

## Table of Contents

- [Overview](#overview)
- [Tools](#tools)
- [Resources](#resources)
- [Resource Templates](#resource-templates)
- [Prompts](#prompts)
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

1. **Attribute-Based Discovery**: Use PHP attributes (`#[McpTool]`, `#[McpResource]`, etc.) on methods or classes. The server automatically discovers and registers them.

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

Tools can throw exceptions which are automatically converted to proper JSON-RPC error responses:

```php
#[McpTool]
public function divideNumbers(float $a, float $b): float
{
    if ($b === 0.0) {
        throw new \InvalidArgumentException('Division by zero is not allowed');
    }
    
    return $a / $b;
}

#[McpTool]
public function processFile(string $filename): string
{
    if (!file_exists($filename)) {
        throw new \InvalidArgumentException("File not found: {$filename}");
    }
    
    return file_get_contents($filename);
}
```

The SDK will convert these exceptions into appropriate JSON-RPC error responses that MCP clients can understand.

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

Resource handlers can throw exceptions for error cases:

```php
#[McpResource(uri: 'file://{path}')]
public function getFile(string $path): string
{
    if (!file_exists($path)) {
        throw new \InvalidArgumentException("File not found: {$path}");
    }
    
    if (!is_readable($path)) {
        throw new \RuntimeException("File not readable: {$path}");
    }
    
    return file_get_contents($path);
}
```

## Resource Templates

Resource templates are **dynamic resources** that use parameterized URIs with variables. They follow all the same rules as static resources (URI schemas, return values, MIME types, etc.) but accept variables using [RFC 6570 URI template syntax](https://datatracker.ietf.org/doc/html/rfc6570).

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

#### Valid Message Roles

- **`user`**: User input or questions  
- **`assistant`**: Assistant responses/system 

#### Error Handling

Prompt handlers can throw exceptions for invalid inputs:

```php
#[McpPrompt]
public function generatePrompt(string $topic, string $style): array
{
    $validStyles = ['casual', 'formal', 'technical'];
    
    if (!in_array($style, $validStyles)) {
        throw new \InvalidArgumentException(
            "Invalid style '{$style}'. Must be one of: " . implode(', ', $validStyles)
        );
    }
    
    return [
        ['role' => 'user', 'content' => "Write about {$topic} in a {$style} style"]
    ];
}
```

The SDK automatically validates that all messages have valid roles and converts the result into the appropriate MCP prompt message format.

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
> Completion providers only offer **suggestions** to users. Users can still input any value, so **always validate parameters** in your handlers. Providers don't enforce validation - they're purely for UX improvement.

## Schema Generation and Validation

The SDK automatically generates JSON schemas for **tool parameters** using a sophisticated priority system. Schema generation applies to both attribute-discovered and manually registered tools.

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

**Warning:** Only use complete schema override if you're well-versed with JSON Schema specification and have complex validation requirements that cannot be achieved through the priority system.

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