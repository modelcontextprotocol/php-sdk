# Completions

Completion providers help MCP clients offer auto-completion suggestions. The protocol defines completion for prompt arguments and resource template variables.

## Completion Provider Types

### 1. Value Lists

Provide a static list of possible values:

```php
use Mcp\Server\Capability\Attribute\CompletionProvider;
use Mcp\Server\Capability\Attribute\McpPrompt;

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

### 2. Enum Classes

Use enum values for completion:

```php
use Mcp\Server\Capability\Attribute\CompletionProvider;
use Mcp\Server\Capability\Attribute\McpResourceTemplate;

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

#[McpResourceTemplate(uriTemplate: 'tasks://{priority}/{status}')]
public function getTask(
    #[CompletionProvider(enum: Priority::class)]  // suggests the backing values
    string $priority,

    #[CompletionProvider(enum: Status::class)]    // suggests the case names
    string $status
): array
{
    // Implementation
}
```

A string-backed enum suggests its backing values. Unit enums and int-backed enums suggest their case names, so type
those parameters as `string`. An `int` parameter rejects a case name with a `-32602` error. To suggest numbers for an
`int` parameter, use `values: [1, 2, 3]`.

The SDK ignores `#[CompletionProvider]` on a parameter typed with a single class or enum (e.g. `Priority $priority`).
Type the parameter as `string` instead.

!!! note
    Discovery reads one class from each file and skips enums. A provider class before the handler class hides the
    handler, so put each provider class in its own file. See
    [Attribute-Based Discovery](registration.md#attribute-based-discovery).

### 3. Custom Provider Classes

For dynamic completion logic:

```php
use Mcp\Server\Capability\Attribute\CompletionProvider;
use Mcp\Server\Capability\Attribute\McpResourceTemplate;
use Mcp\Server\Capability\Completion\ProviderInterface;

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

- **Class strings** (`Provider::class`) → Resolved from the PSR-11 container, or created with `new Provider()` if the container doesn't have it
- **Instances** (`new Provider()`) → Used directly
- **Values** (`['a', 'b']`) → Wrapped in `ListCompletionProvider`
- **Enums** (`MyEnum::class`) → Wrapped in `EnumCompletionProvider`

> **Important**
> 
> Completion providers only offer **suggestions** to users. Users can still input any value, so **always validate
> parameters** in your handlers. Providers don't enforce validation - they're purely for UX improvement.

## Registering Providers Without Discovery

`#[CompletionProvider]` also works on the handlers you pass to `addPrompt()` and `addResourceTemplate()`.

For a `Prompt` or `ResourceTemplate` registered with `Builder::add()`, pass the providers as the third argument. The
keys are the argument or variable names:

```php
use Mcp\Server\Capability\Completion\ListCompletionProvider;

// $prompt is an Mcp\Schema\Prompt, ContentPromptHandler implements PromptHandlerInterface
$builder->add($prompt, new ContentPromptHandler(), [
    'contentType' => new ListCompletionProvider(['blog', 'article', 'tutorial']),
]);
```

`Builder::add()` throws an `Mcp\Server\Exception\InvalidArgumentException` if you pass providers with a `Tool` or a
`ResourceDefinition`. See [Explicit element registration](registration.md#explicit-element-registration).
