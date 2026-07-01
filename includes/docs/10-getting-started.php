<?php
/**
 * @pfw-doc-page    wp-pfagent/getting-started
 * @pfw-doc-title   Getting started
 * @pfw-doc-order   10
 *
 * ![WP-PFAgent home](docs-img:pfagent-home.png)
 *
 * From zero to a first agent conversation in about 5 minutes.
 *
 * ## Prerequisites
 *
 * - WordPress 6.5 or newer.
 * - PHP 8.1 or newer.
 * - **WP-PFWorkflow installed, activated and licensed** on the same
 *   site. WP-PFAgent uses the Workflow engine's REST API as its
 *   primary tool surface. Without it the agent has nothing to drive.
 * - A WP-PFAgent license key.
 * - An API key for at least one LLM provider (OpenAI, Anthropic,
 *   Gemini, etc.).
 *
 * ## 1. Install
 *
 * From [your customer dashboard](https://project-flash.com/my-account/):
 *
 * 1. **Generate download link** on your WP-PFAgent license.
 * 2. Download `wp-pfagent-X.Y.Z.zip`.
 *
 * In WordPress:
 *
 * 3. **Plugins → Add New → Upload Plugin** → choose the .zip → Install
 *    → Activate.
 *
 * A new sub-menu appears under **Project Flash → Agent**.
 *
 * ## 2. Activate the license
 *
 * **Project Flash → Agent → Settings**:
 *
 * 1. Paste the license key.
 * 2. Click **Activate**.
 *
 * Same flow as WP-PFWorkflow — see [Portal →
 * Licensing](../portal/licensing/) for what happens behind the scenes.
 *
 * ## 3. Add an LLM provider
 *
 * **Project Flash → Agent → Providers → Add Provider**:
 *
 * 1. Pick a preset from the dropdown (e.g. *OpenAI — GPT-4o*).
 * 2. Paste your API key. The form may ask for extra fields depending
 *    on the provider (organisation id for OpenAI, deployment id for
 *    Azure, base URL for OpenAI-compatible).
 * 3. Click **Test connection** — sends a tiny health-check
 *    completion. Success means the key works and the agent can use
 *    this provider.
 * 4. **Save**.
 *
 * You can add multiple providers — when starting a chat session you
 * pick which one to use for that session.
 *
 * ## 4. Start a chat session
 *
 * **Project Flash → Agent → Chat**:
 *
 * 1. Pick a provider + model from the dropdown.
 * 2. Type your first message. Try something simple to confirm the
 *    pipe works: *"List my workflows."*.
 * 3. Press Enter.
 *
 * The agent calls the `workflow.list` tool and shows you the list.
 * That confirms: provider → agent → Workflow engine all wired.
 *
 * ## 5. Try a build instruction
 *
 * Now ask something that BUILDS:
 *
 * > "Build a workflow that, when a WooCommerce order is over 200 EUR,
 * > sends a personalised thank-you email using OpenAI."
 *
 * The agent will:
 *
 * 1. Draft the workflow as a JSON definition.
 * 2. Show you the draft inline (graph view + JSON view).
 * 3. Pause at the [side-effect confirmation gate](wp-pfagent/side-effect-gate/)
 *    because creating a workflow is a write.
 * 4. Show two buttons: **Approve** and **Reject (with notes)**.
 *
 * Click **Approve**. The agent calls `workflow.create` with the
 * drafted definition. A new workflow appears in
 * **Project Flash → Workflows**, ready to test and activate as you
 * would with any hand-built workflow.
 *
 * ## What to do next
 *
 * - Read [Side-effect confirmation gate](wp-pfagent/side-effect-gate/)
 *   — it is the single most important concept to understand before
 *   using the agent for real work.
 * - Read [Tools](wp-pfagent/tools/) for the full list of what the
 *   agent can do.
 * - Configure rate limits + budgets in
 *   **Project Flash → Agent → Settings → Limits**.
 */
