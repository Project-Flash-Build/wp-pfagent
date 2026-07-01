<?php
/**
 * @pfw-doc-page    wp-pfagent/permission-rules
 * @pfw-doc-title   Permission rules
 * @pfw-doc-order   35
 *
 * ![Permission rules editor in the agent admin](docs-img:pfagent-permission-rules.png)
 *
 * Control which actions the agent can perform without asking you.
 * Permission rules let you fine-tune the trade-off between automation
 * and oversight.
 *
 * ## How permission rules work
 *
 * Every action the agent wants to perform goes through the rules
 * engine before executing. Three possible verdicts:
 *
 * | Verdict | Behaviour |
 * |---|---|
 * | `allow` | Execute immediately, no confirmation prompt. |
 * | `deny`  | Refuse to execute and tell the agent to try a different approach. |
 * | `ask`   | Show the side-effect confirmation card and wait for your decision. |
 *
 * ## Rule format
 *
 * Each rule has:
 *
 * - `tool` — the action it applies to (or `*` for everything).
 * - `verdict` — `allow`, `deny`, or `ask`.
 * - `when` (optional) — a set of conditions that must all match for
 *   the rule to fire.
 *
 * Conditions can match on the kind of action, on a target path, or on
 * any property of the action's arguments via dotted notation
 * (e.g. `payload.status`). Globbing (`*`, `?`) is supported in
 * condition values.
 *
 * ## Example
 *
 * ```json
 * [
 *   {"tool": "*", "verdict": "ask"},
 *   {"tool": "workflow_apply", "verdict": "allow",
 *    "when": {"kind": "read"}},
 *   {"tool": "pfm_apply", "verdict": "allow",
 *    "when": {"kind": "record", "action": "get"}},
 *   {"tool": "workflow_create_variable", "verdict": "allow"}
 * ]
 * ```
 *
 * This configuration:
 *
 * 1. Asks for confirmation on everything by default.
 * 2. Allows the agent to read workflows without asking.
 * 3. Allows the agent to read records without asking.
 * 4. Allows the agent to create variables without asking.
 *
 * ## Managing rules
 *
 * - **Admin UI**: Project Flash → Agent → Settings → Permission rules.
 * - **REST**: `GET / PUT /pfw/v1/agent-runtime/permission-rules`.
 *
 * > [!WARNING] Setting `*` to `allow` skips the side-effect
 * > confirmation gate entirely. Only do this in environments where
 * > you fully trust both the agent and every account that can talk
 * > to it.
 */

/**
 * @pfw-doc-page    wp-pfagent/budget-cost
 * @pfw-doc-title   Budget & cost management
 * @pfw-doc-order   45
 *
 * Track and cap LLM API spend. Every call is recorded; budgets stop
 * the agent before a runaway conversation costs you real money.
 *
 * ## Cost tracking
 *
 * Each LLM round-trip is recorded with: tokens in, tokens out, tokens
 * served from cache (not billed), estimated cost, which model, and
 * which provider. That data drives the cost views in the admin and
 * the support export.
 *
 * ## Budget limits
 *
 * | Limit | Where configured |
 * |---|---|
 * | Per-turn hard cap | Per provider, in Settings → Budgets. Stops the agent loop the moment a single turn would exceed it. |
 * | Daily token budget | Per provider, sliding 24h window. |
 * | Monthly cost cap | Per provider, USD. |
 *
 * When a limit is hit, the agent loop stops and the chat tells you
 * which budget tripped. No silent overrun.
 *
 * ## Viewing costs
 *
 * - **Admin dashboard widget** — aggregate stats (conversations,
 *   messages, providers).
 * - **REST**: `GET /pfw/v1/agent-runtime/metrics?windowHours=168` —
 *   cost by provider, tokens by provider, calls by action.
 * - **Support export** — full per-turn cost breakdown.
 *
 * ## Keeping costs down
 *
 * - **Prompt caching** — enable on supported providers to cut input
 *   token cost on repeated content.
 * - **Pick the right model size per task** — use a cheaper "small"
 *   model for routine work; reserve the larger model for the cases
 *   that actually need it.
 * - **Set a hard cap** even if you do not expect to hit it — it is
 *   the difference between a noticed mistake and a surprise bill.
 */

