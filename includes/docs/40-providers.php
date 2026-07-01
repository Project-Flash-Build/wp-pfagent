<?php
/**
 * @pfw-doc-page    wp-pfagent/providers
 * @pfw-doc-title   Providers
 * @pfw-doc-order   40
 *
 * ![Add LLM credential](docs-img:pfagent-add-credential.png)
 *
 * Providers are LLM endpoints the agent can call. WP-PFAgent is BYOK
 * — you bring your own keys for each provider you want to use.
 *
 * ## Built-in presets
 *
 * Presets ship for the major LLM providers and any OpenAI-compatible
 * endpoint. Each preset is a curated set of (provider adapter,
 * recommended models, default parameters, capabilities). You can
 * override any of these per provider instance.
 *
 * | Family | Adapter | Notes |
 * |---|---|---|
 * | **OpenAI** | `openai` | GPT-4o, GPT-4 Turbo, o1 series, o3-mini. Tool use + structured outputs supported. |
 * | **Anthropic** | `anthropic` | Claude 3.5 / Claude 3.7 / Claude 4 families. Tool use + extended thinking + prompt caching supported. |
 * | **Google Gemini** | `gemini` | Gemini 1.5 Pro / Flash. Tool use supported. |
 * | **Azure OpenAI** | `openai` (Azure variant) | Microsoft-hosted OpenAI; you specify your deployment id and base URL. |
 * | **AWS Bedrock** | `bedrock` | Multiple models via Bedrock (Claude, Llama, Mistral). IAM-based auth. |
 * | **DeepSeek** | `openai-compatible` | API-compatible with OpenAI; specify base URL. |
 * | **Qwen** | `openai-compatible` | Alibaba's API. |
 * | **Mimo** | `openai-compatible` | |
 * | **Grok (xAI)** | `openai-compatible` | |
 * | **Generic OpenAI-compatible** | `openai-compatible` | For anything else: Together AI, Groq, Fireworks, Cerebras, LM Studio, vLLM, etc. |
 *
 * ## Adding a provider
 *
 * **Project Flash → Agent → Providers → Add Provider**:
 *
 * 1. Pick a preset. The form auto-fills the recommended fields.
 * 2. Override or accept the defaults:
 *    - **Label** — your own name for this provider (e.g. "OpenAI
 *      prod", "OpenAI dev personal key").
 *    - **API key** — the credential. Stored encrypted at rest; see
 *      [Credential encryption](wp-pfagent/credential-encryption/).
 *    - **Default model** — which model the chat dropdown
 *      pre-selects. Override per session.
 *    - **Base URL** — only shown for OpenAI-compatible adapters.
 *    - **Organisation ID** — only shown for OpenAI when you have a
 *      multi-org account.
 *    - **Deployment ID** — only shown for Azure OpenAI.
 *    - **Temperature / max_tokens** — runtime defaults; can be
 *      overridden per session.
 * 3. **Test connection** sends a single-token probe completion to
 *    confirm the credential works.
 * 4. **Save**.
 *
 * ## Provider health
 *
 * The plugin runs a daily background probe against every configured
 * provider and exposes the result in **Project Flash → Agent →
 * Health** as one of `healthy`, `degraded`, or `failing`, with the
 * last probe time, latency and (if any) the error message. This lets
 * you spot a key that has been revoked or a provider that is
 * degraded without waiting for a real conversation to fail.
 *
 * ## Prompt caching
 *
 * On providers that support it (e.g. Anthropic), the agent
 * automatically marks the parts of each request that stay constant
 * within a session — system prompt, action catalog — as cacheable.
 * Cached input tokens are billed at a fraction of the normal rate,
 * so a chatty session costs noticeably less after the first turn.
 * Cache lifetime is provider-specific; the plugin schedules turns to
 * keep the cache warm where it can.
 *
 * ## Back-off under load
 *
 * When a provider returns 429 or a `Retry-After` header, the agent
 * waits the requested amount and retries with exponential back-off
 * and jitter, capped by the turn's wall-clock budget. The chat
 * surfaces a single inline notice rather than failing on the first
 * 429.
 */

