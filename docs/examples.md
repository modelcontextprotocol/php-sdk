# Examples

The MCP PHP SDK includes comprehensive examples demonstrating different patterns and use cases. Each example showcases
specific features and can be run independently to understand how the SDK works.

## Table of Contents

- [Getting Started](#getting-started)
- [Running Examples](#running-examples)
- [Examples](#examples)

## Getting Started

All examples are located in the `examples/` directory and use the SDK dependencies from the root project. Most examples
can be run directly without additional setup.

### Prerequisites

```bash
# Install dependencies (in project root)
composer install
```

## Running Examples

The bootstrapping of the example will choose the used transport based on the SAPI you use.

### STDIO Transport

The STDIO transport will use standard input/output for communication:

```bash
# Interactive testing with MCP Inspector
npx @modelcontextprotocol/inspector php examples/discovery-calculator/server.php

# Run with debugging enabled
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/discovery-calculator/server.php

# Or configure the script path in your MCP client
# Path: php examples/discovery-calculator/server.php
```

### HTTP Transport

The Streamable HTTP transport will be chosen if running examples with a web servers:

```bash
# Start the server
php -S localhost:8000 examples/discovery-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Test with curl
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"test","version":"1.0.0"},"capabilities":{}}}'
```

## Examples

### Discovery Calculator

**File**: `examples/discovery-calculator/`

**What it demonstrates:**
- Attribute-based discovery using `#[McpTool]` and `#[McpResource]`
- Basic arithmetic operations
- Configuration management through resources
- State management between tool calls

**Key Features:**
```php
#[McpTool(name: 'calculate')]
public function calculate(float $a, float $b, string $operation): float|string

#[McpResource(
    uri: 'config://calculator/settings',
    name: 'calculator_config',
    mimeType: 'application/json'
)]
public function getConfiguration(): array
```

**Usage:**
```bash
# Interactive testing
npx @modelcontextprotocol/inspector php examples/discovery-calculator/server.php

# Or configure in MCP client: php examples/discovery-calculator/server.php
```

### Explicit Registration

**File**: `examples/explicit-registration/`

**What it demonstrates:**
- Manual registration of tools, resources, and prompts
- Alternative to attribute-based discovery
- Simple handler functions

**Key Features:**
```php
$server = Server::builder()
    ->addTool([SimpleHandlers::class, 'echoText'], 'echo_text')
    ->addResource([SimpleHandlers::class, 'getAppVersion'], 'app://version')
    ->addPrompt([SimpleHandlers::class, 'greetingPrompt'], 'personalized_greeting')
```

### Environment Variables

**File**: `examples/env-variables/`

**What it demonstrates:**
- Environment variable integration
- Server configuration from environment
- Environment-based tool behavior

**Key Features:**
- Reading environment variables within tools
- Conditional behavior based on environment
- Environment validation and defaults

### Custom Dependencies

**File**: `examples/custom-dependencies/`

**What it demonstrates:**
- Dependency injection with PSR-11 containers
- Service layer architecture
- Repository pattern implementation
- Complex business logic integration

**Key Features:**
```php
$container->set(TaskRepositoryInterface::class, $taskRepo);
$container->set(StatsServiceInterface::class, $statsService);

$server = Server::builder()
    ->setContainer($container)
    ->setDiscovery(__DIR__, ['.'])
```

### Cached Discovery

**File**: `examples/cached-discovery/`

**What it demonstrates:**
- Discovery caching for improved performance
- PSR-16 cache integration
- Cache invalidation strategies

**Key Features:**
```php
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

$cache = new Psr16Cache(new FilesystemAdapter('mcp-discovery'));

$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'], [], $cache)
```

### Client Communication

**File**: `examples/client-communication/`

**What it demonstrates:**
- Server initiated communication back to the client
- Logging, sampling, progress and notifications
- Using `ClientGateway` in tool method via method argument injection of `RequestContext`

### Discovery User Profile

**File**: `examples/discovery-userprofile/`

**What it demonstrates:**
- HTTP transport with StreamableHttpTransport
- Resource templates with URI parameters
- Completion providers for parameter hints
- User profile management system
- Session persistence with FileSessionStore

**Key Features:**
```php
#[McpResourceTemplate(
    uriTemplate: 'user://{userId}/profile',
    name: 'user_profile',
    mimeType: 'application/json'
)]
public function getUserProfile(
    #[CompletionProvider(values: ['101', '102', '103'])]
    string $userId
): array

#[McpPrompt(name: 'generate_bio_prompt')]
public function generateBio(string $userId, string $tone = 'professional'): array
```

**Usage:**
```bash
# Start the HTTP server
php -S localhost:8000 examples/discovery-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Or configure in MCP client: http://localhost:8000
```

### Combined Registration

**File**: `examples/combined-registration/`

**What it demonstrates:**
- Mixing attribute discovery with manual registration
- HTTP server with both discovered and manual capabilities
- Flexible registration patterns

**Key Features:**
```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])  // Automatic discovery
    ->addTool([ManualHandlers::class, 'manualGreeter'])  // Manual registration
    ->addResource([ManualHandlers::class, 'getPriorityConfig'], 'config://priority')
```

### Complex Tool Schema

**File**: `examples/complex-tool-schema/`

**What it demonstrates:**
- Advanced JSON schema definitions
- Complex data structures and validation
- Event scheduling and management
- Enum types and nested objects

**Key Features:**
```php
#[Schema(definition: [
    'type' => 'object',
    'properties' => [
        'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'eventType' => ['type' => 'string', 'enum' => ['meeting', 'deadline', 'reminder']],
        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'urgent']]
    ]
])]
public function scheduleEvent(array $eventData): array
```

### Schema Showcase

**File**: `examples/schema-showcase/`

**What it demonstrates:**
- Comprehensive JSON schema features
- Parameter-level schema validation
- String constraints (minLength, maxLength, pattern)
- Numeric constraints (minimum, maximum, multipleOf)
- Array and object validation

**Key Features:**
```php
#[McpTool]
public function formatText(
    #[Schema(
        type: 'string',
        minLength: 5,
        maxLength: 100,
        pattern: '^[a-zA-Z0-9\s\.,!?\-]+$'
    )]
    string $text,
    
    #[Schema(enum: ['uppercase', 'lowercase', 'title', 'sentence'])]
    string $format = 'sentence'
): array
```

### Elicitation

**File**: `examples/server/elicitation/`

**What it demonstrates:**
- Server-to-client elicitation requests
- Interactive user input during tool execution
- Multi-field form schemas with validation
- Boolean confirmation dialogs
- Enum fields with human-readable labels
- Handling accept/decline/cancel responses
- Session persistence requirement for server-initiated requests

**Key Features:**
```php
// Check client support before eliciting
if (!$context->getClientGateway()->supportsElicitation()) {
    return ['status' => 'error', 'message' => 'Client does not support elicitation'];
}

// Build schema with multiple field types
$schema = new ElicitationSchema(
    properties: [
        'party_size' => new NumberSchemaDefinition(
            title: 'Party Size',
            integerOnly: true,
            minimum: 1,
            maximum: 20
        ),
        'date' => new StringSchemaDefinition(
            title: 'Reservation Date',
            format: 'date'
        ),
        'dietary' => new EnumSchemaDefinition(
            title: 'Dietary Restrictions',
            enum: ['none', 'vegetarian', 'vegan'],
            enumNames: ['None', 'Vegetarian', 'Vegan']
        ),
    ],
    required: ['party_size', 'date']
);

// Send elicitation request
$result = $client->elicit(
    message: 'Please provide your reservation details',
    requestedSchema: $schema
);

// Handle response
if ($result->isAccepted()) {
    $data = $result->content; // User-provided data
} elseif ($result->isDeclined() || $result->isCancelled()) {
    // User declined or cancelled
}
```

**Important Notes:**
- Elicitation requires a session store (e.g., `FileSessionStore`)
- Check client capabilities with `supportsElicitation()` before sending requests
- Schema supports primitive types: string, number/integer, boolean, enum
- String fields support format validation: date, date-time, email, uri
- Users can accept (providing data), decline, or cancel requests

**Usage:**
```bash
# Interactive testing with MCP client that supports elicitation
npx @modelcontextprotocol/inspector php examples/server/elicitation/server.php

# Test with Goose (confirmed working by reviewer)
# Or configure in Claude Desktop or other MCP clients
```

**Example Tools:**
1. **book_restaurant** - Multi-field reservation form with number, date, and enum fields
2. **confirm_action** - Simple boolean confirmation dialog
3. **collect_feedback** - Rating and comments form with optional fields
