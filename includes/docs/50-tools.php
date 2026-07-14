<?php
/**
 * @pfw-doc-page    wp-pfagent/tools
 * @pfw-doc-title   What the agent can do
 * @pfw-doc-order   50
 *
 * The agent's capabilities — what it can read, build, change, run, and
 * inspect on your behalf. Everything that writes, runs, or deletes
 * pauses at the [side-effect confirmation gate](wp-pfagent/side-effect-gate/)
 * for your approval before it executes.
 *
 * ## Managing WordPress directly
 *
 * On any install — no other plugin required — the agent reads and manages your
 * content, taxonomies, media, users, comments, settings, menus and site
 * overview, and works with popular plugins (WooCommerce, SEO, forms, LearnDash,
 * MemberPress) when they are active. See
 * [Managing WordPress directly](wp-pfagent/wordpress/) for the full list.
 *
 * ## Workflows
 *
 * The agent can list, read, design, modify, activate, deactivate, and
 * delete workflows. Designing a new workflow or changing an existing
 * one is a write action and always requires your approval before it
 * lands.
 *
 * ## Executions
 *
 * The agent can browse past executions and inspect the full timeline
 * of any one of them — every node visited, its inputs and outputs,
 * and any errors. It can also trigger a new run, or replay a past
 * execution from a specific step (useful for recovering from a
 * downstream service that was temporarily down).
 *
 * ## Connections
 *
 * The agent can list stored credentials (values are masked) and add,
 * update, or remove them. Adding or rotating a credential is a write
 * action; removing one that is currently in use is refused unless you
 * explicitly force it.
 *
 * ## Records (with WP-PFManagement)
 *
 * When WP-PFManagement is installed, the agent can list, query, read,
 * and modify records of any entity it has permission for. Designing
 * new entities or fields is a write action and requires your approval.
 *
 * ## System inspection
 *
 * - **Health check** — aggregate health of providers and the queue
 *   worker.
 * - **Diagnostics dump** — site fingerprint, plugin versions, recent
 *   error rates. Useful to attach to a support ticket.
 *
 * ## Grounded answers
 *
 * The agent can search this documentation site to ground its answers
 * in real reference material rather than guessing capabilities. If you
 * notice it claiming a feature that does not exist, that is the
 * signal that the docs and the implementation drifted — please report
 * it.
 *
 * ## Why everything is typed
 *
 * Every capability above is described to the LLM with an explicit input
 * and output schema. When the LLM proposes an action, the proposal is
 * checked against that schema before anything runs — invalid proposals
 * are rejected with a structured error message the LLM uses to
 * self-correct on the next turn. You should rarely see this; when you
 * do, it shows up in the trace.
 */
