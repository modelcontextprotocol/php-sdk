# Registering elements

Every tool, resource, resource template, and prompt has to reach the server's
registry somehow. There are three ways to get it there, and they mix freely.

## Attribute-Based Discovery

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

## Manual Registration

Register MCP elements programmatically without using attributes. The handler is the most important parameter and can be
any PHP callable.

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


### Handler Types

**Handler** can be any PHP callable:

1. **Closure**: `function(int $a, int $b): int { return $a + $b; }`
2. **Class and method name pair**: `[ClassName::class, 'methodName']` - the class is instantiated lazily on first call, so it must be constructable through the container (or have a no-arg constructor)
3. **Class instance and method name**: `[$instance, 'methodName']` - the given, already-constructed object is invoked as-is. Use this for handlers the container cannot build, e.g. those with scalar constructor arguments or dependencies wired at runtime
4. **Invokable class name**: `InvokableClass::class` - class must be constructable through the container and have `__invoke` method

### Manual Tool Registration

```php
$server = Server::builder()
    // Using closure
    ->addTool(
        handler: function(int $a, int $b): int { return $a + $b; },
        name: 'add_numbers',
        description: 'Adds two numbers together'
    )

    // Using class method pair
    ->addTool(
        handler: [Calculator::class, 'multiply'],
        name: 'multiply_numbers'
        // name and description are optional - derived from method name and docblock
    )

    // Using instance method
    ->addTool(
        handler: [$calculatorInstance, 'divide']
    )

    // Using invokable class
    ->addTool(
        handler: InvokableCalculator::class
    );
```

#### Parameters

- `handler` (callable|string): The tool handler
- `name` (string|null): Optional tool name
- `title` (string|null): Optional human-readable title for display in UI
- `description` (string|null): Optional tool description
- `annotations` (ToolAnnotations|null): Optional annotations for the tool
- `inputSchema` (array|null): Optional input schema for the tool
- `icons` (Icon[]|null): Optional array of icons for the tool
- `meta` (array|null): Optional metadata for the tool

### Manual Resource Registration

Register static resources:

```php
$server = Server::builder()
    ->addResource(
        handler: [Config::class, 'getSettings'],
        uri: 'config://app/settings',
        name: 'app_config',
        description: 'Application configuration',
        mimeType: 'application/json'
    );
```

#### Parameters

- `handler` (callable|string): The resource handler
- `uri` (string): The resource URI
- `name` (string|null): Optional resource name
- `description` (string|null): Optional resource description
- `mimeType` (string|null): Optional MIME type of the resource
- `size` (int|null): Optional size of the resource in bytes
- `annotations` (Annotations|null): Optional annotations for the resource
- `icons` (Icon[]|null): Optional array of icons for the resource
- `meta` (array|null): Optional metadata for the resource

### Manual Resource Template Registration

Register dynamic resources with URI templates:

```php
$server = Server::builder()
    ->addResourceTemplate(
        handler: [UserService::class, 'getUserProfile'],
        uriTemplate: 'user://{userId}/profile',
        name: 'user_profile',
        description: 'User profile by ID',
        mimeType: 'application/json'
    );
```

#### Parameters

- `handler` (callable|string): The resource template handler
- `uriTemplate` (string): The resource URI template
- `name` (string|null): Optional resource template name
- `description` (string|null): Optional resource template description
- `mimeType` (string|null): Optional MIME type of the resource
- `annotations` (Annotations|null): Optional annotations for the resource template

### Manual Prompt Registration

Register prompt generators:

```php
$server = Server::builder()
    ->addPrompt(
        handler: [PromptService::class, 'generatePrompt'],
        name: 'custom_prompt',
        description: 'A custom prompt generator'
    );
```

#### Parameters

- `handler` (callable|string): The prompt handler
- `name` (string|null): Optional prompt name
- `title` (string|null): Optional human-readable title for display in UI
- `description` (string|null): Optional prompt description
- `icons` (Icon[]|null): Optional array of icons for the prompt

**Note:** `name` and `description` are optional when the handler is a method or an invokable class — they are then
derived from the method name and its docblock. A **closure** handler has neither, so it gets a generated name
(`closure_tool_<id>`) and no description; name your closures explicitly.

For more details on the elements themselves, see [Tools](tools.md), [Resources](resources.md), [Resource templates](resource-templates.md), and [Prompts](prompts.md).

### Explicit element registration

When an element's name, schema, or description is only known at runtime, pair an `Mcp\Schema\*` value object with one of
the four handler interfaces below and register it through `Builder::add()`.

| Element kind      | Handler interface                                     |
|-------------------|-------------------------------------------------------|
| Tool              | `Mcp\Server\Handler\ToolHandlerInterface`             |
| Resource          | `Mcp\Server\Handler\ResourceHandlerInterface`         |
| Resource template | `Mcp\Server\Handler\ResourceTemplateHandlerInterface` |
| Prompt            | `Mcp\Server\Handler\PromptHandlerInterface`           |

Each handler interface declares a single execution method. Tool and prompt handlers receive an arguments map and a
`ClientGateway`. Resource handlers receive the requested URI; resource template handlers additionally receive the parsed
template variables.

```php
use Mcp\Schema\Tool;
use Mcp\Server;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

final class WeatherHandler implements ToolHandlerInterface
{
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return ['temperature' => 21, 'unit' => 'C'];
    }
}

$tool = new Tool(
    name: 'get_weather',
    title: null,
    inputSchema: [
        'type' => 'object',
        'properties' => ['city' => ['type' => 'string']],
        'required' => ['city'],
    ],
    description: 'Returns the current weather for a city.',
    annotations: null,
);

$server = Server::builder()
    ->add($tool, new WeatherHandler())
    ->build();
```

`Builder::add()` validates the pairing at registration time. Pairing a `Tool` definition with, for example, a
`PromptHandlerInterface` raises `Mcp\Exception\InvalidArgumentException`. The schema value objects validate some of
their own input as well — `Tool` requires an object-typed input schema, `ResourceDefinition` and `ResourceTemplate`
check the name pattern and URI — but an invalid tool or prompt *name* is not rejected, it is only logged as a warning
when the element is registered.

Use `add()` when the metadata cannot be inferred from a handler class via reflection. For statically-known elements,
prefer `addTool/addResource/addResourceTemplate/addPrompt`, which can derive metadata from the handler's signature and
docblock.

## Hybrid Approach

Combine both methods for maximum flexibility:

```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'])  // Discover most capabilities
    ->addTool([ExternalService::class, 'process'], 'external')  // Add specific ones
    ->build();
```

Manual registrations always take precedence over discovered elements with the same identifier — same `name` for tools
and prompts, same `uri` for resources, same `uriTemplate` for resource templates.

For runtime, config-driven elements whose shape is not known at compile time, see
[Explicit element registration](#explicit-element-registration).
