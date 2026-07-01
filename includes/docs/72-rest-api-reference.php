<?php
/**
 * @pfw-doc-page    wp-pfworkflow/rest-api-reference
 * @pfw-doc-title   REST API reference
 * @pfw-doc-order   62
 *
 * Complete reference for all WP-PFWorkflow REST API endpoints.
 * Base URL: `/wp-json/pfw-workflow/v1/`
 *
 * ## Authentication
 *
 * All endpoints require WordPress authentication unless marked **Public**.
 * Send `X-WP-Nonce` header with a nonce from `wp_create_nonce('wp_rest')`.
 *
 * ## Workflows
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/workflows` | List workflows (query: `status`, `tag`, `q`, `per_page`, `page`) |
 * | POST | `/workflows` | Create a new workflow |
 * | GET | `/workflows/{id}` | Get workflow with full graph |
 * | PUT | `/workflows/{id}` | Update workflow |
 * | DELETE | `/workflows/{id}` | Soft-delete (trash) |
 * | GET | `/workflows/{id}/export` | Export as JSON |
 * | POST | `/workflows/import` | Import from JSON body |
 * | POST | `/workflows/{id}/validate` | Validate graph structure |
 * | POST | `/workflows/{id}/run` | Execute manually |
 * | POST | `/workflows/{id}/test-run` | Dry-run (no side effects) |
 *
 * ## Executions
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/workflows/{id}/logs` | Execution logs (filter: `status`, `since`, `until`, `limit` 1-100) |
 * | GET | `/workflows/{id}/executions/{eid}/support` | Full support package (timeline + logs + replay data) |
 * | POST | `/workflows/{id}/executions/{eid}/cancel` | Cancel a running execution |
 * | POST | `/workflows/{id}/executions/{eid}/resume` | Resume from a specific node |
 *
 * ## Nodes & catalog
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/catalog` | Full node catalog (categories + nodes) |
 * | GET | `/catalog/contracts` | Typed node contracts (inputs, outputs, config schema) |
 * | GET | `/nodes/{key}` | Inspect a specific node's contract |
 * | POST | `/nodes/{key}/execute` | Execute a node in isolation |
 * | POST | `/nodes/{key}/dry-run` | Dry-run a node without side effects |
 *
 * ## Templates
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/templates` | List pre-built templates |
 * | GET | `/templates/{id}/install-plan` | Dependencies needed before installing |
 *
 * ## Studio
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/studio/context` | Site info and environment |
 * | GET | `/studio/options` | Available async option providers |
 * | GET | `/studio/options/{source}` | Resolved options from a provider |
 * | GET | `/studio/tokens` | Full token catalog |
 *
 * ## Webhooks
 *
 * | Method | Route | Auth | Description |
 * |---|---|---|---|
 * | GET,POST | `/webhooks/incoming` | Public | Generic incoming webhook receiver |
 * | POST | `/webhooks/incoming/{key}` | Public | Keyed incoming webhook receiver |
 *
 * ## Remote workers
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/remote/workers` | List workers |
 * | POST | `/remote/workers` | Create worker (token shown once) |
 * | GET | `/remote/workers/{id}` | Get worker details |
 * | DELETE | `/remote/workers/{id}` | Delete worker |
 * | POST | `/remote/workers/{id}/rotate` | Rotate bearer token |
 * | POST | `/remote/workers/{id}/enable` | Enable worker |
 * | POST | `/remote/workers/{id}/disable` | Disable worker |
 *
 * ## Remote queue
 *
 * | Method | Route | Auth | Description |
 * |---|---|---|---|
 * | GET | `/remote/contract` | Public | Worker self-discovery |
 * | POST | `/remote/queue/claim` | Bearer | Worker claims jobs |
 * | POST | `/remote/queue/{jobId}/heartbeat` | Bearer | Lease extension |
 * | POST | `/remote/queue/{jobId}/result` | Bearer | Report job result |
 * | GET | `/remote/queue` | WP auth | List all jobs |
 * | POST | `/remote/queue` | WP auth | Enqueue a job |
 * | GET | `/remote/queue/stats` | WP auth | Queue statistics |
 * | GET | `/remote/queue/{jobId}` | WP auth | Get job details |
 * | DELETE | `/remote/queue/{jobId}` | WP auth | Cancel job |
 *
 * ## Approvals
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/approvals/pending` | List pending approvals |
 * | GET | `/approvals/{id}` | Get approval details |
 * | POST | `/approvals/{id}/approve` | Approve (optional note) |
 * | POST | `/approvals/{id}/reject` | Reject (optional note) |
 *
 * ## OAuth2
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | POST | `/oauth/start` | Start authorisation code flow |
 * | GET | `/oauth/callback` | Provider redirect target (state-guarded) |
 *
 * ## Agent integration
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/agent/contract` | Typed contract for agent tool use |
 * | GET | `/agent/workflows/{id}` | Workflow snapshot for agent |
 *
 * ## System
 *
 * | Method | Route | Auth | Description |
 * |---|---|---|---|
 * | GET | `/diagnostics` | WP auth | Full diagnostics report |
 * | GET | `/heartbeat` | Public | Queue tick (rate-limited 30s/IP) |
 * | POST | `/loopback/run` | HMAC | Self-initiated queue processing |
 *
 * ## Error format
 *
 * All errors follow this envelope:
 *
 * ```json
 * {
 *   "status": "fail",
 *   "error": "workflow_not_found",
 *   "detail": "No workflow exists with id 42"
 * }
 * ```
 *
 * HTTP status codes: 400 (bad request), 401 (unauthorised), 403 (forbidden),
 * 404 (not found), 409 (conflict), 422 (validation), 429 (rate limit),
 * 500 (internal error), 502 (bad gateway).
 */

