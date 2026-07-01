<?php
/**
 * @pfw-doc-page    wp-pfagent/provider-presets
 * @pfw-doc-title   Provider presets
 * @pfw-doc-order   38
 *
 * WP-PFAgent ships with pre-configured presets for the major LLM
 * providers and any OpenAI-compatible endpoint. A preset wires the base
 * URL, model discovery, and the help text shown next to the API-key
 * field so adding a credential is a two-click flow.
 *
 * ## Supported provider families
 *
 * | Family | Examples |
 * |---|---|
 * | OpenAI-native | OpenAI, DeepSeek, Groq, Together AI, Fireworks, Cerebras, vLLM, Ollama, LM Studio |
 * | Anthropic | Anthropic Claude (direct API) |
 * | Google | Google Gemini, Vertex AI |
 * | OpenAI-compatible | any service that exposes an OpenAI-compatible `/chat/completions` endpoint |
 *
 * ## Discovery modes
 *
 * Each preset declares how the plugin discovers the model list for that
 * provider — automatically via the provider's own API, manually
 * (you type the model IDs), or a mix.
 *
 * ## Adding your own preset
 *
 * Custom providers can be added via a single WordPress filter. The
 * preset declares the provider id, base URL, family, and discovery
 * mode; the rest of the agent surface (credentials, model picker,
 * smoke test) wires up automatically.
 *
 * See [Providers](wp-pfagent/providers/) for per-provider setup notes.
 */

/**
 * @pfw-doc-page    wp-pfagent/credential-encryption
 * @pfw-doc-title   Credential encryption
 * @pfw-doc-order   39
 *
 * How WP-PFAgent stores LLM API keys and other secrets at rest.
 *
 * ## What we encrypt
 *
 * - LLM API keys (the full value).
 * - OAuth client secrets.
 * - Per-credential connection tokens.
 *
 * ## How
 *
 * - **Algorithm**: AES-256-GCM, the standard for authenticated
 *   symmetric encryption. Integrity-protected — any tampering with
 *   the ciphertext is detected and the value refuses to decrypt.
 * - **Encryption key**: derived from your WordPress installation's
 *   secret salts (the same constants WordPress itself uses to
 *   protect logged-in sessions). If your `wp-config.php` salts are
 *   strong, your credentials are too.
 * - **IV**: a fresh random value per encrypted record. Stored
 *   alongside the ciphertext.
 *
 * ## What we do NOT store encrypted
 *
 * Some metadata is intentionally readable so it can be queried and
 * displayed without unlocking the secret:
 *
 * - The provider id and preset name (needed to look the credential up).
 * - A masked tail of the key (last 4 characters, for the UI:
 *   `sk-...xyz1`).
 * - Model IDs, pricing, and health status (not secret).
 *
 * ## Operational handling
 *
 * - API keys are **never written to log files** by the plugin.
 * - API keys are **never sent in trace data or telemetry**.
 * - When a chat message would echo a key (e.g. you paste one), it is
 *   scrubbed before being persisted to the conversation log.
 * - Exports (workflow JSON, support bundles) never include decrypted
 *   credential values.
 *
 * ## Key rotation
 *
 * If your WordPress salts change (operator decision, or a security
 * incident response), existing credentials cannot be decrypted any
 * more. The plugin detects this and marks the credential as `failed`
 * in the admin UI — you simply re-enter the API key to re-encrypt it
 * under the new salts.
 *
 * ## What this gives you
 *
 * - A stolen database dump alone does not yield usable API keys; the
 *   attacker would also need `wp-config.php` (or whichever environment
 *   secret store the salts come from). Defense in depth: keep your
 *   filesystem and your database under separate access boundaries.
 * - Day-to-day, the encryption is transparent — saved keys are
 *   reusable across requests until you change them.
 */