/**
 * @pfw-doc-page    wp-pfagent/sessions
 * @pfw-doc-title   Session management
 * @pfw-doc-order   65
 *
 * Chat sessions persist your conversations with the agent. Each
 * session keeps its own message history, action log, and trace data.
 *
 * ## Session lifecycle
 *
 * | Action | Endpoint |
 * |---|---|
 * | Create | `POST /pfw/v1/chat-sessions` |
 * | List   | `GET /pfw/v1/chat-sessions` |
 * | Get    | `GET /pfw/v1/chat-sessions/{id}` |
 * | Rename | `PATCH /pfw/v1/chat-sessions/{id}` |
 * | Delete | `DELETE /pfw/v1/chat-sessions/{id}` (cascades to messages, actions, traces) |
 * | Purge  | `POST /pfw/v1/chat-sessions/purge` (sessions older than N days, default 7) |
 *
 * ## Session data
 *
 * - Label — your name for the session (max 190 chars).
 * - Owner — the WordPress user who created it.
 * - Workflow (optional) — an associated workflow for context.
 * - Messages — user prompts and assistant responses in order.
 * - Actions — every action the agent proposed with arguments and
 *   outcome.
 * - Traces — timing, tokens, cost, and outcome per turn.
 *
 * ## Access control
 *
 * By default only the session owner can read or modify a session.
 * Site builders can broaden this — e.g. allow administrators to read
 * any session, or share sessions across a team — through the
 * documented access-control extension point.
 *
 * ## Secret redaction
 *
 * Anything that looks like an API key (e.g. `sk-…`, `sk-ant-…`) is
 * automatically redacted from stored messages and traces. You can
 * paste a key into a chat to ask "is this format correct?" without
 * the literal value being persisted anywhere on disk.
 */

/**
 * @pfw-doc-page    wp-pfagent/settings
 * @pfw-doc-title   Settings & configuration
 * @pfw-doc-order   75
 *
 * Top-level configuration for WP-PFAgent. Most settings live in the
 * admin SPA under **Project Flash → Agent → Settings**.
 *
 * ## Provider credentials
 *
 * - **API key** — encrypted at rest (see
 *   [Credential encryption](wp-pfagent/credential-encryption/)).
 * - **Provider presets** — built-in catalog of the major providers
 *   and any OpenAI-compatible endpoint. See
 *   [Provider presets](wp-pfagent/provider-presets/).
 * - **Per-model configuration** — after the setup wizard, the model
 *   list, capability flags, and pricing for each model live alongside
 *   the credential and are visible in the model picker.
 *
 * ## Rate limits
 *
 * Per-user, per-provider sliding windows. Configurable in
 * **Settings → Rate limits**:
 *
 * | Bucket | Purpose |
 * |---|---|
 * | Reads | List/get calls into the agent surface. |
 * | Config changes | Provider, credential, and rules edits. |
 * | LLM calls | Total LLM round-trips. |
 * | Agent turns | Chat turns started by the user. |
 *
 * ## Long conversations
 *
 * When a conversation approaches the model's context limit, the agent
 * keeps it flowing automatically — older history is summarised so
 * recent turns stay intact. The behaviour is on by default; the
 * trade-off and how to tune it live in the admin under
 * **Settings → Conversation length**.
 *
 * ## Provider-specific tuning
 *
 * Some providers expose features that the agent can opt into per
 * model — for example, response caching on providers that support it.
 * These are surfaced as toggles next to the model in the picker so you
 * can enable them where it helps and leave them off where it does not.
 */

/**
 * @pfw-doc-page    wp-pfagent/streaming
 * @pfw-doc-title   Streaming & progress
 * @pfw-doc-order   85
 *
 * WP-PFAgent supports real-time streaming and live progress so you
 * can see what the agent is doing as it happens.
 *
 * ## Server-Sent Events (SSE)
 *
 * Where the provider supports it, the agent can stream the response
 * token-by-token. Open the chat with `Accept: text/event-stream` and
 * the server holds the connection open, emitting events as work
 * progresses:
 *
 * - `token` — incremental text from the model.
 * - `tool_call` — the agent has decided to perform an action (name +
 *   arguments).
 * - `tool_result` — the result of an action.
 * - `gate_pending` — a confirmation card is waiting for you.
 * - `done` — the turn is complete, with usage stats.
 *
 * ## Live progress polling
 *
 * For non-streaming turns, the chat UI polls progress on
 * `GET /pfw/v1/agent-runtime/progress?conversationId=X`. That gives
 * you:
 *
 * - **Actions in flight** — name, status (pending, executing,
 *   complete, failed), timestamps.
 * - **Narrations** — brief status text the agent emits while working
 *   ("looking up the workflow…", "preparing the change…").
 * - **Trace updates** — the latest observability data for the
 *   current turn.
 *
 * ## When streaming is not available
 *
 * If the provider does not support streaming, the agent falls back to
 * synchronous turns and the chat UI relies on progress polling to
 * keep you informed. No configuration needed; this is automatic.
 */