/**
 * @pfw-doc-page    wp-pfagent/rest-api-reference
 * @pfw-doc-title   REST API reference
 * @pfw-doc-order   72
 *
 * Complete reference for all WP-PFAgent REST API endpoints.
 * Base URL: `/wp-json/pfw-agent/v1/`
 *
 * ## Authentication
 *
 * All endpoints require WordPress authentication. Send `X-WP-Nonce`
 * header with a nonce from `wp_create_nonce('wp_rest')`.
 *
 * ## Contract & discovery
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/contract` | Full agent contract (routes, tools, providers, capabilities) |
 * | GET | `/contract/openapi` | OpenAPI 3.1 document for all endpoints |
 *
 * ## Provider management
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/provider-presets` | Catalog of all known provider presets |
 * | GET | `/provider-credentials` | List credential statuses per preset |
 * | POST | `/provider-credentials/{provider}` | Save/update API key + settings |
 * | DELETE | `/provider-credentials/{provider}` | Delete stored credential |
 * | POST | `/provider-credentials/{provider}/rotate` | Rotate credential |
 * | POST | `/provider-credentials/{provider}/test` | Test connection |
 * | GET | `/provider-models/{provider}` | Discover/cache models (`?force=true` to bypass cache) |
 * | POST | `/provider-models/{provider}/manual` | Save manually-entered model IDs |
 * | POST | `/provider-models/{provider}/save` | Persist wizard-confirmed model config |
 * | POST | `/provider-health/{provider}` | Full health check (discovery + validation) |
 * | POST | `/provider-runtime/{provider}/smoke` | Smoke test (one-sentence generation) |
 *
 * ## Agent runtime
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/agent-runtime/tools` | List declared tools with schemas |
 * | GET | `/agent-runtime/internal-docs` | Generated internal docs |
 * | POST | `/agent-runtime/fix-suggestions` | Diagnostic suggestions for tool errors |
 * | POST | `/agent-runtime/turn-v2` | Run user message through agent loop |
 * | POST | `/agent-runtime/resume-v2` | Resume after side-effect decision |
 * | GET | `/agent-runtime/progress` | Poll live progress during a turn |
 * | GET | `/agent-runtime/permission-rules` | Read permission rules |
 * | PUT | `/agent-runtime/permission-rules` | Save permission rules |
 * | GET | `/agent-runtime/support-export` | Full support export |
 * | GET | `/agent-runtime/metrics` | Aggregate metrics (`?windowHours=N`) |
 * | GET | `/agent-runtime/beta-readiness` | Evaluate beta readiness |
 *
 * ## Chat sessions
 *
 * | Method | Route | Description |
 * |---|---|---|
 * | GET | `/chat-sessions` | List user's sessions (paginated) |
 * | POST | `/chat-sessions` | Create session (label, optional workflowId) |
 * | POST | `/chat-sessions/purge` | Purge sessions older than N days (default 7) |
 * | GET | `/chat-sessions/{id}` | Get full session with messages |
 * | PATCH | `/chat-sessions/{id}` | Update label/workflowId |
 * | DELETE | `/chat-sessions/{id}` | Delete session + cascading data |
 *
 * ## Turn response format
 *
 * A successful turn returns:
 *
 * ```json
 * {
 *   "status": "ok",
 *   "result": {
 *     "kind": "success",
 *     "final_text": "I've created the workflow for you.",
 *     "usage": {"tokens_in": 1200, "tokens_out": 300, "cost_usd": 0.0045}
 *   }
 * }
 * ```
 *
 * When confirmation is needed:
 *
 * ```json
 * {
 *   "status": "ok",
 *   "result": {
 *     "kind": "needs_confirmation",
 *     "pending_tool_call": {
 *       "tool_name": "workflow_apply",
 *       "arguments": {...},
 *       "side_effect": "write",
 *       "summary": "Create a new workflow 'Order Alert'"
 *     }
 *   }
 * }
 * ```
 *
 * Then call `/resume-v2` with `{verdict: "approve"}` or `{verdict: "reject"}`.
 */
