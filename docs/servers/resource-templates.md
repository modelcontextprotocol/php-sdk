# Resource Templates

Resource templates are **dynamic resources** that use parameterized URIs with variables. They follow all the same rules
as static resources (URI schemas, return values, MIME types, etc.) but accept `{variable}` placeholders in the URI.

Only simple [RFC 6570](https://datatracker.ietf.org/doc/html/rfc6570) variable expansion
is supported — `{var}`, one path segment each. Operators such as `{+var}`, `{#var}`,
`{/path}`, `{?query}` and explode (`{list*}`) are not parsed, and a variable's value
cannot contain `/`.

```php
use Mcp\Server\Capability\Attribute\McpResourceTemplate;

class UserProvider
{
    /**
     * Retrieves user profile information by ID.
     */
    #[McpResourceTemplate(
        uriTemplate: 'user://{userId}/profile/{section}',
        name: 'user_profile',
        description: 'User profile data by section',
        mimeType: 'application/json'
    )]
    public function getUserProfile(string $userId, string $section): array
    {
        return $this->users[$userId][$section] ?? throw new \InvalidArgumentException("Profile section not found");
    }
}
```

## Parameters

- **`uriTemplate`** (required): URI with `{variables}`. Must start with a scheme (`file://`, `user://`, …) and contain at least one variable.
- **`name`** (optional): Short resource template identifier. Defaults to method name if not provided.
- **`title`** (optional): Human-readable display title shown in client UI. Distinct from `name`.
- **`description`** (optional): Template description. Falls back to the docblock (summary plus long description).
- **`mimeType`** (optional): MIME type of the resource content.
- **`annotations`** (optional): Additional metadata.
- **`meta`** (optional): Arbitrary key-value pairs for custom metadata.

## Variable Rules

1. **Variable names must match exactly** between URI template and method parameters —
   they are bound by name, so the parameter order is free
2. **All variables are required** - no optional parameters supported
3. **Type hints work normally** - parameters can be typed (string, int, etc.)

**Example mapping**: `user://123/profile/settings` → `getUserProfile("123", "settings")`
