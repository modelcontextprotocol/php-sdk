# Schema generator

Generates the value objects in `src/Schema` from the MCP specification's JSON schemas in `spec/`.
Generated files carry the line "Generated from the MCP specification by tools/schema-generator, do not edit."
and are the only files the generator writes or deletes.

```bash
make schema-generate                 # regenerate from the vendored spec
make schema-check                    # fail if the committed classes are out of date (runs in CI)
make schema-update REF=<commit>      # vendor another upstream commit, then regenerate
```

## How the classes are derived

- One class per configured definition covers every revision in `revisions`: properties are merged, a property is
  required only if every revision requires it, and revision-specific ones are documented with their revisions.
- Objects become `final` readonly classes with `fromArray()`, `jsonSerialize()` and a `@phpstan-type <Name>Data`
  shape; enums become backed enums; unions of references become interfaces with a `<Name>Factory` that picks the
  member by its constant discriminator (`type`) or by a key only that member requires.
- `@deprecated` notices are read from `schema.ts`, since `schema.json` drops them.

## Configuration (`config.php`)

| Key | Purpose |
|---|---|
| `types` | Spec definition => class. References to anything not configured fail the generation. |
| `inline_enums` | `Type.property` enums that get their own enum class. |
| `maps`, `any` | Definitions kept as `array<string, mixed>` or `mixed`. |
| `omit` | Fields owned by the wire codecs, e.g. `resultType`. |
| `order` | Constructor parameter order; pin it once released, positional calls depend on it. |
| `defaults`, `always_emit` | Non-null defaults, and whether a default value is still sent. |
| `implements` | Extra interfaces of a class, or parents of a union interface. |
| `traits` | Hand-written behaviour (`src/Schema/Concern`), e.g. convenience factories. |
| `transforms` | Static methods applied to a property in the constructor, optionally widening the parameter type. |
| `validators` | Instance methods the constructor calls last. |
| `phpstan_types` | Extra `@phpstan-type` aliases a class exports. |

Behaviour the specification cannot express belongs in a trait wired up through `traits`, `transforms` or
`validators`, never in the generated file.
