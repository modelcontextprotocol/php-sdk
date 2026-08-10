# Resources

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

## Parameters

- **`uri`** (required): Unique resource identifier. Must comply with [RFC 3986](https://datatracker.ietf.org/doc/html/rfc3986).
- **`name`** (optional): Short resource identifier. Defaults to method name if not provided.
- **`title`** (optional): Human-readable display title shown in client UI. Distinct from `name`.
- **`description`** (optional): Resource description. Defaults to docblock summary if not provided.
- **`mimeType`** (optional): MIME type of the resource content.
- **`size`** (optional): Size in bytes if known.
- **`annotations`** (optional): Additional metadata.
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

**Standard Protocol URI Schemes**: `https://` (web resources), `file://` (filesystem), `git://` (version control).
**Custom schemes**: `config://`, `data://`, `db://`, `api://` or any RFC 3986 compliant scheme.

## Resource Return Values

Resource handlers can return various data types that are automatically formatted into appropriate MCP resource content types.

### Supported Return Types

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

// Stream resource - read and converted to blob.
// `resource` is not a PHP type declaration, so the return type is left off.
/** @return resource */
public function getImageStream()
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

### Error Handling

Resource handlers can throw any exception, but the type determines how it's handled:

- **`ResourceReadException`**: Converted to JSON-RPC error response with the actual exception message
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Exception\ResourceReadException;

// A URI with variables is a resource *template*; `#[McpResource]` registers a
// fixed URI and would never receive `$path`. Note a variable matches a single
// segment, so `$path` here cannot contain `/`.
#[McpResourceTemplate(uriTemplate: 'file://{path}')]
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
