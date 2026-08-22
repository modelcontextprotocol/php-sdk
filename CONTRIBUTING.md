# Contributing

Thanks for considering a contribution to the PHP SDK for the Model Context Protocol. This is a
collaboration between [the PHP Foundation](https://thephp.foundation/) and the
[Symfony project](https://symfony.com/), and it follows Symfony's conventions throughout.

## Ways to contribute

- **Report a bug** or **propose a feature** by [opening an issue](https://github.com/modelcontextprotocol/php-sdk/issues).
  Check existing issues first — many spec-driven changes are already tracked under the relevant
  `2026-07-28`-style release label.
- **Send a pull request** for a fix, a new capability, or a docs improvement.
- **Improve the guides** under `docs/` — see [Documentation](#documentation) below for how they're built.
- **Help close a conformance gap** — `make conformance-tests` runs the official MCP conformance
  suite against this SDK; a failing scenario there is a concrete, well-scoped contribution.

## Development setup

Requires PHP 8.1+.

```bash
composer install
```

## Before opening a pull request

Run the full CI suite locally:

```bash
make ci
```

This runs, in order: `make cs` (PHP CS Fixer, auto-fixes style), `make phpstan` (static analysis),
and `make tests` (unit + inspector tests). All three must pass. If your change touches
protocol-observable behavior, also run:

```bash
make conformance-tests   # requires Docker
```

## Coding standards

This project follows [Symfony's coding standards](https://symfony.com/doc/current/contributing/code/standards.html)
and [backward compatibility promise](https://symfony.com/doc/current/contributing/code/bc.html). In short:

- `declare(strict_types=1);` in every file.
- Type hints on every parameter and return value.
- Final classes by default — only leave a class non-final when it's deliberately designed for extension.
- Prefer immutable value objects.
- DocBlocks explain *why*, not *what* — the code and its types should already say what it does.

See [CLAUDE.md](CLAUDE.md) for a fuller tour of the codebase's architecture and layout.

## Tests

New capabilities need unit tests (`tests/Unit/`) covering the core logic, and — for anything
reachable over the wire — inspector tests (`tests/Inspector/`) for end-to-end coverage. If you're
adding a documented pattern, consider adding or updating an example under `examples/`.

## Documentation

The guides under `docs/` are built with [Zensical](https://zensical.org/) in `--strict` mode,
which fails the build on a broken internal link:

```bash
make docs-guides
```

Links between guide pages must be relative paths that resolve within `docs/` (e.g.
`protocol-versions.md`, `../CLAUDE.md` will *not* resolve — Zensical only follows the `docs/` tree).
For anything at the repo root (`CLAUDE.md`, `ROADMAP.md`, `CHANGELOG.md`), link to it by its GitHub
URL instead, matching the pattern already used across `docs/`. The class-level API reference is
generated separately by phpDocumentor (`make docs-api`) and isn't hand-written.

## Versioning

The SDK follows [Semantic Versioning](https://semver.org/) — see
[SDK tier target](docs/sdk-tier.md#versioning) for what that means pre- and post-1.0, and how it
lines up with Symfony's backward compatibility promise.

## Licensing

New contributions are licensed under Apache License, Version 2.0. Existing code predating this
policy remains under the MIT License — see [LICENSE](LICENSE) for the details. By opening a pull
request, you agree your contribution is provided under those terms.

## Getting help

If something is unclear or you want early feedback on an approach before writing code, open an
issue or a draft pull request — that's the right place to ask, rather than guessing at scope.
