# Caching

`server/discover`, the four list methods and `resources/read` **must** carry `ttlMs` and
`cacheScope`. The default is `ttlMs: 0, cacheScope: "private"` — conformant, and a flat
refusal to let anything be cached. Say what you actually mean:

```php
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server\Wire\CachePolicy;

->setCachePolicy(
    CachePolicy::default(30_000)
        ->withMethod('tools/list', 3_600_000, CacheScope::Public)
        ->withMethod('server/discover', 3_600_000, CacheScope::Public),
)
```

`public` lets a shared proxy serve one caller's answer to another, so use it only for
results that do not vary by caller. Only the operator can make that call, which is why the
conservative default stands until you change it.

A `ReadResourceResult` may set its own `ttlMs`/`cacheScope`, which win over the policy.

Results produced by an [MRTR retry](input-required.md) are never given hints: their inputs
are not part of any cache key.
