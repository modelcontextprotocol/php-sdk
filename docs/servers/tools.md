# Tools

Tools are callable functions that perform actions and return results.

```php
use Mcp\Server\Capability\Attribute\McpTool;

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

## Parameters

- **`name`** (optional): Tool identifier. Defaults to method name if not provided.
- **`title`** (optional): Human-readable display title shown in client UI. Distinct from `name`.
- **`description`** (optional): Tool description. Falls back to the docblock (summary plus long description); stays unset if there is no docblock.
- **`annotations`** (optional): `ToolAnnotations` object for additional metadata.
- **`outputSchema`** (optional): JSON schema describing `structuredContent`; see [Structured Output](#structured-output).
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

**Priority**: `name` is the attribute parameter, else the method name. `description` is the attribute parameter, else the docblock — the method name is never used as a description.

For tool parameter validation and JSON schema generation, see [Schema generation](schemas.md).

## Tool Return Values

Tools can return any data type and the SDK will automatically wrap them in appropriate MCP content types.

### Automatic Content Wrapping

```php
// Primitive types → TextContent
public function getString(): string { return "Hello"; }           // TextContent
public function getNumber(): int { return 42; }                  // TextContent  
public function getBool(): bool { return true; }                 // TextContent
public function getArray(): array { return ['key' => 'value']; } // TextContent (JSON)

// Special cases
public function getNull(): ?string { return null; }              // TextContent("(null)")
public function returnVoid(): void { /* no return */ }           // TextContent("(null)")
```

### Explicit Content Types

For fine control over output formatting:

```php
use Mcp\Schema\Content\{TextContent, ImageContent, AudioContent, ResourceLink, EmbeddedResource};

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
    return EmbeddedResource::fromText('file://data.json', 'File content');

    // …or build the resource contents yourself:
    // return new EmbeddedResource(
    //     new TextResourceContents('file://data.json', 'application/json', '{}')
    // );
}

public function getResourceLink(): ResourceLink
{
    // Reference a resource by URI without embedding its contents, e.g. when
    // a tool result would otherwise need to inline many or large resources.
    return new ResourceLink(
        uri: 'file://data.json',
        name: 'data.json',
        mimeType: 'application/json'
    );
}
```

### Multiple Content Items

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

### Structured Output

Besides the human-readable `content`, a tool result can carry a machine-readable `structuredContent` value. Declare its
shape with `outputSchema`, a JSON Schema of type `object`:

```php
#[McpTool(
    name: 'get_weather',
    outputSchema: [
        'type' => 'object',
        'properties' => [
            'temperature' => ['type' => 'number'],
            'conditions' => ['type' => 'string'],
        ],
        'required' => ['temperature', 'conditions'],
    ]
)]
public function getWeather(string $city): array
{
    // Sent as `structuredContent`, and JSON-encoded into `content` for clients that ignore it
    return ['temperature' => 22.5, 'conditions' => 'sunny'];
}
```

The same schema can be passed to manual registration:

```php
$builder->addTool([WeatherHandler::class, 'getWeather'], outputSchema: [/* ... */]);
```

The SDK fills `structuredContent` whenever the return value qualifies — `outputSchema` is what tells clients to expect it
and lets them validate it. What qualifies depends on the protocol revision the call is served under:

| Return value | `structuredContent` |
|---|---|
| Associative array (`['temperature' => 22.5]`) | The array |
| Object (`stdClass`, DTO, `JsonSerializable`) that serializes to a JSON object | Its JSON representation |
| List (`[1, 2, 3]`, `[['id' => 1], ['id' => 2]]`), or an object serializing to one | Omitted before `2026-07-28`, kept from it on |
| Array holding `Content` instances | Omitted (already carried in `content`) |
| Scalars, `null`, `Content` instances | Omitted |

Up to revision `2025-11-25`, `structuredContent` had to be a JSON object, so a PHP list — which serializes to a JSON
array — was not emittable and strict clients rejected the whole tool call over one. [SEP-2106][sep-2106], part of
revision `2026-07-28`, widened `outputSchema` to any JSON Schema 2020-12 and `structuredContent` to any JSON value
conforming to it. The SDK picks the rule from the revision negotiated for the call, so a tool serving both eras needs the
object shape to produce structured output everywhere. Wrap the list in a key for that:

```php
// Structured content only from 2026-07-28 on: a bare list is not a JSON object
public function listUsersFlat(): array
{
    return [['id' => 1], ['id' => 2]];
}

#[McpTool(outputSchema: [
    'type' => 'object',
    'properties' => [
        'items' => ['type' => 'array', 'items' => ['type' => 'object']],
    ],
    'required' => ['items']
])]
public function listUsers(): array
{
    return ['items' => [['id' => 1], ['id' => 2]]];
}
```

Either way the data reaches the client: a return value with no structured representation is still JSON-encoded into
`content` as a `TextContent`. When a tool declares an `outputSchema` but returns something that cannot be sent as
`structuredContent`, the SDK logs a warning — the value is not silently dropped.

A tool that wants to branch on the revision itself can read it from the injected `RequestContext`, see
[Talking back to the client](../handlers/client-communication.md#clientgateway).

[sep-2106]: https://modelcontextprotocol.io/specification/2026-07-28/server/tools#structured-content

### Error Handling

Tool handlers can throw any exception, but the type determines how it's handled:

- **`ToolCallException`**: Converted to JSON-RPC response with `CallToolResult` where `isError: true`, allowing the LLM to see the error message and self-correct
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Server\Exception\ToolCallException;

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
