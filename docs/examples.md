# Examples

The MCP PHP SDK includes comprehensive examples demonstrating different patterns and use cases. Each example showcases
specific features and can be run independently to understand how the SDK works.

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
npx @modelcontextprotocol/inspector php examples/server/discovery-calculator/server.php

# Run with debugging enabled
npx @modelcontextprotocol/inspector -e DEBUG=1 -e FILE_LOG=1 php examples/server/discovery-calculator/server.php

# Or configure the script path in your MCP client
# Path: php examples/server/discovery-calculator/server.php
```

### HTTP Transport

The Streamable HTTP transport will be chosen if running examples with a web servers:

```bash
# Start the server
php -S localhost:8000 examples/server/discovery-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Test with curl
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"test","version":"1.0.0"},"capabilities":{}}}'
```

## Server Examples

### Discovery Calculator

**File**: `examples/server/discovery-calculator/`

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
npx @modelcontextprotocol/inspector php examples/server/discovery-calculator/server.php

# Or configure in MCP client: php examples/server/discovery-calculator/server.php
```

### Explicit Registration

**File**: `examples/server/explicit-registration/`

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

**File**: `examples/server/env-variables/`

**What it demonstrates:**
- Environment variable integration
- Server configuration from environment
- Environment-based tool behavior

**Key Features:**
- Reading environment variables within tools
- Conditional behavior based on environment
- Environment validation and defaults

### Custom Dependencies

**File**: `examples/server/custom-dependencies/`

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

**File**: `examples/server/cached-discovery/`

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

**File**: `examples/server/client-communication/`

**What it demonstrates:**
- Server initiated communication back to the client
- Logging, sampling, progress and notifications
- Using `ClientGateway` in tool method via method argument injection of `RequestContext`
- Sampling and roots asked for the [multi round-trip](handlers/input-required.md) way, so the
  same tools serve a handshake-era and a `2026-07-28` client without naming either

### Discovery User Profile

**File**: `examples/server/discovery-userprofile/`

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
php -S localhost:8000 examples/server/discovery-userprofile/server.php

# Test with MCP Inspector
npx @modelcontextprotocol/inspector http://localhost:8000

# Or configure in MCP client: http://localhost:8000
```

### Combined Registration

**File**: `examples/server/combined-registration/`

**What it demonstrates:**
- Mixing attribute discovery with manual registration
- HTTP server with both discovered and manual capabilities
- All three handler styles: discovered, `[Class::class, 'method']`, and a pre-built `[$instance, 'method']`
- Pre-built instance handlers for classes the container cannot auto-wire (e.g. constructor scalars)

**Key Features:**
```php
// Built here so its constructor dependencies are injected before registration;
// the SDK invokes this very instance instead of constructing one itself.
$preconfiguredGreeter = new PreconfiguredGreeter('Willkommen', logger());

$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])                         // Automatic discovery
    ->addTool([ManualHandlers::class, 'manualGreeter'])    // Manual class-string handler
    ->addTool([$preconfiguredGreeter, 'greet'], 'instance_greeter')  // Pre-built instance handler
    ->addResource([ManualHandlers::class, 'getPriorityConfigManual'], 'config://priority')
```

### Complex Tool Schema

**File**: `examples/server/complex-tool-schema/`

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

**File**: `examples/server/schema-showcase/`

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
- Asking the user for input from a tool, written the
  [multi round-trip](handlers/input-required.md) way so one handler serves both protocol eras
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

// Return the ask, read the answer off the retry. The example wraps both halves
// in one helper, since every tool here does the same thing:
$result = $context->getInputContext()?->elicitResult('details')
    ?? new InputRequiredResult(['details' => new ElicitRequest($message, $schema)]);

// First round: hand the ask back to the client, which retries this whole call.
if ($result instanceof InputRequiredResult) {
    return $result;
}

// Handle response
if ($result->isAccepted()) {
    $data = $result->content; // User-provided data
} elseif ($result->isDeclined() || $result->isCancelled()) {
    // User declined or cancelled
}
```

**Important Notes:**
- The handler is re-entered from the top on the retry, so anything that must happen once
  belongs behind the "do I have the answer yet?" check
- A handshake-era client reaches the same tool: the SDK's input-required shim turns the ask
  into a real `elicitation/create` request over that connection
- Elicitation over a handshake-era connection requires a session store (e.g., `FileSessionStore`)
- Check client capabilities with `supportsElicitation()` before asking
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

### MCP Apps

**File**: `examples/server/mcp-apps/`

