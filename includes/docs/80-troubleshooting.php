<?php
/**
 * @pfw-doc-page    wp-pfagent/troubleshooting
 * @pfw-doc-title   Troubleshooting
 * @pfw-doc-order   80
 *
 *
 *
 * Common agent issues and their fixes.
 *
 * ## "The agent says it doesn't have access to my workflows"
 *
 * Two possible causes:
 *
 * 1. **WP-PFWorkflow isn't installed / activated / licensed.** Open
 *    **Project Flash → Agent → Diagnostics → Beta Readiness**; the
 *    "Workflow plugin reachable" check fails.
 *
 * 2. **Your user doesn't have `pfw_view_workflows` capability.**
 *    Admin has it by default; subscribers don't. Map it to the
 *    relevant role with a role-editor plugin, or have the agent run
 *    under a user that does.
 *
 * ## "Tool call XYZ keeps failing with `validation_error`"
 *
 * The LLM is generating tool arguments that don't match the schema.
 * Causes and fixes:
 *
 * - **Old model**. GPT-3.5 generation models hallucinate parameters
 *   often. Pick GPT-4o or Claude 3.5+ which handle structured
 *   outputs reliably.
 * - **Prompt overload**. If you have too many connections /
 *   workflows / records, the action catalog plus the conversation
 *   can push the model into degraded behaviour. Tighten the
 *   conversation length cap (**Settings → Conversation length**) so
 *   the older middle of the conversation gets summarised sooner.
 * - **OpenAI-compatible adapter mismatch**. Some self-hosted models
 *   advertise tool use but don't implement it correctly. Switch to
 *   an OpenAI / Anthropic / Gemini provider for agent use cases.
 *
 * ## "The gate keeps popping up for actions I want to auto-run"
 *
 * The side-effect gate is conservative by design. Use auto-approval
 * rules sparingly:
 *
 * 1. Go to **Project Flash → Agent → Settings → Gate → Rules**.
 * 2. Click **Add rule**.
 * 3. Pick the tool you want to auto-approve.
 * 4. Define the JSON-Logic predicate. Example: for
 *    `workflow.create` with rule
 *    `{ "==": [ { "var": "args.status" }, "draft" ] }`, the agent
 *    auto-approves only when the workflow being created is a draft.
 * 5. Test the rule against a sample with the inline preview.
 *
 * Rules are AND-combined within a tool; if any rule matches, the
 * action runs. Misconfigured rules can be dangerous — narrow is
 * better than wide.
 *
 * ## "Costs are surprisingly high"
 *
 * 1. **Project Flash → Agent → Telemetry → Cost by provider** shows
 *    where the money is going. Sort by `cost_usd desc` to find the
 *    expensive turns.
 * 2. Open the offending session. Common patterns:
 *    - The agent looped 10 times trying to fix a malformed tool
 *      call. Cause: bad provider for tool use. Switch provider.
 *    - You have a huge conversation history that is not getting
 *      summarised soon enough. Tighten the conversation length cap
 *      in **Settings → Conversation length**.
 *    - You're using the most expensive model for every turn.
 *      Configure a cheaper default and only switch to GPT-4o /
 *      Opus 4 for sessions that need depth.
 * 3. Set a hard monthly cap in **Settings → Budgets** so a runaway
 *    bug can never spend more than X.
 *
 * ## "The agent reports `error: budget_exceeded`"
 *
 * That's the budget doing its job — you (or someone using the
 * plugin) hit the configured limit. Either raise the limit
 * (**Settings → Budgets**) or wait for the next reset window
 * (daily / monthly). Past usage and remaining budget are visible on
 * the same page.
 *
 * ## "I want to disable streaming because it confuses my frontend"
 *
 * REST callers can simply not pass `"stream": true`. For the
 * in-admin Chat screen, set
 * `define('PFAGENT_DISABLE_STREAMING', true);` in `wp-config.php`.
 * Falls back to non-streaming responses (slower perceived UX but
 * simpler).
 *
 * ## "Provider health says 'failing'"
 *
 * Provider's API is returning errors to the probe. Three steps:
 *
 * 1. **Project Flash → Agent → Health → [provider name]** shows the
 *    last error message verbatim.
 * 2. Common causes: revoked API key, exhausted quota, provider
 *    outage. The error message tells you which.
 * 3. Re-test from **Providers → [provider name] → Test
 *    connection**. If it now succeeds, the next nightly probe will
 *    update health back to green.
 */
