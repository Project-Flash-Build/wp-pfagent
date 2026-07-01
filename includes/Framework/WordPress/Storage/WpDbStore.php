<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\Framework\WordPress\Storage;

use ProjectFlash\Agent\Framework\Conversation;
use ProjectFlash\Agent\Framework\Message;
use ProjectFlash\Agent\Framework\Storage\Store;

/**
 * Store implementation backed by WordPress's $wpdb. Tables are created with
 * the standard `{$wpdb->prefix}pfaf_*` naming and use MariaDB / MySQL
 * syntax. The framework's core never imports this class — it's plugged in
 * by the WordPress host at bootstrap.
 *
 * Owns four tables — pfaf_conversations, pfaf_messages, pfaf_tool_calls,
 * pfaf_traces. The last is distinct from the agent-side
 * `{prefix}pfa_trace_log` owned by {@see \ProjectFlash\Agent\TraceLogger}:
 * pfaf_traces records per-round Loop telemetry (system fingerprints, tool
 * call counts, finishReason); pfa_trace_log records cross-cutting agent
 * operations (REST turns, workflow API calls, rate-limit trips). Both have
 * "trace" in the name, the schemas do NOT overlap (F6 disambiguation; see
 * docs/architecture-notes.md).
 *
 * Why not subclass PdoStore: $wpdb uses mysqli underneath and provides its
 * own quoting / charset handling that's correct for WordPress installs.
 * Bypassing it would be a footgun (wp_slash mismatches, charset drift on
 * old multisite installs, etc.). The duplication with PdoStore is the
 * deliberate cost of using each backend's idiomatic primitives.
 */
final class WpDbStore implements Store
{
    private string $prefix;

    public function __construct(private readonly \wpdb $wpdb)
    {
        $this->prefix = $this->wpdb->prefix . 'pfaf_';
    }

    public function migrate(): void
    {
        $charset = $this->wpdb->get_charset_collate();
        $tables = [
            "{$this->prefix}conversations" => "
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                label         VARCHAR(190) NOT NULL DEFAULT '',
                status        VARCHAR(32)  NOT NULL DEFAULT 'open',
                created_at    VARCHAR(32)  NOT NULL,
                last_turn_at  VARCHAR(32)  NOT NULL DEFAULT '',
                turn_count    INT UNSIGNED NOT NULL DEFAULT 0,
                metadata_json LONGTEXT     NOT NULL,
                PRIMARY KEY (id),
                KEY status_idx (status),
                KEY label_idx (label),
                KEY owner_idx (owner_user_id)
            ",
            "{$this->prefix}messages" => "
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                ordinal         INT UNSIGNED NOT NULL,
                role            VARCHAR(16) NOT NULL,
                content_json    LONGTEXT NOT NULL,
                tool_calls_json LONGTEXT NOT NULL,
                tool_call_id    VARCHAR(190) NOT NULL DEFAULT '',
                reasoning       LONGTEXT NOT NULL,
                finish_reason   VARCHAR(32) NOT NULL DEFAULT '',
                tokens_in       INT UNSIGNED NOT NULL DEFAULT 0,
                tokens_out      INT UNSIGNED NOT NULL DEFAULT 0,
                cost_micros     BIGINT NOT NULL DEFAULT 0,
                created_at      VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY msg_ordinal_idx (conversation_id, ordinal),
                KEY msg_role_idx (conversation_id, role)
            ",
            "{$this->prefix}tool_calls" => "
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id   BIGINT UNSIGNED NOT NULL,
                message_ordinal   INT UNSIGNED NOT NULL,
                tool_call_id      VARCHAR(190) NOT NULL,
                tool_name         VARCHAR(190) NOT NULL,
                arguments_json    LONGTEXT NOT NULL,
                side_effect       TINYINT(1) NOT NULL DEFAULT 0,
                status            VARCHAR(32) NOT NULL,
                result_json       LONGTEXT NOT NULL,
                state_after_json  LONGTEXT NOT NULL,
                error_code        VARCHAR(190) NOT NULL DEFAULT '',
                error_message     LONGTEXT NOT NULL,
                fingerprint       VARCHAR(64) NOT NULL DEFAULT '',
                duration_ms       INT UNSIGNED NOT NULL DEFAULT 0,
                started_at        VARCHAR(32) NOT NULL DEFAULT '',
                ended_at          VARCHAR(32) NOT NULL DEFAULT '',
                PRIMARY KEY (id),
                KEY tc_conv_idx (conversation_id),
                KEY tc_fp_idx   (conversation_id, fingerprint)
            ",
            "{$this->prefix}traces" => "
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id    BIGINT UNSIGNED NOT NULL,
                turn               INT UNSIGNED NOT NULL DEFAULT 0,
                round              INT UNSIGNED NOT NULL DEFAULT 0,
                kind               VARCHAR(64) NOT NULL,
                payload_json       LONGTEXT NOT NULL,
                system_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
                created_at         VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                KEY trace_conv_idx (conversation_id, turn, round),
                KEY trace_kind_idx (kind)
            ",
        ];
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach ($tables as $name => $columns) {
            $sql = "CREATE TABLE {$name} (\n{$columns}\n) {$charset};";
            dbDelta($sql);
        }

        // One-off cleanup: earlier schemas carried provider_id + model on the
        // conversations row, which let stale values survive across turns and
        // override whatever model the operator had active in the wizard. The
        // active model is now a global selection living in the credential
        // store + injected from the frontend on every turn; conversations
        // hold no model state. Drop the legacy columns if they still exist.
        $convs_table = $this->prefix . 'conversations';
        $columns = (array) $this->wpdb->get_col("SHOW COLUMNS FROM {$convs_table}", 0);
        if (in_array('provider_id', $columns, true)) {
            $this->wpdb->query("ALTER TABLE {$convs_table} DROP COLUMN provider_id");
        }
        if (in_array('model', $columns, true)) {
            $this->wpdb->query("ALTER TABLE {$convs_table} DROP COLUMN model");
        }
    }

