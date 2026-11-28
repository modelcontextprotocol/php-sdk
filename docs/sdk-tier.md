# SDK tier target (SEP-1730)

The MCP spec defines three SDK tiers: Tier 1 (Fully Supported), Tier 2
(Commitment to Full Support), and Tier 3 (Experimental). This document tracks
where the PHP SDK stands and what's still missing to move up a tier.

## Current standing

**Tier 3**, per the official audit in
[modelcontextprotocol/modelcontextprotocol#3274](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/3274)
(2026-08-19, superseding the earlier Tier 3 assessment in #2305). That audit
identified two blockers for Tier 2:

- **Client conformance was 20% (10/50) in that audit**, against the ≥80% bar.
  Almost entirely OAuth: 38 of 39 scored auth scenarios failed.
  Server conformance was 100% (67/67).
- **No stable release ≥ 1.0.0 has shipped yet**, see the
  [releases](https://github.com/modelcontextprotocol/php-sdk/releases). Tier 2
  requires at least one.

The SDK now implements [client OAuth](client/authorization.md). Both
[`conformance-baseline-2025-11-25.yml`](https://github.com/modelcontextprotocol/php-sdk/blob/main/tests/Conformance/conformance-baseline-2025-11-25.yml)
and
[`conformance-baseline-2026-07-28.yml`](https://github.com/modelcontextprotocol/php-sdk/blob/main/tests/Conformance/conformance-baseline-2026-07-28.yml)
have no expected client failures. An empty baseline is not a conformance score or a tier reassessment;
use the [weekly conformance runs](https://github.com/modelcontextprotocol/php-sdk/actions/workflows/conformance-weekly.yaml)
for measured results.

Tier 1 requires full (not ≥80%) client conformance, a stable release and
the documentation coverage the audit calls for. The audit listed 10 documentation gaps;
two concern features covered by
[Intentionally not implemented](#intentionally-not-implemented).

## Target

**Tier 2 next, Tier 1 after the 1.0 release.** Tier 1's "stable release"
requirement is structurally out of reach until 1.0 ships regardless of how
complete every other Tier 1 criterion is.

## Path to Tier 2

1. **Verify current client conformance against the ≥80% bar**, using the weekly results
   for each revision, and address any regressions as the conformance suite evolves.
2. **Ship a stable 1.0.0+ release.**

## Intentionally not implemented

- **The HTTP+SSE transport** of revision `2024-11-05`. The specification
  deprecates it in favor of Streamable HTTP, the only HTTP transport of the
  SDK's server and client.
- **`notifications/elicitation/complete`**. On revision `2025-11-25`, a server
  may send this optional notification when a URL-mode elicitation finishes.
  The SDK neither sends nor handles it.

## Versioning

The SDK follows [Semantic Versioning](https://semver.org/):
`MAJOR.MINOR.PATCH`. Pre-1.0, that means any digit can carry a breaking
change per SemVer §4 — every one is logged with a `[BC Break]` marker in
[`CHANGELOG.md`](https://github.com/modelcontextprotocol/php-sdk/blob/main/CHANGELOG.md).

Once at 1.0, the SDK adopts Symfony's
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html):
PATCH releases never break BC, MINOR releases only add functionality (gated
by the deprecation window in [Deprecation policy](deprecation-policy.md)),
and a breaking change ships only in a MAJOR release.

## Roadmap

See [ROADMAP.md](https://github.com/modelcontextprotocol/php-sdk/blob/main/ROADMAP.md)
for the feature-level plan toward 1.0. This document only tracks the
process/tier-classification gap, which is narrower and more mechanical than
the feature roadmap.
