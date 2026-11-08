# Registering elements

Every tool, resource, resource template, and prompt has to reach the server's
registry somehow. There are three ways to get it there, and they mix freely: attribute
discovery, manual registration with `addTool()` and its siblings, and explicit element
registration with `Builder::add()`.

## Attribute-Based Discovery

**Advantages:**

- Declarative and readable
- Automatic parameter inference
- DocBlock integration
- Type-safe by default
- Caching support

**Example:**
```php
use Mcp\Server\Server;

$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])  // Automatic discovery
    ->build();
```

!!! warning
    Discovery registers only the first class in a file and ignores the others. Put each class with MCP attributes in
    its own file.

## Manual Registration

Register MCP elements programmatically without using attributes. The handler is the most important parameter. See
[Handler Types](#handler-types) for the accepted forms.

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

**Handler** can be one of the following forms. Other callables, such as a function name, a `'Class::method'` string or
an invokable object, fail when the elements load:

1. **Closure**: `function(int $a, int $b): int { return $a + $b; }`, or a first-class callable such as `$service->add(...)`
2. **Class and method name pair**: `[ClassName::class, 'methodName']` - the class is instantiated lazily on first call, so it must be constructable through the container (or have a no-arg constructor)
3. **Class instance and method name**: `[$instance, 'methodName']` - the given, already-constructed object is invoked as-is. Use this for handlers the container cannot build, e.g. those with scalar constructor arguments or dependencies wired at runtime
4. **Invokable class name**: `InvokableClass::class` - class must be constructable through the container and have `__invoke` method

!!! note
    A custom loader implements `Mcp\Server\Capability\Registry\Loader\LoaderInterface` and is added with `Builder::addLoader()`. It can register an element with the id of a callable service from your container as the handler (e.g. `app.weather_handler`).

    `addTool()`, `addResource()`, `addResourceTemplate()` and `addPrompt()` don't accept service ids. A bad handler fails when the elements load, by default on the first registry read. The SDK logs a `ConfigurationException` and answers that request with `-32603` `Internal server error.`. With `setLazyLoading(false)`, `build()` throws the exception.

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
    )
    ->build();
```

#### Parameters

- `handler` (\Closure|array|string): The tool handler
- `name` (string|null): Optional tool name
- `title` (string|null): Optional human-readable title for display in UI
- `description` (string|null): Optional tool description
- `annotations` (ToolAnnotations|null): Optional annotations for the tool
- `inputSchema` (array|null): Optional input schema for the tool
- `icons` (Icon[]|null): Optional array of icons for the tool
- `meta` (array|null): Optional metadata for the tool
- `outputSchema` (array|null): Optional JSON schema describing the tool's `structuredContent`

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
    )
    ->build();
```

#### Parameters

- `handler` (\Closure|array|string): The resource handler
- `uri` (string): The resource URI
- `name` (string|null): Optional resource name
- `title` (string|null): Optional human-readable title for display in UI
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
    )
    ->build();
```

#### Parameters

- `handler` (\Closure|array|string): The resource template handler
- `uriTemplate` (string): The resource URI template
- `name` (string|null): Optional resource template name
- `title` (string|null): Optional human-readable title for display in UI
- `description` (string|null): Optional resource template description
- `mimeType` (string|null): Optional MIME type of the resource
- `annotations` (Annotations|null): Optional annotations for the resource template
- `meta` (array|null): Optional metadata for the resource template

### Manual Prompt Registration

Register prompt generators:

```php
$server = Server::builder()
    ->addPrompt(
        handler: [PromptService::class, 'generatePrompt'],
        name: 'custom_prompt',
        description: 'A custom prompt generator'
    )
    ->build();
