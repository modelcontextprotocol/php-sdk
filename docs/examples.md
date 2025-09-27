# Examples

The MCP PHP SDK includes comprehensive examples demonstrating different patterns and use cases. Each example showcases specific features and can be run independently to understand how the SDK works.

## Table of Contents

- [Getting Started](#getting-started)
- [Running Examples](#running-examples)
- [STDIO Examples](#stdio-examples)
- [HTTP Examples](#http-examples)
- [Advanced Patterns](#advanced-patterns)
- [Testing and Debugging](#testing-and-debugging)

## Getting Started

All examples are located in the `examples/` directory and use the SDK dependencies from the root project. Most examples can be run directly without additional setup.

### Prerequisites

```bash
# Install dependencies (from project root)
composer install

# Optional: Install HTTP-specific dependencies for web examples
composer install --dev
```

## Running Examples

### STDIO Examples

STDIO examples use standard input/output for communication:

```bash
# Interactive testing with MCP Inspector
npx @modelcontextprotocol/inspector php examples/01-discovery-stdio-calculator/server.php

# Run with debugging enabled
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/01-discovery-stdio-calculator/server.php

# Or configure the script path in your MCP client
# Path: php examples/01-discovery-stdio-calculator/server.php
```

### HTTP Examples

HTTP examples run as web servers:

```bash
# Start the server
php -S localhost:8000 examples/02-discovery-http-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Test with curl
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"test","version":"1.0.0"},"capabilities":{}}}'
```

## STDIO Examples

### 01. Discovery STDIO Calculator

**File**: `examples/01-discovery-stdio-calculator/`

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
npx @modelcontextprotocol/inspector php examples/01-discovery-stdio-calculator/server.php

# Or configure in MCP client: php examples/01-discovery-stdio-calculator/server.php
```

### 03. Manual Registration STDIO

**File**: `examples/03-manual-registration-stdio/`

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

### 05. STDIO Environment Variables

**File**: `examples/05-stdio-env-variables/`

**What it demonstrates:**
- Environment variable integration
- Server configuration from environment
- Environment-based tool behavior

**Key Features:**
- Reading environment variables within tools
- Conditional behavior based on environment
- Environment validation and defaults

### 06. Custom Dependencies STDIO

**File**: `examples/06-custom-dependencies-stdio/`

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

### 09. Cached Discovery STDIO

**File**: `examples/09-cached-discovery-stdio/`

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

## HTTP Examples

### 02. Discovery HTTP User Profile

**File**: `examples/02-discovery-http-userprofile/`

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
php -S localhost:8000 examples/02-discovery-http-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Or configure in MCP client: http://localhost:8000
```

### 04. Combined Registration HTTP

**File**: `examples/04-combined-registration-http/`

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

### 07. Complex Tool Schema HTTP

**File**: `examples/07-complex-tool-schema-http/`

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

### 08. Schema Showcase Streamable

**File**: `examples/08-schema-showcase-streamable/`

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
