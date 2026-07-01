<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Chat session persistence backed by the framework's `wp_pfaf_*` tables
 * (the Loop's source of truth). Each session is one row in
 * `wp_pfaf_conversations`, owned by a single WordPress user (tracked via
 * the new `owner_user_id` column). Messages live in `wp_pfaf_messages`
 * keyed by `conversation_id`.
 *
 * Pre-Sprint-C this class owned a CPT (`pfa_chat_session`) and stored
 * messages as JSON in `post_content`. That layer is now retired in favour
 * of the canonical Framework store so the UI sees exactly what the Loop
 * writes — no two-store synchronisation, no phantom-empty sessions.
 *
 * The `pfa_chat_session_can_access` filter is preserved so hosts can
 * still override per-session ownership (super-admin overrides, shared
 * sessions, etc.).
 */
final class ChatSessions
{
    /**
     * Legacy CPT slug. Kept registered so existing posts remain readable
     * by the migration helper (bin/migrate-cpt-to-pfaf.php) until the
     * operator has fully cut over.
     */
    public const POST_TYPE = 'pfa_chat_session';

    private const MAX_BODY_BYTES = 524288;
    /**
     * Match the wp_pfaf_conversations.label column width (VARCHAR(190)).
     * The DB structure is frozen; this constant defines the PHP-side
     * truncation that aligns with what the column will accept. F22 was
     * the mismatch — PHP truncated to 200, DB rejected the 200-char
     * insert with a string-too-long error → 500.
     */
    private const MAX_LABEL_LENGTH = 190;
    private const DEFAULT_PER_PAGE = 5;
    private const MAX_PER_PAGE = 100;
    private const DEFAULT_PURGE_DAYS = 7;
    private const MAX_PURGE_BATCH = 500;

    public function __construct(private readonly RateLimiter $rate_limiter)
    {
    }

    public function register(): void
    {
        // F10: the CPT is intentionally NOT registered. The migration
        // script (bin/migrate-cpt-to-pfaf.php) is the only caller that
        // ever reads `pfa_chat_session` posts; it registers the type
        // ad-hoc when invoked. Production runtime has no read or write
        // path that uses the CPT — storage moved to wp_pfaf_conversations
        // during Sprint-C. Keeping the registration at boot was dead
        // code that confused operators inspecting Tools → Post Types.
        // The POST_TYPE constant survives for the
        // `pfa_chat_session_can_access` filter signature.
    }

    public function register_routes(): void
    {
        $namespace = 'wp-pfagent/v1';

        register_rest_route($namespace, '/chat-sessions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'list_sessions'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
                'args' => [
                    'page' => ['type' => 'integer', 'required' => false],
                    'perPage' => ['type' => 'integer', 'required' => false],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create_session'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            ],
        ]);

        register_rest_route($namespace, '/chat-sessions/purge', [
            'methods' => 'POST',
            'callback' => [$this, 'purge_sessions'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            'args' => [
                'olderThanDays' => ['type' => 'integer', 'required' => false],
            ],
        ]);

        register_rest_route($namespace, '/chat-sessions/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_session'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'patch_session'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'delete_session'],
                'permission_callback' => [Capabilities::class, 'can_manage_agent'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
        ]);

        register_rest_route($namespace, '/chat-sessions/(?P<id>\d+)/messages', [
            'methods' => 'POST',
            'callback' => [$this, 'append_messages'],
            'permission_callback' => [Capabilities::class, 'can_manage_agent'],
            'args' => ['id' => ['required' => true, 'type' => 'integer']],
        ]);
    }

    public function list_sessions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('reads')) {
            return $error;
        }

        $page = max(1, (int) ($request->get_param('page') ?? 1));
        $per_page_raw = (int) ($request->get_param('perPage') ?? self::DEFAULT_PER_PAGE);
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page_raw));

        $current = get_current_user_id();
        if ($current === 0) {
            return new WP_Error('pfa_session_no_user', __('A logged-in user is required.', 'wp-pfagent'), ['status' => 401]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pfaf_conversations';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE owner_user_id = %d",
            $current
        ));

        $offset = ($page - 1) * $per_page;
        $rows = (array) $wpdb->get_results($wpdb->prepare(
            // Order by last_turn_at (the v2 stamp); fall back to created_at
            // for rows that haven't had a turn yet. last_turn_at is a
            // string column but ISO-8601 sorts correctly as text.
            "SELECT id, label, status, created_at, last_turn_at, turn_count, metadata_json
             FROM {$table}
             WHERE owner_user_id = %d
             ORDER BY (CASE WHEN last_turn_at = '' THEN created_at ELSE last_turn_at END) DESC
             LIMIT %d OFFSET %d",
            $current,
            $per_page,
            $offset
        ), ARRAY_A);

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = $this->serialize_summary_row($row);
        }

        $total_pages = max(1, (int) ceil($total / $per_page));

        return rest_ensure_response([
            'sessions' => $sessions,
            'total' => $total,
            'page' => $page,
            'perPage' => $per_page,
            'totalPages' => $total_pages,
        ]);
    }

    public function purge_sessions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('config')) {
            return $error;
        }

        $days_raw = (int) ($request->get_param('olderThanDays') ?? self::DEFAULT_PURGE_DAYS);
        $days = max(1, $days_raw);

        $current = get_current_user_id();
        if ($current === 0) {
            return new WP_Error('pfa_session_no_user', __('A logged-in user is required.', 'wp-pfagent'), ['status' => 401]);
        }

        global $wpdb;
        $convs = $wpdb->prefix . 'pfaf_conversations';
        $msgs = $wpdb->prefix . 'pfaf_messages';
        $tcs = $wpdb->prefix . 'pfaf_tool_calls';
        $traces = $wpdb->prefix . 'pfaf_traces';

        $cutoff = gmdate('c', time() - ($days * DAY_IN_SECONDS));

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$convs}
             WHERE owner_user_id = %d
               AND (CASE WHEN last_turn_at = '' THEN created_at ELSE last_turn_at END) <= %s
             ORDER BY id ASC
             LIMIT %d",
            $current,
            $cutoff,
            self::MAX_PURGE_BATCH
        ));

        $deleted = 0;
        foreach ($ids as $id) {
            $deleted += $this->delete_conversation_row((int) $id, $msgs, $tcs, $traces, $convs);
        }

        return rest_ensure_response([
            'deleted' => $deleted,
            'olderThanDays' => $days,
            'cutoff' => $cutoff,
        ]);
    }

    public function create_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('config')) {
            return $error;
        }
        if ($error = $this->guard_body_size($request)) {
            return $error;
        }

        $params = $this->json_params($request);
        $label = $this->sanitize_label((string) ($params['label'] ?? ''));
        $workflow_id = isset($params['workflowId']) ? (int) $params['workflowId'] : 0;

        $current = get_current_user_id();
        if ($current === 0) {
            return new WP_Error('pfa_session_no_user', __('A logged-in user is required.', 'wp-pfagent'), ['status' => 401]);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pfaf_conversations';
        $metadata = ['ownerUserId' => $current];
        if ($workflow_id > 0) {
            $metadata['workflowId'] = $workflow_id;
        }

        $ok = $wpdb->insert($table, [
            'owner_user_id' => $current,
            'label' => $label !== '' ? $label : sprintf(
                /* translators: %s: UTC timestamp used as default chat session title */
                __('Session %s', 'wp-pfagent'),
                gmdate('Y-m-d H:i')
            ),
            'status' => 'open',
            'created_at' => gmdate('c'),
            'last_turn_at' => '',
            'turn_count' => 0,
            'metadata_json' => (string) wp_json_encode($metadata),
        ]);

        if ($ok === false) {
            return new WP_Error('pfa_session_create_failed', __('Failed to create the chat session.', 'wp-pfagent'), ['status' => 500]);
        }

        $id = (int) $wpdb->insert_id;
        $payload = $this->serialize_full($id);
        if ($payload === null) {
            return new WP_Error('pfa_session_not_found', __('Chat session not found after creation.', 'wp-pfagent'), ['status' => 500]);
        }

        return new WP_REST_Response($payload, 201);
    }

    public function get_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('reads')) {
            return $error;
        }
        $session_id = (int) $request['id'];
        if ($error = $this->guard_access($session_id)) {
            return $error;
        }

        $payload = $this->serialize_full($session_id);
        if ($payload === null) {
            return new WP_Error('pfa_session_not_found', __('Chat session not found.', 'wp-pfagent'), ['status' => 404]);
        }

        return rest_ensure_response($payload);
    }

    public function patch_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('config')) {
            return $error;
        }
        if ($error = $this->guard_body_size($request)) {
            return $error;
        }
        $session_id = (int) $request['id'];
        if ($error = $this->guard_access($session_id)) {
            return $error;
        }

        $params = $this->json_params($request);
        global $wpdb;
        $table = $wpdb->prefix . 'pfaf_conversations';
        $update = [];

        if (array_key_exists('label', $params)) {
            $label = $this->sanitize_label((string) $params['label']);
            if ($label === '') {
                return new WP_Error('pfa_session_label_required', __('label cannot be empty.', 'wp-pfagent'), ['status' => 400]);
            }
            $update['label'] = $label;
        }

        if (array_key_exists('workflowId', $params)) {
            $metadata = $this->load_metadata($session_id);
            $workflow_id = (int) $params['workflowId'];
            if ($workflow_id <= 0) {
                unset($metadata['workflowId']);
            } else {
                $metadata['workflowId'] = $workflow_id;
            }
            $update['metadata_json'] = (string) wp_json_encode($metadata);
        }

        if ($update !== []) {
            $wpdb->update($table, $update, ['id' => $session_id]);
        }

        $payload = $this->serialize_full($session_id);
        if ($payload === null) {
            return new WP_Error('pfa_session_not_found', __('Chat session not found.', 'wp-pfagent'), ['status' => 404]);
        }

        return rest_ensure_response($payload);
    }

    public function delete_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('config')) {
            return $error;
        }
        $session_id = (int) $request['id'];
        if ($error = $this->guard_access($session_id)) {
            return $error;
        }

        global $wpdb;
        $convs = $wpdb->prefix . 'pfaf_conversations';
        $msgs = $wpdb->prefix . 'pfaf_messages';
        $tcs = $wpdb->prefix . 'pfaf_tool_calls';
        $traces = $wpdb->prefix . 'pfaf_traces';

        $deleted = $this->delete_conversation_row($session_id, $msgs, $tcs, $traces, $convs);
        if ($deleted === 0) {
            return new WP_Error('pfa_session_delete_failed', __('Failed to delete the chat session.', 'wp-pfagent'), ['status' => 500]);
        }

        return rest_ensure_response(['deleted' => true, 'id' => $session_id]);
    }

    /**
     * No-op endpoint kept for backward compatibility: in the v2 path the
     * Framework Loop already persists every user / assistant / tool message
     * into wp_pfaf_messages. The frontend's legacy `persistTurnToSession`
     * call still hits this route — we accept it and return the current
     * session so the UI doesn't have to special-case the cutover.
     */
    public function append_messages(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if ($error = $this->rate('agent_turn')) {
            return $error;
        }
        if ($error = $this->guard_body_size($request)) {
            return $error;
        }
        $session_id = (int) $request['id'];
        if ($error = $this->guard_access($session_id)) {
            return $error;
        }

        // F23: validate role even though this endpoint is a no-op shim.
        // The Loop is the real writer, but accepting `role: '<script>'`
        // from the wire is a contract leak that suggests we'd persist
        // arbitrary roles if we ever started doing so. Reject early
        // when an explicit role is sent that isn't in the canonical
        // enum. Absent role passes through (matches the legacy shape
        // the SPA's persistTurnToSession may send without role).
        $allowed_roles = ['system', 'user', 'assistant', 'tool'];
        $params = $this->json_params($request);
        if (array_key_exists('role', $params)) {
            $role = (string) $params['role'];
            if ($role !== '' && !in_array($role, $allowed_roles, true)) {
                return new WP_Error(
                    'pfa_session_role_invalid',
                    sprintf(
                        /* translators: 1: rejected role value, 2: comma-separated allowed values */
                        __('role "%1$s" is not in the allowed enum (%2$s).', 'wp-pfagent'),
                        $role,
                        implode(', ', $allowed_roles)
                    ),
                    ['status' => 400, 'role' => $role, 'allowed' => $allowed_roles]
                );
            }
        }

        $payload = $this->serialize_full($session_id);
        if ($payload === null) {
            return new WP_Error('pfa_session_not_found', __('Chat session not found.', 'wp-pfagent'), ['status' => 404]);
        }

        return rest_ensure_response($payload);
    }

    /**
     * Public helper: redact known API key shapes from arbitrary text.
     * Kept on the class because callers across the plugin import it.
     */
    public static function redact_secrets(string $text): string
    {
        $patterns = [
            '/sk-ant-api\d{2}-[A-Za-z0-9_\-]{20,}/i',
            '/sk-[A-Za-z0-9]{20,}/',
            '/AIza[A-Za-z0-9_\-]{30,}/',
            '/glpat-[A-Za-z0-9_\-]{20,}/',
            '/ghp_[A-Za-z0-9]{30,}/',
            '/xox[baprs]-[A-Za-z0-9-]{10,}/',
            '/Bearer\s+[A-Za-z0-9._\-]{20,}/i',
        ];

        return (string) preg_replace($patterns, '[REDACTED]', $text);
    }

    private function rate(string $bucket): ?WP_Error
    {
        $r = $this->rate_limiter->consume($bucket);
        return $r instanceof WP_Error ? $r : null;
    }

    private function guard_body_size(WP_REST_Request $request): ?WP_Error
    {
        $size = strlen((string) $request->get_body());
        if ($size > self::MAX_BODY_BYTES) {
            return new WP_Error(
                'pfa_payload_too_large',
                sprintf(
                    /* translators: 1: maximum bytes allowed, 2: actual bytes received */
                    __('Request body exceeds %1$d bytes (got %2$d).', 'wp-pfagent'),
                    self::MAX_BODY_BYTES,
                    $size
                ),
                ['status' => 413, 'maxBytes' => self::MAX_BODY_BYTES, 'received' => $size]
            );
        }
        return null;
    }

    private function guard_access(int $session_id): ?WP_Error
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pfaf_conversations';
        $owner = $wpdb->get_var($wpdb->prepare(
            "SELECT owner_user_id FROM {$table} WHERE id = %d",
            $session_id
        ));
        if ($owner === null) {
            return new WP_Error('pfa_session_not_found', __('Chat session not found.', 'wp-pfagent'), ['status' => 404]);
        }

        $current = get_current_user_id();
        $is_owner = (int) $owner === $current;
        $allowed = (bool) apply_filters('pfa_chat_session_can_access', $is_owner, $session_id, $current);
        if (!$allowed) {
            return new WP_Error('pfa_session_forbidden', __('You do not have access to this chat session.', 'wp-pfagent'), ['status' => 403]);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row Row from wp_pfaf_conversations
     * @return array<string, mixed>
     */
    private function serialize_summary_row(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $last_turn = (string) ($row['last_turn_at'] ?? '');
        $created = (string) ($row['created_at'] ?? '');
        return [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'authorId' => (int) ($metadata['ownerUserId'] ?? 0),
            'workflowId' => (int) ($metadata['workflowId'] ?? 0),
            'turnCount' => (int) ($row['turn_count'] ?? 0),
            'lastTurnAt' => $last_turn,
            'createdAt' => $created,
            'updatedAt' => $last_turn !== '' ? $last_turn : $created,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serialize_full(int $session_id): ?array
    {
        global $wpdb;
        $convs = $wpdb->prefix . 'pfaf_conversations';
        $msgs = $wpdb->prefix . 'pfaf_messages';

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, label, status, created_at, last_turn_at, turn_count, metadata_json
             FROM {$convs} WHERE id = %d",
            $session_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }

        $summary = $this->serialize_summary_row($row);

        $message_rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT role, content_json, tool_call_id, created_at FROM {$msgs}
             WHERE conversation_id = %d
             ORDER BY ordinal ASC",
            $session_id
        ), ARRAY_A);

        $messages = [];
        foreach ($message_rows as $m) {
            $role = (string) ($m['role'] ?? '');
            if ($role === 'tool') {
                // The UI's chat transcript does not render tool result
                // messages (they're surfaced via the execution timeline
                // attached to the previous assistant turn). Skip here to
                // keep the visible transcript matching what the v1 flow
                // showed.
                continue;
            }
            $content_raw = $m['content_json'] ?? '';
            $content = is_string($content_raw) ? (string) (json_decode($content_raw, true) ?? '') : '';
            // Skip every empty bubble — including assistant rows that
            // were persisted alongside a tool_calls payload but carried
            // no natural-language text. Those mid-loop "narration-less"
            // assistant turns are an implementation detail of the multi-
            // round Loop; surfacing them on rehydration produces a
            // string of empty robot bubbles between the user message and
            // the final reply (exact symptom the operator reported on
            // reopening a finished conversation).
            if ($content === '') {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $content,
                'at' => (string) ($m['created_at'] ?? ''),
            ];
        }

        $summary['messages'] = $messages;
        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function load_metadata(int $session_id): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pfaf_conversations';
        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT metadata_json FROM {$table} WHERE id = %d",
            $session_id
        ));
        $metadata = json_decode((string) $raw, true);
        return is_array($metadata) ? $metadata : [];
    }

    private function delete_conversation_row(int $id, string $msgs, string $tcs, string $traces, string $convs): int
    {
        global $wpdb;
        // Cascade delete children first; the conversations table has no
        // FK constraints so the order is just for tidiness on partial
        // failure.
        $wpdb->delete($msgs, ['conversation_id' => $id]);
        $wpdb->delete($tcs, ['conversation_id' => $id]);
        $wpdb->delete($traces, ['conversation_id' => $id]);
        return (int) $wpdb->delete($convs, ['id' => $id]);
    }

    private function sanitize_label(string $label): string
    {
        $label = sanitize_text_field($label);
        if (function_exists('mb_substr')) {
            return mb_substr($label, 0, self::MAX_LABEL_LENGTH);
        }
        return substr($label, 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * @return array<string, mixed>
     */
    private function json_params(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        return is_array($params) ? $params : [];
    }
}