```

#### Parameters

- `handler` (\Closure|array|string): The prompt handler
- `name` (string|null): Optional prompt name
- `title` (string|null): Optional human-readable title for display in UI
- `description` (string|null): Optional prompt description
- `icons` (Icon[]|null): Optional array of icons for the prompt
- `meta` (array|null): Optional metadata for the prompt

**Note:** `name` and `description` are optional when the handler is a method or an invokable class. The name defaults to
the method name, or to the class short name for an invokable class. The description comes from the docblock.

A **closure** handler gets a generated name (`closure_tool_<id>`, `closure_resource_<id>`, `closure_template_<id>` or
`closure_prompt_<id>`) and no description. Name your closures explicitly.

For more details on the elements themselves, see [Tools](tools.md), [Resources](resources.md), [Resource templates](resource-templates.md), and [Prompts](prompts.md).

## Explicit element registration

When an element's name, schema, or description is only known at runtime, pair an `Mcp\Schema\*` value object with one of
the four handler interfaces below and register it through `Builder::add()`.

| Element kind      | Handler interface                                     |
|-------------------|-------------------------------------------------------|
| Tool              | `Mcp\Server\Handler\ToolHandlerInterface`             |
| Resource          | `Mcp\Server\Handler\ResourceHandlerInterface`         |
| Resource template | `Mcp\Server\Handler\ResourceTemplateHandlerInterface` |
| Prompt            | `Mcp\Server\Handler\PromptHandlerInterface`           |

Each handler interface declares a single execution method, and each method receives a `ClientGateway` as its last
argument. Before it, tool and prompt handlers receive an arguments map. Resource handlers receive the requested URI, and
resource template handlers receive the URI and the parsed template variables.

```php
use Mcp\Schema\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;
use Mcp\Server\Server;

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
`PromptHandlerInterface` raises `Mcp\Server\Exception\InvalidArgumentException`.

The schema value objects validate some of their own input as well. `Tool` requires an object-typed input schema.
`ResourceDefinition` and `ResourceTemplate` check the URI.

An invalid tool name is not rejected. The registry only logs a warning when you register the tool.

For `Prompt` and `ResourceTemplate` definitions, `add()` takes completion providers as a third argument. See
[Registering providers without discovery](completions.md#registering-providers-without-discovery).

Use `add()` when the metadata cannot be inferred from a handler class via reflection. For statically-known elements,
prefer `addTool/addResource/addResourceTemplate/addPrompt`, which can derive metadata from the handler's signature and
docblock.

## Element sources

Your tools, resources or prompts may come from a database or another service. An element source reads them and
returns them as a collection:

```php
use Mcp\Server\Capability\Registry\Source\ElementCollection;
use Mcp\Server\Capability\Registry\Source\ElementSourceInterface;
use Mcp\Server\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;

final class ProductToolSource implements ElementSourceInterface
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function provide(): ElementCollection
    {
        $tools = [];
        foreach ($this->products->findPublished() as $product) {
            $tool = new Tool('product_'.$product->id, $product->title, $product->inputSchema, $product->description, null);
            $tools[] = new ToolReference($tool, [ProductTools::class, 'run']);
        }

        return new ElementCollection(tools: $tools);
    }
}
```

Register the source on the builder:

```php
$server = Server::builder()
    ->setServerInfo('Product Server', '1.0.0')
    ->addSource(new ProductToolSource($products))
    ->build();
```

The server calls `provide()` when it loads the elements.

An element from a source replaces one with the same identifier from a custom loader. Elements registered on the
builder replace those of a source, and discovery never replaces an existing element.

If a source throws an exception, the server logs it as an error and serves the other elements. The server calls the
source again on each read until it succeeds. Until then, it doesn't call the sources that already succeeded. The registry
doesn't announce elements that arrive on a later attempt, so clients see them the next time they list.

While a source fails, the server advertises tools, resources and prompts, because it can't know what the source provides.

A custom loader can write elements before it fails. While a custom loader fails, the server also runs everything that
comes after it on each read, including the sources.

A `ConfigurationException` stops the load instead, because it reports a mistake in your setup, e.g. a
handler class that doesn't exist. The request that triggered the load fails with `-32603`.

## Hybrid Approach

Combine discovery with manual registration for maximum flexibility:

```php
$server = Server::builder()
    ->setDiscovery(__DIR__, ['.'], excludeDirs: ['vendor'])  // Discover most capabilities
    ->addTool([ExternalService::class, 'process'], 'external')  // Add specific ones
    ->build();
```

Manual registrations and explicit elements always take precedence over discovered elements with the same identifier:
the same `name` for tools and prompts, the same `uri` for resources, the same `uriTemplate` for resource templates.

For runtime, config-driven elements whose shape is not known at compile time, see
[Explicit element registration](#explicit-element-registration).
