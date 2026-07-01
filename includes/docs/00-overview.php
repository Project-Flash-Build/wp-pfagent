<?php
/**
 * @pfw-doc-page    wp-pfagent
 * @pfw-doc-title   WP-PFAgent
 * @pfw-doc-order   50
 *
 * ![WP-PFAgent home](docs-img:pfagent-home.png)
 *
 * WP-PFAgent is a natural-language interface that sits on top of
 * WP-PFWorkflow. You describe what you want in a sentence; the agent
 * picks the right tools, drafts a workflow, asks you to confirm any
 * side effects, and runs.
 *
 * It is the conductor layer of the three Project Flash plugins. It
 * requires WP-PFWorkflow installed and licensed on the same site —
 * the engine is the agent's hands.
 *
 * ## What you can ask it
 *
 * - "When a WooCommerce order is over 200 EUR, draft a thank-you note
 *   with the customer's purchase history, send it via my SMTP, and
 *   log it as a note on the order."
 * - "Show me every workflow that uses the OpenAI connection."
 * - "Run the lead-scoring workflow against this JSON: {...}".
 * - "The 'thank big orders' workflow failed three times today — read
 *   the latest execution and tell me why."
 * - "Add a delay of 24h between the AI node and the email node in
 *   the 'follow up' workflow."
 *
 * The first one BUILDS something new. The next ones INSPECT or MODIFY
 * something existing. The agent does both. The dividing line is the
 * [side-effect confirmation gate](wp-pfagent/side-effect-gate/) —
 * read-only requests run immediately, write/run/send/delete requests
 * pause and ask you to approve before executing.
 *
 * ## Bring your own LLM
 *
 * WP-PFAgent never proxies your LLM traffic through a Project Flash
 * server. You configure providers (OpenAI, Anthropic, Gemini, etc.)
 * with YOUR API keys. The agent calls those providers directly from
 * your WP server.
 *
 * That means:
 *
 * - Your costs go to your LLM provider account, not to us.
 * - Your conversation content never crosses a Project Flash network.
 * - You can mix providers — different workflows / sessions can use
 *   different models.
 *
 * Built-in presets cover the major providers — OpenAI, Anthropic,
 * Gemini, DeepSeek, Qwen, Grok — plus a generic "OpenAI-compatible"
 * preset for anything else exposing that API shape.
 *
 * ## How licensing works
 *
 * Three variants: x1 (1 site), x5 (5 sites), x25 (25 sites). Same
 * licensing model as WP-PFWorkflow — see
 * [Portal → Licensing](../portal/licensing/) for details.
 *
 * ## Where to go from here
 *
 * - First time using it: [Getting started](wp-pfagent/getting-started/)
 * - Understanding the architecture: [Concepts](wp-pfagent/concepts/)
 * - Connecting your LLM provider: [Providers](wp-pfagent/providers/)
 * - The tools the agent has: [Tools](wp-pfagent/tools/)
 * - Why the agent asks before doing things:
 *   [Side-effect confirmation gate](wp-pfagent/side-effect-gate/)
 */
