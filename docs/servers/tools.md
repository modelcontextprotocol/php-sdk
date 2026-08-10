# Tools

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

## Parameters

- **`name`** (optional): Tool identifier. Defaults to method name if not provided.
- **`title`** (optional): Human-readable display title shown in client UI. Distinct from `name`.
- **`description`** (optional): Tool description. Falls back to the docblock (summary plus long description); stays unset if there is no docblock.
- **`annotations`** (optional): `ToolAnnotations` object for additional metadata.
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
    return EmbeddedResource::fromText('file://data.json', 'File content');

    // …or build the resource contents yourself:
    // return new EmbeddedResource(
    //     new TextResourceContents('file://data.json', 'application/json', '{}')
    // );
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

### Error Handling

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
