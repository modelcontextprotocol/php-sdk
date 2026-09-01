# Prompts

Prompts generate templates for AI interactions.

```php
use Mcp\Server\Capability\Attribute\McpPrompt;

class PromptGenerator
{
    /**
     * Generates a code review request prompt.
     */
    #[McpPrompt(name: 'code_review')]
    public function reviewCode(string $language, string $code, string $focus = 'general'): array
    {
        return [
            ['role' => 'assistant', 'content' => 'You are an expert code reviewer.'],
            ['role' => 'user', 'content' => "Review this {$language} code focusing on {$focus}:\n\n```{$language}\n{$code}\n```"]
        ];
    }
}
```

## Parameters

- **`name`** (optional): Prompt identifier. Defaults to method name if not provided.
- **`title`** (optional): Human-readable display title shown in client UI. Distinct from `name`.
- **`description`** (optional): Prompt description. Falls back to the docblock (summary plus long description).
- **`icons`** (optional): Array of `Icon` objects for visual representation.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

## Prompt Return Values

Prompt handlers must return an array of message structures that are automatically formatted into MCP prompt messages.

### Supported Return Formats

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

// Non-text content — each message carries exactly one content block,
// so an image goes into its own message
use Mcp\Schema\Content\{TextContent, ImageContent};

public function mixedContent(): array
{
    return [
        ['role' => 'user', 'content' => new TextContent('Analyze this image:')],
        ['role' => 'user', 'content' => new ImageContent(data: $imageData, mimeType: 'image/png')]
    ];
}

// Using explicit PromptMessage objects
use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Enum\Role;

public function explicitMessages(): array
{
    return [
        new PromptMessage(Role::Assistant, new TextContent('System instructions')),
        new PromptMessage(Role::User, new TextContent('User question'))
    ];
}
```

The SDK automatically validates that all messages have valid roles and converts the result into the appropriate MCP prompt message format.

### Valid Message Roles

- **`user`**: User input or questions
- **`assistant`**: Assistant responses, including system-style instructions

Those two are the only valid roles — MCP has no `system` role, and any other value
makes the prompt handler throw.

### Error Handling

Prompt handlers can throw any exception, but the type determines how it's handled:
- **`PromptGetException`**: Converted to JSON-RPC error response with the actual exception message
- **Any other exception**: Converted to JSON-RPC error response, but with a generic error message

```php
use Mcp\Server\Exception\PromptGetException;

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