/**
 * @pfw-doc-page    wp-pfagent/providers/openai
 * @pfw-doc-title   OpenAI
 * @pfw-doc-order   10
 *
 * Setting up OpenAI as a provider.
 *
 * ## Get a key
 *
 * https://platform.openai.com/api-keys → Create new secret key.
 * Restrict it to **API access only**. Save the key string (starts
 * with `sk-`).
 *
 * ## Add the provider
 *
 * **Project Flash → Agent → Providers → Add Provider → OpenAI**.
 *
 * Required fields:
 *
 * - **API key**: the `sk-` string.
 * - **Default model**: `gpt-4o` recommended for general use,
 *   `gpt-4o-mini` for cost-optimised runs.
 *
 * Optional:
 *
 * - **Organisation ID** (only if your account has multiple orgs).
 * - **Project ID** (if you use OpenAI Projects).
 *
 * ## Pricing
 *
 * As of writing: GPT-4o is $5/M input + $15/M output. GPT-4o-mini is
 * $0.15/M + $0.60/M. For a typical agent turn (15K input including
 * tool catalog + 1K output), GPT-4o costs about $0.09 per turn,
 * GPT-4o-mini costs about $0.003 per turn.
 *
 * Always check https://openai.com/pricing for current numbers.
 *
 * ## Tool use
 *
 * Fully supported via OpenAI's `tools` parameter. The agent uses
 * `tool_choice: "auto"` so the LLM decides when to call a tool vs
 * answer directly.
 *
 * ## Structured output
 *
 * For tools that need strict JSON output, the agent uses OpenAI's
 * `response_format: json_schema` mode when the model supports it
 * (GPT-4o and newer). Reduces malformed-JSON retries to nearly zero.
 *
 * ## Known issues
 *
 * - `o1` and `o3-mini` reasoning models don't support tool use in
 *   the same way; the agent falls back to a "describe what you would
 *   do, then a separate model executes" pattern, which is slower.
 *   For agent use cases pick a GPT-4o variant.
 */

/**
 * @pfw-doc-page    wp-pfagent/providers/anthropic
 * @pfw-doc-title   Anthropic
 * @pfw-doc-order   20
 *
 * Setting up Anthropic Claude as a provider.
 *
 * ## Get a key
 *
 * https://console.anthropic.com/settings/keys → Create key.
 *
 * ## Add the provider
 *
 * **Project Flash → Agent → Providers → Add Provider → Anthropic**.
 *
 * Required:
 *
 * - **API key**: starts with `sk-ant-`.
 * - **Default model**: `claude-sonnet-4` recommended (good
 *   cost/quality balance). `claude-opus-4` for the deepest
 *   reasoning, `claude-haiku-4-5` for the cheapest fast turns.
 *
 * ## Pricing
 *
 * Claude Sonnet 4 is $3/M input + $15/M output (last published
 * numbers; check https://www.anthropic.com/pricing). For the same
 * 15K + 1K turn shape, that's about $0.06 per turn.
 *
 * ## Tool use
 *
 * Fully supported with Anthropic's `tools` parameter. Models also
 * support **extended thinking** — the model can reason at length
 * before deciding what tool to call. The agent uses this when
 * available, showing the reasoning trace in the Action Inspector.
 *
 * ## Prompt caching
 *
 * Anthropic supports marking parts of the prompt as cacheable;
 * subsequent requests pay a small fraction of the normal input
 * price on the cached part. The plugin opts the agent into this
 * automatically on the parts that stay constant within a session.
 * Cache lifetime is short (a few minutes); the agent schedules turns
 * to keep the cache warm where it can.
 *
 * ## Long context
 *
 * Claude supports 200K-token (Sonnet 4) and 1M-token (Opus 4 1M)
 * contexts. When a conversation approaches the model's context
 * limit, the agent automatically summarises the older middle of the
 * conversation to keep recent turns intact. See
 * [Conversation length](wp-pfagent/settings/) in Settings.
 */

/**
 * @pfw-doc-page    wp-pfagent/providers/openai-compatible
 * @pfw-doc-title   Generic OpenAI-compatible
 * @pfw-doc-order   30
 *
 * Anything exposing the OpenAI API shape can be added as a generic
 * "OpenAI-compatible" provider. This includes:
 *
 * - **DeepSeek**: `https://api.deepseek.com/v1`
 * - **Qwen** (Alibaba): `https://dashscope-intl.aliyuncs.com/compatible-mode/v1`
 * - **Groq**: `https://api.groq.com/openai/v1`
 * - **Together AI**: `https://api.together.xyz/v1`
 * - **Fireworks**: `https://api.fireworks.ai/inference/v1`
 * - **Cerebras**: `https://api.cerebras.ai/v1`
 * - **LM Studio** (self-hosted): `http://localhost:1234/v1`
 * - **vLLM** (self-hosted): `http://your-server:8000/v1`
 * - **Ollama**: `http://localhost:11434/v1`
 *
 * ## Adding one
 *
 * **Project Flash → Agent → Providers → Add Provider → Generic
 * OpenAI-compatible**.
 *
 * Required:
 *
 * - **Base URL**: e.g. `https://api.deepseek.com/v1`.
 * - **API key**: provider-specific. Self-hosted endpoints often
 *   accept any string or `EMPTY`.
 * - **Model identifier**: e.g. `deepseek-chat`, `llama-3.1-70b-instruct`.
 *
 * Test connection probes `POST {base_url}/chat/completions` with a
 * 1-token request. If it returns a valid choice, you're good.
 *
 * ## Known caveats
 *
 * - **Tool use varies**. Not every OpenAI-compatible endpoint
 *   implements `tools` correctly. If the agent's tool calls come
 *   back as plain text instead of structured calls, that provider
 *   doesn't support tool use. Pick a different model.
 * - **Streaming**: most do; some don't. The plugin auto-detects
 *   and falls back to non-streaming if needed.
 * - **Token counting**: some providers don't return usage stats.
 *   Budget tracking degrades to "best effort" for those.
 */
