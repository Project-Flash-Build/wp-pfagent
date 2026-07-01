<?php
/**
 * @pfw-doc-page    wp-pfagent/observability
 * @pfw-doc-title   Observability
 * @pfw-doc-order   60
 *
 * What the agent records about itself so you can debug, audit, and
 * tune it.
 */

/**
 * @pfw-doc-page    wp-pfagent/observability/action-inspector
 * @pfw-doc-title   Action Inspector
 * @pfw-doc-order   10
 *
 * Every action the agent has ever proposed — whether you approved it,
 * rejected it, or it was auto-decided by your permission rules — is
 * preserved in **Project Flash → Agent → Action Log**.
 *
 * ## What each row shows
 *
 * - **Timestamp** (UTC).
 * - **Session** — links through to the chat session that produced
 *   this action.
 * - **Action** — what the agent wanted to do, in plain language.
 * - **Arguments** — the exact values the agent intended to use.
 * - **Side-effect class** — read, write, run, or delete.
 * - **Decision** — approved, rejected, auto-approved, auto-rejected,
 *   pending.
 * - **Approver** — which WordPress user clicked the button.
 * - **Rejection note** — only present when rejected.
 * - **Reasoning** — the LLM's reasoning trace, when the provider
 *   supports it (e.g. Anthropic extended thinking).
 * - **Outcome** — what the action actually returned, when it ran.
 *
 * ## What this is for
 *
 * **Audit.** If a customer asks *"did your agent change my workflow
 * yesterday?"*, the answer is in this log with timestamps and
 * approver IDs. Compliance-critical.
 *
 * **Tuning.** If you see the agent repeatedly proposing the same write
 * that you keep rejecting, that is a signal — either your
 * auto-approval rules are too narrow, or your prompts are too
 * open-ended.
 *
 * ## Retention
 *
 * The Action Log retains entries for one year by default. Operators
 * who need a different retention can override it; the cleanup job
 * runs daily.
 */

/**
 * @pfw-doc-page    wp-pfagent/observability/turn-telemetry
 * @pfw-doc-title   Turn telemetry
 * @pfw-doc-order   20
 *
 * Every agent turn records what it cost and how it went. Useful for
 * cost analysis and for finding turns that hit budget or time limits.
 *
 * ## What is recorded per turn
 *
 * - Which session and which turn within it.
 * - Which provider and which model were used.
 * - Wall-clock duration.
 * - Token counts in and out, and how many of the input tokens came
 *   from the prompt cache (and were therefore cheaper).
 * - Estimated cost in USD based on the provider's published prices.
 * - Outcome — answered cleanly, paused for confirmation, rejected at
 *   the gate, hit a tool limit, hit a budget, timed out, or errored.
 * - An error code when the outcome was an error.
 *
 * ## Where to see it
 *
 * **Project Flash → Agent → Telemetry**:
 *
 * - **Sparkline** at the top: turns per day for the last 30 days.
 * - **By outcome**: breakdown of how turns ended.
 * - **Cost by provider**: stacked bar of USD spent per provider per
 *   day. Helps you spot a runaway session.
 * - **Slowest turns**: list of the 10 slowest turns this week with
 *   click-through to the session and its timeline.
 *
 * ## Support export
 *
 * The **Export → Support bundle** button at the bottom packages the
 * recent telemetry, your provider configuration (credentials
 * redacted), your current rate-limit and budget settings, the recent
 * Action Log, and the PHP error log for the plugin namespace. One
 * .zip — attach it to a support ticket and we can reproduce most
 * issues from just that bundle.
 */
