# SDK Tier Target (SEP-1730)

The MCP spec defines three SDK tiers: Tier 1 (Fully Supported), Tier 2
(Commitment to Full Support), and Tier 3 (Experimental). This document tracks
where the PHP SDK stands and what's still missing to move up a tier.

## Current standing

**Tier 3**, per the official audit in
[modelcontextprotocol/modelcontextprotocol#3274](https://github.com/modelcontextprotocol/modelcontextprotocol/issues/3274)
(2026-08-19, superseding the earlier Tier 3 assessment in #2305). Two things
block Tier 2:

- **Client conformance is 20% (10/50)**, against the ≥80% bar. Almost
  entirely OAuth: 38 of 39 scored auth scenarios fail. Every one of those
  failures is pre-declared in the SDK's own
  [`tests/Conformance/conformance-baseline-*.yml`](https://github.com/modelcontextprotocol/php-sdk/tree/main/tests/Conformance)
  files and tracked in `ROADMAP.md` — a known, scoped gap, not silent
  breakage. Server conformance is 100% (67/67).
- **No stable release ≥ 1.0.0 has ever shipped** (latest: v0.7.1). Tier 2
  requires at least one.

Tier 1 needs both of those plus full (not ≥80%) client conformance and
closing 10 documentation gaps the audit lists by name (mostly small
per-feature additions — legacy SSE transport and the elicitation
complete-notification need either an implementation or an explicit
"intentionally not implemented" note).

## Target

**Tier 2 next, Tier 1 after the 1.0 release.** Tier 1's "stable release"
requirement is structurally out of reach until 1.0 ships regardless of how
complete every other Tier 1 criterion is.

## Path to Tier 2

Roughly in priority order, per the audit's own recommendation:

1. **OAuth client conformance.** The single highest-leverage fix — it
   accounts for 38 of 40 client failures and blocks both tiers on its own.
   Already scoped: token endpoint auth methods, scope handling (step-up,
   retry-limit, from-`WWW-Authenticate`, from `scopes_supported`), dynamic
   client registration, issuer validation, `offline_access`,
   authorization-server migration — see `ROADMAP.md` and the
   `2026-07-28`-labeled auth issues.
2. **Fix the two non-auth client failures** (`sse-retry`,
   `elicitation-sep1034-client-defaults`, both scored at 2025-11-25).
3. **Ship a stable 1.0.0+ release.**

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
