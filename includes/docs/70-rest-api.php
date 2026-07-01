<?php
/**
 * @pfw-doc-page    wp-pfagent/rest-api
 * @pfw-doc-title   REST API
 * @pfw-doc-order   70
 *
 * Most callers interact with WP-PFAgent through the **Chat** screen
 * in wp-admin. But the same surface is also exposed as REST so you
 * can drive the agent from outside (a Slack bot, a CLI, a custom
 * frontend).
 *
 * Base: `/wp-json/pfw-agent/v1/`.
 *
 * Auth: cookie + `X-WP-Nonce` for in-browser. Application Password
 * for external callers. Required capability:
 * `pfw_agent_use` (mapped to `edit_posts` by default).
 *
 * ## POST `/sessions`
 *
 * Starts a new chat session.
 *
 * ```json
 * {
 *   "provider_id": 1,
 *   "model_id": "gpt-4o",
 *   "system_prompt_override": "(optional, takes from defaults if omitted)"
 * }
 * ```
 *
 * Response: `{ "session_id": "ses_abc123" }`.
 *
 * ## GET `/sessions/{id}`
 *
 * Returns session metadata + the message history.
 *
 * ## POST `/sessions/{id}/messages`
 *
 * Send a user message, get the agent's response (or a gate-pending
 * action) back.
 *
 * ```json
 * {
 *   "content": "List my workflows.",
 *   "stream": false
 * }
 * ```
 *
 * Response (success):
 *
 * ```json
 * {
 *   "status": "ok",
 *   "data": {
 *     "outcome": "answered",
 *     "assistant_message": "...",
 *     "tools_called": [
 *       { "name": "workflow.list", "args": {...}, "result_summary": "Returned 12 workflows" }
 *     ],
 *     "telemetry": { "tokens_in": 14200, "tokens_out": 380, "wall_ms": 2100, "cost_usd": 0.085 }
 *   }
 * }
 * ```
 *
 * Response when the agent is paused at the gate:
 *
 * ```json
 * {
 *   "status": "ok",
 *   "data": {
 *     "outcome": "gate_pending",
 *     "pending_action": {
 *       "action_id": "act_xyz",
 *       "tool": "workflow.create",
 *       "args": { ... },
 *       "summary": "Create workflow \"Thank big orders\""
 *     }
 *   }
 * }
 * ```
 *
 * Continue with:
 *
 * ## POST `/actions/{action_id}/approve`
 *
 * Approves a pending action. Optional body:
 *
 * ```json
 * { "note": "looks good — please create it as draft" }
 * ```
 *
 * The agent resumes the loop. Response is the same shape as a
 * regular `/messages` POST.
 *
 * ## POST `/actions/{action_id}/reject`
 *
 * Rejects with optional note. Body: `{ "note": "..." }`. Agent
 * resumes with the rejection in context and decides what to do
 * next.
 *
 * ## Streaming (advanced)
 *
 * Pass `"stream": true` on `/messages` to get a Server-Sent Events
 * stream of the response as the LLM produces it. The stream emits:
 *
 * - `event: token` with `data: {"delta": "...partial text..."}`
 *   for each text fragment.
 * - `event: tool_call` with `data: {"tool": "...", "args": {...}}`
 *   when the LLM decides to call a tool.
 * - `event: tool_result` with the tool's return value.
 * - `event: gate_pending` if the agent hits the side-effect gate.
 * - `event: done` at the end with the final outcome + telemetry.
 *
 * Used by the wp-admin Chat screen for "live typing" UX. Useful for
 * external integrations too.
 */
