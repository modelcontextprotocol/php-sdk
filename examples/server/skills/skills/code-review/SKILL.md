---
name: code-review
description: Review a pull request for correctness, security, and style following this team's conventions.
version: 1.0.0
tags:
  - review
  - quality
---

# Code Review

Follow these steps to review a pull request thoroughly and consistently.

## 1. Understand the change

- Read the PR description and linked issue to understand the intended behavior.
- Skim the diff top to bottom before commenting to build a mental model.

## 2. Correctness

- Check edge cases: empty input, nulls, boundary values, concurrency.
- Verify error handling fails fast and preserves context.
- Confirm tests cover the new behavior and actually assert on it.

## 3. Security

- See `references/SECURITY.md` for the security checklist that MUST be applied to
  every change touching authentication, input parsing, or external I/O.

## 4. Style & maintainability

- Match the surrounding code's naming, structure, and comment density.
- Prefer the simplest implementation that satisfies the requirement.

## 5. Wrap up

- Summarize findings grouped by severity (blocking, suggestion, nit).
- Approve only when blocking issues are resolved and CI is green.