A weather app demonstrating the [MCP Apps extension](advanced/extensions.md): a `ui://`
HTML resource is opened by an MCP App-aware client (e.g. Goose) and bridged to
the `get_weather` tool. The bundled `weather-app.html` performs the
`ui/initialize` handshake, reports its size via `ui/notifications/size-changed`,
and calls back into the server. See the
[ext-apps repo](https://github.com/modelcontextprotocol/ext-apps) for the
TypeScript SDK and richer view-side patterns.

### The 2026-07-28 lifecycle

**File**: `examples/server/stateless-lifecycle/`

A server speaking protocol revision `2026-07-28`, which removed the `initialize` handshake and
protocol-level sessions. It is HTTP-only and cannot be driven by the Inspector, which opens with
`initialize`.

**What it demonstrates:**
- A tool answered in a single POST, with no handshake before it
- A [multi round-trip](handlers/input-required.md) tool that returns its ask and reads the answer
  off the retry
- Progress and log notifications travelling on the request's own response stream
- Cache hints on `server/discover` and the list methods

**Usage:**
```bash
php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php
```

Every request carries its own protocol version and client capabilities:

```bash
curl -sS http://127.0.0.1:8000/ \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: server/discover' \
  -d '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{
        "io.modelcontextprotocol/protocolVersion":"2026-07-28",
        "io.modelcontextprotocol/clientCapabilities":{}}}}'
```

`tests/Integration/StatelessLifecycleTest.php` drives this example end to end. See
[The 2026-07-28 lifecycle](protocol-versions.md) for the guide.

## Client Examples

### STDIO Discovery Calculator (Client)

**File**: `examples/client/stdio_discovery_calculator.php`

**What it demonstrates:**
- Basic MCP client usage with STDIO transport
- Connecting to a local MCP server process
- Listing and calling tools
- Reading resources

**Key Features:**
```php
$client = Client::builder()
    ->setClientInfo('STDIO Example Client', '1.0.0')
    ->setInitTimeout(30)
    ->setRequestTimeout(60)
    ->build();

$transport = new StdioTransport(
    command: 'php',
    args: [__DIR__.'/../server/discovery-calculator/server.php'],
);

$client->connect($transport);
$tools = $client->listTools();
$result = $client->callTool('calculate', ['a' => 5, 'b' => 3, 'operation' => 'add']);
$resourceContent = $client->readResource('config://calculator/settings');
```

**Usage:**
```bash
# Run the client (automatically starts the server)
php examples/client/stdio_discovery_calculator.php
```

### HTTP Discovery Calculator (Client)

**File**: `examples/client/http_discovery_calculator.php`

**What it demonstrates:**
- MCP client with HTTP transport
- Connecting to remote MCP servers
- Listing tools, resources, and prompts

**Key Features:**
```php
$transport = new HttpTransport('http://localhost:8000');
$client->connect($transport);

$tools = $client->listTools();
$resources = $client->listResources();
$prompts = $client->listPrompts();
```

**Usage:**
```bash
# Start the server first — the example picks its transport from the SAPI,
# so running it under a web server makes it speak Streamable HTTP
php -S localhost:8000 examples/server/discovery-calculator/server.php

# Then run the client
php examples/client/http_discovery_calculator.php
```

### STDIO Client Communication

**File**: `examples/client/stdio_client_communication.php`

**What it demonstrates:**
- Server-to-client communication (logging, progress, sampling)
- Handling logging notifications from server
- Implementing sampling callbacks for LLM requests
- Progress tracking during tool execution

**Key Features:**
```php
use Mcp\Client\Handler\Notification\LoggingNotificationHandler;
use Mcp\Client\Handler\Request\SamplingRequestHandler;
use Mcp\Client\Handler\Request\SamplingCallbackInterface;
use Mcp\Schema\ClientCapabilities;

$loggingHandler = new LoggingNotificationHandler(
    static function (LoggingMessageNotification $n) {
        echo "[LOG {$n->level->value}] {$n->data}\n";
    }
);

$samplingHandler = new SamplingRequestHandler(new class implements SamplingCallbackInterface {
    public function __invoke(CreateSamplingMessageRequest $request): CreateSamplingMessageResult
    {
        // Perform LLM sampling and return result
    }
});

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(sampling: true))
    ->addNotificationHandler($loggingHandler)
    ->addRequestHandler($samplingHandler)
    ->build();

// Call tool with progress tracking
$result = $client->callTool(
    name: 'run_dataset_quality_checks',
    arguments: ['dataset' => 'customer_orders_2024'],
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        $percent = $total > 0 ? round(($progress / $total) * 100) : '?';
        echo "[PROGRESS {$percent}%] {$message}\n";
    }
);
```

**Usage:**
```bash
# Run the client (automatically starts the communication server)
php examples/client/stdio_client_communication.php
```

### HTTP Client Communication

**File**: `examples/client/http_client_communication.php`

**What it demonstrates:**
- Server-to-client communication over HTTP
- Receiving logging and progress notifications via SSE streaming
- Implementing sampling for HTTP-based servers
- Progress tracking with long-running operations

**Key Features:**
- Same client-side code as STDIO version
- Uses HttpTransport instead of StdioTransport
- Demonstrates SSE-based real-time notifications
- Shows HTTP session management

**Usage:**
```bash
# Start the server
php -S 127.0.0.1:8000 examples/server/client-communication/server.php

# Run the client
php examples/client/http_client_communication.php
```

!!! note
    For sampling with HTTP transport, the server must support concurrent request processing (e.g., using Symfony CLI, PHP-FPM, or a production web server). PHP's built-in development server cannot handle the concurrent requests required for sampling.

### STDIO Elicitation

**File**: `examples/client/stdio_elicitation.php`

**What it demonstrates:**
- Answering server-initiated `elicitation/create` requests
- Prompting interactively on STDIN, one field per requested property
- Deriving a default per schema type, applied when the user just presses Enter
- Casting the entered string back to the declared type

Runs against the [Elicitation](#elicitation) server example, whose tools ask for
input mid-execution.

**Key Features:**
```php
use Mcp\Client\Handler\Request\ElicitationCallbackInterface;
use Mcp\Client\Handler\Request\ElicitationRequestHandler;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Enum\ElicitAction;
use Mcp\Schema\Request\ElicitRequest;
use Mcp\Schema\Result\ElicitResult;

$elicitationRequestHandler = new ElicitationRequestHandler(new class implements ElicitationCallbackInterface {
    public function __invoke(ElicitRequest $request): ElicitResult
    {
        echo "\n[ELICIT] {$request->message}\n";

        $content = [];
        foreach ($request->requestedSchema->properties as $name => $definition) {
            // defaultFor() and cast() below switch on the schema definition type
            $default = $this->defaultFor($definition);
            echo "  {$definition->title} [{$default}]: ";

            $input = trim(fgets(\STDIN) ?: '');
            $content[$name] = '' === $input ? $default : $this->cast($definition, $input);
        }

        return new ElicitResult(ElicitAction::Accept, $content);
    }
});

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($elicitationRequestHandler)
    ->build();
```

**Usage:**
```bash
# Run the client (automatically starts the elicitation server)
php examples/client/stdio_elicitation.php
```

The client calls `book_restaurant` and `confirm_action`, so it prompts for a
multi-field reservation form and then for a boolean confirmation.

### STDIO Roots

**File**: `examples/client/stdio_roots.php`

**What it demonstrates:**
- Advertising the `roots` capability during initialization
- Answering server `roots/list` requests with `file://` workspace folders
- Notifying the server when the list of roots changes

**Key Features:**
```php
use Mcp\Client\Handler\Request\ListRootsRequestHandler;
use Mcp\Client\Handler\Request\RootsCallbackInterface;
use Mcp\Schema\ClientCapabilities;
use Mcp\Schema\Result\ListRootsResult;
use Mcp\Schema\Root;

$rootsRequestHandler = new ListRootsRequestHandler(new class implements RootsCallbackInterface {
    public function __invoke(ListRootsRequest $request): ListRootsResult
    {
        echo "[ROOTS] Server requested the client's list of roots\n";

        return new ListRootsResult([
            new Root('file:///home/user/projects/app', 'Application'),
            new Root('file:///home/user/projects/library', 'Library'),
        ]);
    }
});

$client = Client::builder()
    ->setCapabilities(new ClientCapabilities(roots: true, rootsListChanged: true))
    ->addRequestHandler($rootsRequestHandler)
    ->build();

// The tool asks the client for its roots, which triggers the handler above
$result = $client->callTool(name: 'inspect_workspace_roots');

// Whenever the workspace folders change
$client->sendRootsListChanged();
```

**Usage:**
```bash
# Run the client (automatically starts the communication server)
php examples/client/stdio_roots.php
```

### Modern-era client

**File**: `examples/client/stateless_lifecycle_client.php`

**What it demonstrates:**
- Selecting protocol revision `2026-07-28` with a single `setProtocolVersion()` call
- A connection that sends no `initialize`, and asks `server/discover` only for the server's identity
- A [multi round-trip](handlers/input-required.md) call answered by the client, so the caller sees
  one call and one result

**Key Features:**
```php
$client = Client::builder()
    ->setClientInfo('stateless-example-client', '1.0.0')
    // The only line that selects the modern lifecycle.
    ->setProtocolVersion(ProtocolVersion::V2026_07_28)
    // Declared in the envelope of every request, so the server knows what it
    // may ask for before it decides how to answer.
    ->setCapabilities(new ClientCapabilities(elicitation: true))
    ->addRequestHandler($answerWithAName)
    ->build();

$client->connect(new HttpTransport('http://127.0.0.1:8000/'));

// One call from here. Two on the wire: the server returns its question, the
// handler above answers it, and the client retries carrying both the answer and
// the server's sealed `requestState`.
$client->callTool('greet', []);
```

Runs against the [2026-07-28 lifecycle](#the-2026-07-28-lifecycle) server example.

**Usage:**
```bash
# Start the matching server first
php -S 127.0.0.1:8000 examples/server/stateless-lifecycle/server.php

# Then run the client
php examples/client/stateless_lifecycle_client.php
```

See [Clients on this revision](protocol-versions.md) for what that one builder line changes.
