<?php
/**
 * @pfw-doc-page    wp-pfagent/side-effect-gate
 * @pfw-doc-title   Side-effect confirmation gate
 * @pfw-doc-order   30
 *
 * The single most important safety mechanism in WP-PFAgent. The agent
 * NEVER performs an action with side effects (write, run, send,
 * delete) without the human confirming first.
 *
 * ## What counts as a side effect
 *
 * Each tool is tagged at registration time with one of:
 *
 * - **read** — pure introspection. Examples: `workflow.list`,
 *   `workflow.get`, `execution.list`, `execution.get`,
 *   `connection.list`, `health.check`. These run immediately without
 *   confirmation.
 * - **write** — creates or modifies data in the WP database.
 *   Examples: `workflow.create`, `workflow.update`,
 *   `connection.create`, `connection.update`.
 * - **run** — triggers a workflow execution. Example: `workflow.run`.
 * - **send** — emits an outbound message (email, webhook). Currently
 *   no tool falls here directly; the agent calls `workflow.run` and
 *   the workflow itself decides if a `send` happens.
 * - **delete** — removes data. Examples: `workflow.delete`,
 *   `connection.delete`, `execution.purge`.
 *
 * Anything in **write / run / send / delete** triggers the gate.
 *
 * ## What "the gate" actually does
 *
 * When the agent decides to call a write/run/send/delete tool:
 *
 * 1. The agent loop **pauses** before executing the call.
 * 2. The chat UI shows a structured **confirmation card** with:
 *    - The tool being called.
 *    - The exact arguments (formatted as a diff against the current
 *      state when applicable, e.g. for `workflow.update`).
 *    - A one-line summary of what will happen.
 *    - Two buttons: **Approve** and **Reject (with note)**.
 * 3. The user either approves (the tool runs, the agent continues)
 *    or rejects (the agent receives a "user rejected: <note>"
 *    message and can adjust its plan).
 *
 * The gate cannot be globally turned off. It can be NARROWED per
 * tool (e.g. "auto-approve `workflow.create` only when the workflow
 * status is `draft`") but never widened.
 *
 * ## The Action Inspector
 *
 * Every gate prompt is preserved in the Action Log
 * (**Project Flash → Agent → Action Log**), which lists every
 * decision the agent has wanted to make, with:
 *
 * - Tool name + arguments
 * - User who approved/rejected
 * - Timestamp
 * - The reasoning the LLM gave for wanting to do it (when the
 *   provider supports tool-use reasoning, e.g. Anthropic's
 *   extended thinking)
 *
 * This is the audit trail for "what did the agent try to do, and
 * what did we agree to". Critical for compliance contexts.
 *
 * ## Auto-approval rules (advanced)
 *
 * In **Project Flash → Agent → Settings → Gate**, you can define
 * narrow auto-approval rules:
 *
 * - For tool `workflow.create`, auto-approve when
 *   `arguments.status == 'draft'`. Rationale: a draft workflow has
 *   no execution side effect until it's activated, so creating it
 *   is low-risk.
 * - For tool `execution.replay`, auto-approve when the original
 *   execution status was `failed`. Rationale: replaying a failed
 *   execution is usually what you wanted anyway.
 *
 * Rules are JSON-Logic expressions on the tool's argument schema.
 * The full DSL is documented in
 * **Project Flash → Agent → Settings → Gate → Rules → Help**.
 *
 * ## What "rejecting" sends back to the agent
 *
 * When you reject, the chat UI lets you add an optional note. The
 * agent receives the note as a message:
 *
 * > Tool call rejected by user. User's note: "{your text}"
 *
 * The agent can use the note to refine its plan. Common pattern:
 * the agent proposes `workflow.create` with status `active`; you
 * reject with note "create as draft first, I want to review the
 * graph before activating"; the agent adjusts and re-proposes
 * with `status: draft`.
 */
