---
name: refunds
description: Process a customer refund following Acme's billing policy and approval thresholds.
version: 1.0.0
tags:
  - billing
  - support
---

# Processing Refunds

A nested skill demonstrating multi-segment skill paths (`skill://acme/billing/refunds/SKILL.md`).

## Policy

1. Verify the charge exists and has not already been refunded.
2. Refunds up to $100 may be issued directly.
3. Refunds above $100 require a team lead's approval before issuing.

## Steps

1. Look up the original charge by order ID.
2. Confirm the refund amount does not exceed the charged amount.
3. Issue the refund and record the reason code.
4. Notify the customer with the expected settlement window.
