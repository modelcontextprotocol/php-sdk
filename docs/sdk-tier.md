# SDK Tier Target (SEP-1730)

The MCP spec defines three SDK tiers: Tier 1 (Fully Supported), Tier 2
(Commitment to Full Support), and Tier 3 (Experimental). This document tracks
where the PHP SDK stands against the Tier 2 bar and what's still missing.

## Target

**Tier 2 now, Tier 1 after the 1.0 release.**

Tier 1 requires a stable release with clear versioning. The SDK is currently
pre-1.0 and experimental (see [CLAUDE.md](../CLAUDE.md)), so Tier 1 is
structurally out of reach until 1.0 ships regardless of how complete any
other Tier 1 criterion is. Tier 2 has no such blocker and is achievable now.

## Gap analysis against Tier 2

| Requirement | Status | Evidence |
|---|---|---|
| ≥80% conformance | **Gap — server yes, client no** | Server: 144/145 (99%) on 2026-07-28, 39/40 (98%) on 2025-11-25. Client: 16/80 (20%) on 2026-07-28, 2/44 (5%) on 2025-11-25. The SDK is strong on the server surface and far below the bar on the client surface. This is the single largest gap to Tier 2 — closing it means building out client-side conformance coverage, not just docs/process. |
| New features within 6 months of a spec release | Informal, not tracked | No published SLA. The `2026-07-28`-labeled issue set and this triage effort are the closest thing to a tracked adoption process today; nothing publishes a commitment. |
| Triage within a month | Informal, not tracked | No published triage SLA. Labels exist to support one (see below) but aren't backed by a stated commitment. |
| P0 fix ≤2 weeks | Informal, not tracked | `P0`–`P3` labels exist and are used, but no published response-time commitment. |
| ≥1 stable release | Not yet | SDK is pre-1.0. |
| Basic docs | **Met** | `docs/` covers the builder API, client, transports, elements, events, authorization, extensions, and the stateless lifecycle. |
| Standardized GitHub label set | **Met** | `bug`, `enhancement`, `question`, `needs confirmation`, `needs repro`, `ready for work`, `good first issue`, `help wanted`, `P0`–`P3` are all present and in active use (confirmed via `gh label list`). |
| Published dependency-update policy | **Gap — mechanism exists, not documented** | `make deps-stable` / `make deps-low` (wired into `ci-stable` / `ci-lowest`) already test against both the newest and lowest-compatible dependency sets on every CI run. There's no doc stating this as a policy or naming a support window. |

## What closes the gap

Roughly in priority order:

1. **Client conformance.** This is the real blocker, not a doc gap. The
   client suite is failing the large majority of scenarios (20% / 5%).
   Investigate what's actually failing before assuming it's missing
   features vs. test/harness gaps — either way this needs engineering work,
   tracked separately from this doc.
2. **Publish the dependency-update policy.** The mechanism (`deps-stable`,
   `deps-low` in CI) already enforces support for both the newest and the
   lowest compatible dependency versions on every run. What's missing is a
   short published statement saying so, so it counts as "published" rather
   than merely "true." That's a stated policy, not new code:
   > The SDK's `composer.json` constraints are tested on every CI run
   > against both the newest allowed versions (`make deps-stable`) and the
   > lowest allowed versions (`make deps-low`), so both ends of the declared
   > range are guaranteed to work. Dependency bumps land as regular PRs;
   > there's no separate deprecation/removal schedule for dependencies
   > beyond what `composer.json`'s version constraints already express.
3. **State the triage/response SLAs**, once the team is actually willing to
   commit to the Tier 2 numbers (triage within a month, P0 fix within two
   weeks). Labels already exist to support this; only the commitment itself
   is missing, and it isn't this document's call to make.

## Roadmap

See [ROADMAP.md](../ROADMAP.md) for the feature-level plan toward 1.0. This
document only tracks the process/tier-classification gap, which is a
narrower and more mechanical thing than the feature roadmap.
