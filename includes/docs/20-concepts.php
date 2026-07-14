<?php
/**
 * @pfw-doc-page    wp-pfagent/concepts
 * @pfw-doc-title   Concepts
 * @pfw-doc-order   20
 *
 * The vocabulary for working with WP-PFAgent. Pick a concept from the
 * sidebar.
 */

/**
 * @pfw-doc-page    wp-pfagent/concepts/budgets-and-rate-limits
 * @pfw-doc-title   Budgets and rate limits
 * @pfw-doc-order   30
 *
 * BYOK doesn't mean unlimited. Budgets cap how much a runaway
 * conversation can accidentally spend; rate limits prevent a buggy or
 * abusive caller from draining your provider quota.
 *
 * ## Budgets
 *
 * Configure per-provider in **Setyenv → Agent → Settings →
 * Budgets**:
 *
 * - **Daily token budget** — a hard cap on input + output tokens for
 *   that provider per UTC day.
 * - **Monthly cost cap (USD)** — an approximate cap based on the
 *   provider's published per-token pricing.
 *
 * When a budget is hit, the agent refuses new turns against that
 * provider with `error: budget_exceeded` and the admin sees a notice
 * in **Setyenv → Agent → Health**. Budgets reset on the configured
 * cadence.
 *
 * ## Rate limits
 *
 * Applied per-user, per-provider on a sliding window:
 *
 * - **Turns per minute** (chat turns started by this user).
 * - **Tool calls per minute** (aggregated across all turns).
 *
 * Hitting a rate limit returns `error: rate_limited` with a
 * `Retry-After` hint. The chat UI surfaces this as a soft inline
 * warning rather than a crash.
 *
 * ## Provider back-off
 *
 * Independently of your budgets and rate limits, the agent respects
 * the provider's own `Retry-After` and 429 responses with exponential
 * back-off. You will not double-charge yourself by hammering a
 * throttled endpoint.
 *
 * ## Where to start
 *
 * The defaults are conservative on purpose: high enough to be
 * unobtrusive for normal use, low enough to keep a runaway agent
 * loop from doing financial damage. Tighten them in production
 * once you have a baseline for your actual usage.
 */
