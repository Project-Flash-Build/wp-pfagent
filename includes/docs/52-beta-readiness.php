<?php
/**
 * @pfw-doc-page    wp-pfagent/beta-readiness
 * @pfw-doc-title   Beta readiness
 * @pfw-doc-order   52
 *
 * Evaluate whether your WP-PFAgent installation is ready for production
 * use. The readiness check inspects the environment, your provider
 * setup, and the host plugins, and gives a green / yellow / red verdict.
 *
 * ## What it checks
 *
 * - At least one LLM provider configured and its probe healthy.
 * - WP-PFWorkflow installed, activated, licensed, and reachable.
 * - At least one connection in WP-PFWorkflow (so the agent has
 *   something to talk to when it builds workflows that need external
 *   services).
 * - PHP version ≥ 8.1.
 * - WordPress version ≥ 6.5.
 * - WordPress encryption salts defined in `wp-config.php`.
 * - WP-Cron healthy.
 * - No PHP fatal errors in the plugin namespace in the last 24h.
 * - Rate limit / budget settings are not all zero (zero = disabled =
 *   unsafe in production).
 *
 * ## Where to run it
 *
 * - **Admin**: Setyenv → Agent → Settings → Beta Readiness.
 * - **REST**: `GET /pfw/v1/agent-runtime/beta-readiness`.
 *
 * ## How to read the verdict
 *
 * - **Green**: every check passes. The agent is safe to use in
 *   production.
 * - **Yellow**: a non-critical check is failing (e.g. only one provider
 *   configured). The agent works; address the warnings before
 *   production.
 * - **Red**: a critical check fails (no provider, no Workflow plugin,
 *   recent fatals). The agent will misbehave or refuse new sessions
 *   until you fix the underlying cause.
 *
 * ## When to re-check
 *
 * The check re-runs every time you open the page. Underlying probes
 * (provider health, etc.) are cached for a few minutes to avoid
 * hammering external services.
 *
 * Re-run it after:
 *
 * - upgrading WP-PFAgent
 * - changing provider credentials
 * - installing or upgrading WP-PFWorkflow
 * - hitting unexpected errors in agent conversations
 *
 * ## Common fixes
 *
 * | Failure | Fix |
 * |---|---|
 * | Provider not healthy | Re-test the connection and verify the API key is valid. |
 * | Workflow not reachable | Install and activate WP-PFWorkflow. |
 * | No connections exist | Add at least one provider connection. |
 * | PHP version too old | Upgrade to PHP 8.1 or later. |
 * | Encryption keys missing | Add the required salts to `wp-config.php`. |
 * | Cron not running | Set up a real cron job or improve WP-Cron reliability. |
 * | Fatal errors in log | Check the PHP error log for the specific error and fix it. |
 */

/**
 * @pfw-doc-page    wp-pfagent/provider-health
 * @pfw-doc-title   Provider health & smoke testing
 * @pfw-doc-order   42
 *
 * Validate that your LLM providers are reachable and usable before
 * relying on them in agent conversations.
 *
 * ## Health check
 *
 * `POST /pfw/v1/provider-health/{provider}`
 *
 * Runs the full validation flow:
 *
 * 1. **Discovery** — queries the provider's API for the model list.
 * 2. **Validation** — checks that at least one compatible model is
 *    found.
 * 3. **Status update** — updates the credential's status in the UI.
 *
 * Returns `connected` or `failed` with an error classification
 * (`auth_failure`, `network_error`, `no_models_found`, `unknown`).
 *
 * ## Smoke test
 *
 * `POST /pfw/v1/provider-runtime/{provider}/smoke`
 *
 * Goes a step further: it actually calls the LLM with a tiny
 * one-sentence generation. Tries up to a handful of candidate models
 * and returns the first success with usage data (tokens, cost). If
 * every candidate fails, the last error is returned so you can debug.
 *
 * > [!TIP] Run a smoke test after adding a new provider or rotating an
 * > API key. It catches configuration errors (wrong base URL, invalid
 * > model name) that a plain health check might miss.
 */