    public function createConversation(string $label, array $metadata = []): int
    {
        $this->wpdb->insert($this->prefix . 'conversations', [
            // owner_user_id is a WordPress concept the framework core knows
            // nothing about, so the host adapter sets it here: prefer an
            // explicit metadata.ownerUserId (the create_session REST path
            // passes it), otherwise the current request's user. Without this
            // the Loop's autocreate path (Loop::run with conversationId=null)
            // left the row at the schema DEFAULT 0, which made the
            // conversation invisible to ChatSessions::list_sessions() (it
            // filters WHERE owner_user_id = current user) and unreachable by
            // live-polling — orphaned the moment it was created.
            'owner_user_id' => (int) ($metadata['ownerUserId'] ?? get_current_user_id()),
            'label' => $label,
            'status' => 'open',
            'created_at' => gmdate('c'),
            'last_turn_at' => '',
            'turn_count' => 0,
            'metadata_json' => (string) wp_json_encode($metadata),
        ]);
        return (int) $this->wpdb->insert_id;
    }

    public function loadConversation(int $id): ?Conversation
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->prefix}conversations WHERE id = %d", $id),
            ARRAY_A,
        );
        if (!is_array($row)) {
            return null;
        }
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->prefix}messages WHERE conversation_id = %d ORDER BY ordinal ASC",
                $id,
            ),
            ARRAY_A,
        );
        $messages = [];
        foreach ((array) $rows as $r) {
            $content = $this->decodeContent((string) $r['content_json']);
            $messages[] = new Message(
                role: (string) $r['role'],
                content: $content === null ? null : (string) $content,
                toolCalls: (array) (json_decode((string) $r['tool_calls_json'], true) ?? []),
                toolCallId: (string) ($r['tool_call_id'] ?? ''),
                reasoning: (string) ($r['reasoning'] ?? ''),
                finishReason: (string) ($r['finish_reason'] ?? ''),
                tokensIn: (int) ($r['tokens_in'] ?? 0),
                tokensOut: (int) ($r['tokens_out'] ?? 0),
            );
        }
        $metadata = json_decode((string) $row['metadata_json'], true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        return new Conversation(
            id: (int) $row['id'],
            label: (string) $row['label'],
            status: (string) $row['status'],
            messages: $messages,
            turnCount: (int) $row['turn_count'],
            metadata: $metadata,
        );
    }

    public function appendMessage(int $conversationId, Message $message): int
    {
        $next = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COALESCE(MAX(ordinal), 0) + 1 FROM {$this->prefix}messages WHERE conversation_id = %d",
                $conversationId,
            ),
        );
        $this->wpdb->insert($this->prefix . 'messages', [
            'conversation_id' => $conversationId,
            'ordinal' => $next,
            'role' => $message->role,
            'content_json' => (string) wp_json_encode($message->content),
            'tool_calls_json' => (string) wp_json_encode($message->toolCalls),
            'tool_call_id' => $message->toolCallId,
            'reasoning' => $message->reasoning,
            'finish_reason' => $message->finishReason,
            'tokens_in' => $message->tokensIn,
            'tokens_out' => $message->tokensOut,
            'cost_micros' => 0,
            'created_at' => gmdate('c'),
        ]);
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->prefix}conversations SET turn_count = turn_count + 1, last_turn_at = %s WHERE id = %d",
                gmdate('c'),
                $conversationId,
            ),
        );
        return $next;
    }

    public function updateConversationMetadata(int $conversationId, array $partial): void
    {
        $existing = $this->wpdb->get_var(
            $this->wpdb->prepare("SELECT metadata_json FROM {$this->prefix}conversations WHERE id = %d", $conversationId),
        );
        $current = is_string($existing) ? (array) (json_decode($existing, true) ?? []) : [];
        $merged = array_replace_recursive($current, $partial);
        $this->wpdb->update(
            $this->prefix . 'conversations',
            ['metadata_json' => (string) wp_json_encode($merged)],
            ['id' => $conversationId],
        );
    }

    public function closeConversation(int $conversationId, string $status = 'closed'): void
    {
        $this->wpdb->update($this->prefix . 'conversations', ['status' => $status], ['id' => $conversationId]);
    }

    public function logToolCall(
        int $conversationId,
        int $messageOrdinal,
        string $toolCallId,
        string $toolName,
        array $arguments,
        bool $sideEffect,
        string $status,
        mixed $result,
        mixed $stateAfter,
        string $errorCode,
        string $errorMessage,
        string $fingerprint,
        int $durationMs,
        string $startedAt,
        string $endedAt,
    ): int {
        $this->wpdb->insert($this->prefix . 'tool_calls', [
            'conversation_id' => $conversationId,
            'message_ordinal' => $messageOrdinal,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'arguments_json' => (string) wp_json_encode($arguments),
            'side_effect' => $sideEffect ? 1 : 0,
            'status' => $status,
            'result_json' => (string) wp_json_encode($result),
            'state_after_json' => (string) wp_json_encode($stateAfter),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'fingerprint' => $fingerprint,
            'duration_ms' => $durationMs,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);
        return (int) $this->wpdb->insert_id;
    }

    public function findIdempotentResult(int $conversationId, string $fingerprint): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT result_json, state_after_json FROM {$this->prefix}tool_calls
                 WHERE conversation_id = %d AND fingerprint = %s AND status = 'ok'
                 ORDER BY id DESC LIMIT 1",
                $conversationId,
                $fingerprint,
            ),
            ARRAY_A,
        );
        if (!is_array($row)) {
            return null;
        }
        return [
            'result' => json_decode((string) $row['result_json'], true),
            'stateAfter' => json_decode((string) $row['state_after_json'], true),
        ];
    }

    public function countFingerprint(int $conversationId, string $fingerprint, int $sinceOrdinal = 0): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->prefix}tool_calls
                 WHERE conversation_id = %d AND fingerprint = %s AND message_ordinal >= %d",
                $conversationId,
                $fingerprint,
                $sinceOrdinal,
            ),
        );
    }

    public function countSuccessfulSideEffects(int $conversationId): int
    {
        // Authoritative signal: any successful (`status = 'ok'`) row
        // in pfaf_tool_calls whose tool_name is on the canonical
        // side-effect list. Used instead of the `side_effect = 1`
        // flag because that flag suffered historical drift — some
        // tools that DO persist state (write_file, edit_file,
        // create_variable) were registered with sideEffect:false in
        // agent-tools.json and their rows ended up flag=0, which
        // false-tripped the honesty rewrite in Loop::driveLoop
        // ("you claim to have created X but no side-effect ran"
        // fired even when the agent had built exactly what it
        // claimed). The tool_name approach is immune to that drift
        // and matches the operator's mental model of "did the LLM
        // change anything?".
        $names = self::SIDE_EFFECT_TOOL_NAMES;
        $placeholders = implode(',', array_fill(0, count($names), '%s'));
        $sql = "SELECT COUNT(*) FROM {$this->prefix}tool_calls
                WHERE conversation_id = %d AND status = 'ok'
                  AND tool_name IN ({$placeholders})";
        $params = array_merge([$conversationId], $names);
        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, ...$params));
    }

    /**
     * List of tools that persist state — used by the honesty check in
     * Loop::driveLoop. This MUST mirror the canonical source of truth,
     * which is `config/agent-tools.json`'s `sideEffect:true` entries: the
     * same flag the confirmation gate keys off in Loop::planToolCall. They
     * are kept in agreement so a tool that asks for confirmation also counts
     * toward the honesty cross-check (and vice-versa). write_file / edit_file
     * / create_variable were previously sideEffect:false in the contract,
     * which silently bypassed the gate for the most common create/edit
     * actions; they are now sideEffect:true there and listed here too.
     * `pfm_delete` is forward-looking (no such tool is registered yet); it
     * stays harmless because it simply never matches a logged tool_name.
     */
    private const SIDE_EFFECT_TOOL_NAMES = [
        'pfm_apply',
        'pfm_delete',
        'write_file',
        'edit_file',
        'move_file',
        'delete_file',
        'create_variable',
        'activate_workflow',
    ];

    public function logTrace(int $conversationId, int $turn, int $round, string $kind, array $payload, string $systemFingerprint = ''): void
    {
        $this->wpdb->insert($this->prefix . 'traces', [
            'conversation_id' => $conversationId,
            'turn' => $turn,
            'round' => $round,
            'kind' => $kind,
            'payload_json' => (string) wp_json_encode($payload),
            'system_fingerprint' => $systemFingerprint,
            'created_at' => gmdate('c'),
        ]);
    }

    private function decodeContent(string $json): mixed
    {
        if ($json === 'null' || $json === '') {
            return null;
        }
        return json_decode($json, true);
    }
}
