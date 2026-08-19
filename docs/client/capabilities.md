# Tools, resources & prompts

Once connected, everything the server exposes is reachable through the client: list what
is there, then call it. Each list method returns the server's own descriptions and
schemas, so a generic client can build its UI from them.

## Working with Tools

### Listing Tools

```php
$toolsResult = $client->listTools();

foreach ($toolsResult->tools as $tool) {
    echo "- {$tool->name}: {$tool->description}\n";
}

// Handle pagination
if ($toolsResult->nextCursor) {
    $moreTools = $client->listTools($toolsResult->nextCursor);
}
```

### Calling Tools

```php
use Mcp\Schema\Content\TextContent;

$result = $client->callTool(
    name: 'calculate',
    arguments: ['a' => 5, 'b' => 3, 'operation' => 'add'],
);

// Access results
foreach ($result->content as $content) {
    if ($content instanceof TextContent) {
        echo $content->text;
    }
}
```

### Progress Notifications

Hook into tool execution progress (if server supports it):

```php
$result = $client->callTool(
    name: 'long_running_task',
    arguments: ['data' => 'large_dataset'],
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        $percent = $total > 0 ? round(($progress / $total) * 100) : 0;
        echo "Progress: {$percent}% - {$message}\n";
    }
);
```

!!! note
    Progress notifications are only received if the server sends them. The callback will not be invoked if the server doesn't support or send progress updates.

## Working with Resources

### Listing Resources

```php
$resourcesResult = $client->listResources();

foreach ($resourcesResult->resources as $resource) {
    echo "- {$resource->uri}: {$resource->name}\n";
}
```

### Listing Resource Templates

```php
$templatesResult = $client->listResourceTemplates();

foreach ($templatesResult->resourceTemplates as $template) {
    echo "- {$template->uriTemplate}: {$template->name}\n";
}
```

### Reading Resources

```php
use Mcp\Schema\Content\{TextResourceContents, BlobResourceContents};

$resourceResult = $client->readResource('config://app/settings');

foreach ($resourceResult->contents as $content) {
    if ($content instanceof TextResourceContents) {
        echo "Text: {$content->text}\n";
    } elseif ($content instanceof BlobResourceContents) {
        echo "Binary data (base64): {$content->blob}\n";
    }
}
```

Resources also support progress notifications:

```php
$result = $client->readResource(
    uri: 'file://large-file.bin',
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        echo "Reading: {$progress}/{$total} bytes\n";
    }
);
```

## Working with Prompts

### Listing Prompts

```php
$promptsResult = $client->listPrompts();

foreach ($promptsResult->prompts as $prompt) {
    echo "- {$prompt->name}: {$prompt->description}\n";
}
```

### Getting Prompts

```php
$promptResult = $client->getPrompt(
    name: 'code_review',
    arguments: ['language' => 'php', 'code' => '...'],
);

foreach ($promptResult->messages as $message) {
    echo "{$message->role->value}: {$message->content->text}\n";
}
```

Prompts also support progress notifications:

```php
$result = $client->getPrompt(
    name: 'generate_report',
    arguments: ['topic' => 'quarterly_analysis'],
    onProgress: static function (float $progress, ?float $total, ?string $message) {
        echo "Generating: {$message}\n";
    }
);
```

### Requesting Completions

Request auto-completion suggestions for prompt or resource arguments:

```php
use Mcp\Schema\PromptReference;

$completionResult = $client->complete(
    ref: new PromptReference('code_review'),
    argument: ['name' => 'language', 'value' => 'ph'],
);

foreach ($completionResult->values as $value) {
    echo "Suggestion: {$value}\n";
}
```
