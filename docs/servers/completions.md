# Completion Providers

Completion providers help MCP clients offer auto-completion suggestions for Resource Templates and Prompts. Unlike Tools and static Resources (which can be listed via `tools/list` and `resources/list`), Resource Templates and Prompts have dynamic parameters that benefit from completion hints.

## Completion Provider Types

### 1. Value Lists

Provide a static list of possible values:

```php
use Mcp\Capability\Attribute\CompletionProvider;

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

A string-backed enum suggests its backing values. Unit enums and int-backed enums suggest their case names.

The SDK reads `#[CompletionProvider]` only on parameters with a built-in type, such as `string` or `int`. On a
parameter typed with a class or an enum (e.g. `Priority $priority`), the attribute is ignored.

### 3. Custom Provider Classes

For dynamic completion logic:

```php
use Mcp\Capability\Completion\ProviderInterface;

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
use Mcp\Capability\Completion\ListCompletionProvider;

$builder->add($prompt, new ContentPromptHandler(), [
    'contentType' => new ListCompletionProvider(['blog', 'article', 'tutorial']),
]);
```

`Builder::add()` throws an `Mcp\Exception\InvalidArgumentException` if you pass providers with a `Tool` or a
`ResourceDefinition`. See [Explicit element registration](registration.md#explicit-element-registration).
