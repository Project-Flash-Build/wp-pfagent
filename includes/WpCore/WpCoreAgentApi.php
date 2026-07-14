<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\WpCore;

use WP_Error;

/**
 * Self-contained cross-cutting WordPress-core agent surface.
 *
 * This module is the LEAN v1 "free hook" capability: it lets the single PFA
 * agent act directly on WordPress core (posts, taxonomies, media, users,
 * comments, site settings, navigation) with the operator's own LLM, gated by
 * the same human-approval loop as every other side-effect tool.
 *
 * DESIGN CONTRACT (operator, 2026-07-13):
 *  - ONE agent, ONE chat. These tools plug into the SAME Loop / gateway /
 *    tool registry / approval gate as the suite tools. That wiring is shared
 *    and correct — there is only one chat.
 *  - The IMPLEMENTATION here is a SELF-CONTAINED module. It shares NO code
 *    with the suite tools (wp-pfmanagement / wp-pfworkflow bridges). It never
 *    reaches into `projectflash_management_agent_api`, the VFS bridge, or any
 *    PFM/PFW class. It talks ONLY to WordPress core APIs.
 *  - It works with the Setyenv suite ABSENT — nothing here depends on PFM/PFW.
 *
 * Resolved as a service via `apply_filters('pfa_wpcore_agent_api', null)` and
 * called by FilterBridgeTool: each public `wp_*` method receives the tool
 * arguments array and returns a JSON-serialisable array (or WP_Error, which
 * the bridge surfaces to the LLM as a recoverable error).
 *
 * Every write method re-checks the caller's per-object capability itself; the
 * tool registry's coarse gate is a first filter, not the last word. Option
 * writes are constrained to an explicit ALLOW-LIST of basic site settings —
 * PFM/PFW/PFA options are excluded by construction (they are simply not on the
 * list), so the agent can never touch suite configuration through here.
 */
final class WpCoreAgentApi
{
    /** Read-allowed site options (basic, content/appearance only). */
    private const OPTION_READ_ALLOWLIST = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'date_format', 'time_format', 'start_of_week', 'posts_per_page',
        'default_category', 'default_comment_status', 'default_ping_status',
        'permalink_structure', 'show_on_front', 'page_on_front', 'page_for_posts',
        'blog_public', 'WPLANG',
    ];

    /** Write-allowed site options (conservative subset of the read list). */
    private const OPTION_WRITE_ALLOWLIST = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset',
        'date_format', 'time_format', 'start_of_week', 'posts_per_page',
        'default_comment_status', 'default_ping_status', 'blog_public',
        'show_on_front', 'page_on_front', 'page_for_posts',
    ];

    // ---------------------------------------------------------------- posts

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_list(array $args)
    {
        $post_type = $this->sanitize_post_type($args['post_type'] ?? 'post');
        if ($post_type instanceof WP_Error) {
            return $post_type;
        }
        $per_page = $this->clamp_int($args['per_page'] ?? 20, 1, 100);
        $paged = $this->clamp_int($args['page'] ?? 1, 1, 100000);

        $query_args = [
            'post_type'      => $post_type,
            'post_status'    => $this->sanitize_status_list($args['status'] ?? 'any'),
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => in_array(($args['orderby'] ?? 'date'), ['date', 'title', 'modified', 'ID', 'menu_order'], true) ? (string) $args['orderby'] : 'date',
            'order'          => (strtoupper((string) ($args['order'] ?? 'DESC')) === 'ASC') ? 'ASC' : 'DESC',
            'no_found_rows'  => false,
        ];
        if (isset($args['search']) && is_string($args['search']) && $args['search'] !== '') {
            $query_args['s'] = sanitize_text_field((string) $args['search']);
        }
        if (isset($args['author'])) {
            $query_args['author'] = $this->clamp_int($args['author'], 1, PHP_INT_MAX);
        }

        $query = new \WP_Query($query_args);
        $items = [];
        foreach ($query->posts as $post) {
            if (!current_user_can('edit_post', $post->ID) && $post->post_status !== 'publish') {
                continue;
            }
            $items[] = $this->post_summary($post);
        }

        return [
            'items'      => $items,
            'total'      => (int) $query->found_posts,
            'page'       => $paged,
            'perPage'    => $per_page,
            'totalPages' => (int) $query->max_num_pages,
            'postType'   => $post_type,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_get(array $args)
    {
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID) && $post->post_status !== 'publish') {
            return new WP_Error('wp_forbidden', __('You cannot read this content.', 'wp-pfagent'), ['status' => 403]);
        }

        $data = $this->post_summary($post);
        $data['content'] = (string) $post->post_content;
        $data['terms'] = $this->post_terms($post);
        $data['meta'] = $this->public_meta($post->ID);

        return $data;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_create(array $args)
    {
        $post_type = $this->sanitize_post_type($args['post_type'] ?? 'post');
        if ($post_type instanceof WP_Error) {
            return $post_type;
        }
        $type_object = get_post_type_object($post_type);
        if (!$type_object || !current_user_can($type_object->cap->create_posts)) {
            return new WP_Error('wp_forbidden', __('You cannot create this content type.', 'wp-pfagent'), ['status' => 403]);
        }

        $title = sanitize_text_field((string) ($args['title'] ?? ''));
        if ($title === '' && (string) ($args['content'] ?? '') === '') {
            return new WP_Error('wp_invalid_args', __('A title or content is required.', 'wp-pfagent'), ['status' => 400]);
        }

        $status = $this->sanitize_single_status($args['status'] ?? 'draft');
        if ($status === 'publish' && !current_user_can($type_object->cap->publish_posts)) {
            $status = 'pending';
        }

        $postarr = [
            'post_type'    => $post_type,
            'post_title'   => $title,
            'post_content' => wp_kses_post((string) ($args['content'] ?? '')),
            'post_excerpt' => sanitize_text_field((string) ($args['excerpt'] ?? '')),
            'post_status'  => $status,
        ];
        if (isset($args['author'])) {
            $postarr['post_author'] = $this->clamp_int($args['author'], 1, PHP_INT_MAX);
        }

        $id = wp_insert_post($postarr, true);
        if ($id instanceof WP_Error) {
            return $id;
        }

        $post = get_post((int) $id);

        return [
            'created'  => true,
            'post'     => $this->post_summary($post),
            'editLink' => html_entity_decode((string) get_edit_post_link((int) $id, 'raw')),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_update(array $args)
    {
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_forbidden', __('You cannot edit this content.', 'wp-pfagent'), ['status' => 403]);
        }

        $postarr = ['ID' => $post->ID];
        if (array_key_exists('title', $args)) {
            $postarr['post_title'] = sanitize_text_field((string) $args['title']);
        }
        if (array_key_exists('content', $args)) {
            $postarr['post_content'] = wp_kses_post((string) $args['content']);
        }
        if (array_key_exists('excerpt', $args)) {
            $postarr['post_excerpt'] = sanitize_text_field((string) $args['excerpt']);
        }
        if (array_key_exists('status', $args)) {
            $status = $this->sanitize_single_status($args['status']);
            $type_object = get_post_type_object($post->post_type);
            if ($status === 'publish' && $type_object && !current_user_can($type_object->cap->publish_posts)) {
                return new WP_Error('wp_forbidden', __('You cannot publish this content.', 'wp-pfagent'), ['status' => 403]);
            }
            $postarr['post_status'] = $status;
        }
        if (count($postarr) === 1) {
            return new WP_Error('wp_invalid_args', __('No updatable fields were provided.', 'wp-pfagent'), ['status' => 400]);
        }

        $result = wp_update_post($postarr, true);
        if ($result instanceof WP_Error) {
            return $result;
        }

        return ['updated' => true, 'post' => $this->post_summary(get_post($post->ID))];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_trash(array $args)
    {
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('delete_post', $post->ID)) {
            return new WP_Error('wp_forbidden', __('You cannot trash this content.', 'wp-pfagent'), ['status' => 403]);
        }
        $trashed = wp_trash_post($post->ID);
        if (!$trashed) {
            return new WP_Error('wp_trash_failed', __('The content could not be moved to trash.', 'wp-pfagent'), ['status' => 500]);
        }

        return ['trashed' => true, 'id' => $post->ID, 'status' => get_post_status($post->ID)];
    }

    // ------------------------------------------------------------ taxonomies

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_taxonomy_list(array $args)
    {
        $taxonomy = isset($args['taxonomy']) ? sanitize_key((string) $args['taxonomy']) : '';
        if ($taxonomy !== '') {
            if (!taxonomy_exists($taxonomy)) {
                return new WP_Error('wp_unknown_taxonomy', __('That taxonomy does not exist.', 'wp-pfagent'), ['status' => 404]);
            }
            $terms = get_terms([
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'number'     => $this->clamp_int($args['per_page'] ?? 100, 1, 500),
            ]);
            $out = [];
            foreach ((is_array($terms) ? $terms : []) as $term) {
                $out[] = ['id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count, 'parent' => (int) $term->parent];
            }
            return ['taxonomy' => $taxonomy, 'terms' => $out];
        }

        $taxonomies = get_taxonomies(['public' => true], 'objects');
        $out = [];
        foreach ($taxonomies as $tax) {
            $out[] = [
                'name'       => $tax->name,
                'label'      => $tax->label,
                'hierarchical' => (bool) $tax->hierarchical,
                'postTypes'  => array_values((array) $tax->object_type),
            ];
        }
        return ['taxonomies' => $out];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_term_create(array $args)
    {
        $taxonomy = sanitize_key((string) ($args['taxonomy'] ?? ''));
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return new WP_Error('wp_unknown_taxonomy', __('A valid taxonomy is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $tax_object = get_taxonomy($taxonomy);
        if (!$tax_object || !current_user_can($tax_object->cap->manage_terms)) {
            return new WP_Error('wp_forbidden', __('You cannot manage terms in this taxonomy.', 'wp-pfagent'), ['status' => 403]);
        }
        $name = sanitize_text_field((string) ($args['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('wp_invalid_args', __('A term name is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $termarr = [];
        if (isset($args['description'])) {
            $termarr['description'] = sanitize_text_field((string) $args['description']);
        }
        if (isset($args['parent'])) {
            $termarr['parent'] = $this->clamp_int($args['parent'], 0, PHP_INT_MAX);
        }
        $result = wp_insert_term($name, $taxonomy, $termarr);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['created' => true, 'termId' => (int) ($result['term_id'] ?? 0), 'taxonomy' => $taxonomy, 'name' => $name];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_term_assign(array $args)
    {
        $post = $this->resolve_post(['id' => $args['post_id'] ?? null]);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_forbidden', __('You cannot edit this content.', 'wp-pfagent'), ['status' => 403]);
        }
        $taxonomy = sanitize_key((string) ($args['taxonomy'] ?? ''));
        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            return new WP_Error('wp_unknown_taxonomy', __('A valid taxonomy is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $terms = $args['terms'] ?? [];
        if (!is_array($terms) || $terms === []) {
            return new WP_Error('wp_invalid_args', __('A non-empty list of terms is required.', 'wp-pfagent'), ['status' => 400]);
        }
        // Accept term ids (int) or names/slugs (string). get_terms handles both.
        $normalized = [];
        foreach ($terms as $t) {
            if (is_int($t) || (is_string($t) && ctype_digit($t))) {
                $normalized[] = (int) $t;
            } elseif (is_string($t) && $t !== '') {
                $normalized[] = sanitize_text_field($t);
            }
        }
        $append = (bool) ($args['append'] ?? false);
        $result = wp_set_object_terms($post->ID, $normalized, $taxonomy, $append);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['assigned' => true, 'postId' => $post->ID, 'taxonomy' => $taxonomy, 'termIds' => array_map('intval', (array) $result)];
    }

    // ----------------------------------------------------------------- media

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_media_list(array $args)
    {
        if (!current_user_can('upload_files')) {
            return new WP_Error('wp_forbidden', __('You cannot browse the media library.', 'wp-pfagent'), ['status' => 403]);
        }
        $per_page = $this->clamp_int($args['per_page'] ?? 20, 1, 100);
        $paged = $this->clamp_int($args['page'] ?? 1, 1, 100000);
        $query_args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
        ];
        if (isset($args['search']) && is_string($args['search']) && $args['search'] !== '') {
            $query_args['s'] = sanitize_text_field((string) $args['search']);
        }
        if (isset($args['mime']) && is_string($args['mime']) && $args['mime'] !== '') {
            $query_args['post_mime_type'] = sanitize_text_field((string) $args['mime']);
        }
        $query = new \WP_Query($query_args);
        $items = [];
        foreach ($query->posts as $post) {
            $items[] = $this->attachment_summary($post);
        }
        return [
            'items'      => $items,
            'total'      => (int) $query->found_posts,
            'page'       => $paged,
            'perPage'    => $per_page,
            'totalPages' => (int) $query->max_num_pages,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_media_get(array $args)
    {
        if (!current_user_can('upload_files')) {
            return new WP_Error('wp_forbidden', __('You cannot read the media library.', 'wp-pfagent'), ['status' => 403]);
        }
        $id = $this->clamp_int($args['id'] ?? 0, 1, PHP_INT_MAX);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new WP_Error('wp_not_found', __('That media item does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $data = $this->attachment_summary($post);
        $data['alt'] = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $meta = wp_get_attachment_metadata($id);
        if (is_array($meta)) {
            $data['width'] = isset($meta['width']) ? (int) $meta['width'] : null;
            $data['height'] = isset($meta['height']) ? (int) $meta['height'] : null;
            $data['filesize'] = isset($meta['filesize']) ? (int) $meta['filesize'] : null;
        }
        return $data;
    }

    // ----------------------------------------------------------------- users

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_user_list(array $args)
    {
        if (!current_user_can('list_users')) {
            return new WP_Error('wp_forbidden', __('You cannot list users.', 'wp-pfagent'), ['status' => 403]);
        }
        $per_page = $this->clamp_int($args['per_page'] ?? 20, 1, 100);
        $paged = $this->clamp_int($args['page'] ?? 1, 1, 100000);
        $query_args = [
            'number' => $per_page,
            'paged'  => $paged,
        ];
        if (isset($args['role']) && is_string($args['role']) && $args['role'] !== '') {
            $query_args['role'] = sanitize_key((string) $args['role']);
        }
        if (isset($args['search']) && is_string($args['search']) && $args['search'] !== '') {
            $query_args['search'] = '*' . sanitize_text_field((string) $args['search']) . '*';
            $query_args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }
        $query = new \WP_User_Query($query_args);
        $items = [];
        foreach ((array) $query->get_results() as $user) {
            $items[] = $this->user_summary($user);
        }
        return [
            'items'   => $items,
            'total'   => (int) $query->get_total(),
            'page'    => $paged,
            'perPage' => $per_page,
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_user_get(array $args)
    {
        if (!current_user_can('list_users')) {
            return new WP_Error('wp_forbidden', __('You cannot read users.', 'wp-pfagent'), ['status' => 403]);
        }
        $user = null;
        if (isset($args['id'])) {
            $user = get_userdata($this->clamp_int($args['id'], 1, PHP_INT_MAX));
        } elseif (isset($args['login']) && is_string($args['login'])) {
            $user = get_user_by('login', sanitize_user((string) $args['login']));
        }
        if (!$user) {
            return new WP_Error('wp_not_found', __('That user does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $data = $this->user_summary($user);
        $data['firstName'] = (string) $user->first_name;
        $data['lastName'] = (string) $user->last_name;
        $data['url'] = (string) $user->user_url;
        return $data;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_user_create(array $args)
    {
        if (!current_user_can('create_users')) {
            return new WP_Error('wp_forbidden', __('You cannot create users.', 'wp-pfagent'), ['status' => 403]);
        }
        $login = sanitize_user((string) ($args['username'] ?? ''), true);
        $email = sanitize_email((string) ($args['email'] ?? ''));
        if ($login === '' || $email === '' || !is_email($email)) {
            return new WP_Error('wp_invalid_args', __('A valid username and email are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $role = $this->sanitize_role_grant($args['role'] ?? get_option('default_role', 'subscriber'));
        if ($role instanceof WP_Error) {
            return $role;
        }
        $userarr = [
            'user_login'   => $login,
            'user_email'   => $email,
            'user_pass'    => wp_generate_password(24, true, true),
            'role'         => $role,
            'display_name' => sanitize_text_field((string) ($args['display_name'] ?? $login)),
            'first_name'   => sanitize_text_field((string) ($args['first_name'] ?? '')),
            'last_name'    => sanitize_text_field((string) ($args['last_name'] ?? '')),
        ];
        $id = wp_insert_user($userarr);
        if ($id instanceof WP_Error) {
            return $id;
        }
        // Never surface the generated password to the LLM/chat.
        return ['created' => true, 'user' => $this->user_summary(get_userdata((int) $id))];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_user_update(array $args)
    {
        if (!current_user_can('edit_users')) {
            return new WP_Error('wp_forbidden', __('You cannot edit users.', 'wp-pfagent'), ['status' => 403]);
        }
        $id = $this->clamp_int($args['id'] ?? 0, 1, PHP_INT_MAX);
        $user = get_userdata($id);
        if (!$user) {
            return new WP_Error('wp_not_found', __('That user does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $userarr = ['ID' => $id];
        foreach (['display_name' => 'display_name', 'first_name' => 'first_name', 'last_name' => 'last_name'] as $arg => $field) {
            if (array_key_exists($arg, $args)) {
                $userarr[$field] = sanitize_text_field((string) $args[$arg]);
            }
        }
        if (array_key_exists('email', $args)) {
            $email = sanitize_email((string) $args['email']);
            if ($email === '' || !is_email($email)) {
                return new WP_Error('wp_invalid_args', __('The email address is not valid.', 'wp-pfagent'), ['status' => 400]);
            }
            $userarr['user_email'] = $email;
        }
        if (array_key_exists('role', $args)) {
            if (!current_user_can('promote_users')) {
                return new WP_Error('wp_forbidden', __('You cannot change user roles.', 'wp-pfagent'), ['status' => 403]);
            }
            $role = $this->sanitize_role_grant($args['role']);
            if ($role instanceof WP_Error) {
                return $role;
            }
            $userarr['role'] = $role;
        }
        if (count($userarr) === 1) {
            return new WP_Error('wp_invalid_args', __('No updatable fields were provided.', 'wp-pfagent'), ['status' => 400]);
        }
        // Password changes are intentionally NOT supported through the agent.
        $result = wp_update_user($userarr);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['updated' => true, 'user' => $this->user_summary(get_userdata($id))];
    }

    // -------------------------------------------------------------- comments

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_comment_list(array $args)
    {
        if (!current_user_can('moderate_comments')) {
            return new WP_Error('wp_forbidden', __('You cannot moderate comments.', 'wp-pfagent'), ['status' => 403]);
        }
        $per_page = $this->clamp_int($args['per_page'] ?? 20, 1, 100);
        $paged = $this->clamp_int($args['page'] ?? 1, 1, 100000);
        $query_args = [
            'number' => $per_page,
            'paged'  => $paged,
            'status' => $this->sanitize_comment_status_filter($args['status'] ?? 'all'),
        ];
        if (isset($args['post_id'])) {
            $query_args['post_id'] = $this->clamp_int($args['post_id'], 1, PHP_INT_MAX);
        }
        if (isset($args['search']) && is_string($args['search']) && $args['search'] !== '') {
            $query_args['search'] = sanitize_text_field((string) $args['search']);
        }
        $comments = get_comments($query_args);
        $items = [];
        foreach ((is_array($comments) ? $comments : []) as $comment) {
            $items[] = $this->comment_summary($comment);
        }
        return ['items' => $items, 'page' => $paged, 'perPage' => $per_page];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_comment_moderate(array $args)
    {
        if (!current_user_can('moderate_comments')) {
            return new WP_Error('wp_forbidden', __('You cannot moderate comments.', 'wp-pfagent'), ['status' => 403]);
        }
        $id = $this->clamp_int($args['comment_id'] ?? 0, 1, PHP_INT_MAX);
        $comment = get_comment($id);
        if (!$comment) {
            return new WP_Error('wp_not_found', __('That comment does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $action = sanitize_key((string) ($args['action'] ?? ''));

        if ($action === 'reply') {
            $content = trim((string) ($args['content'] ?? ''));
            if ($content === '') {
                return new WP_Error('wp_invalid_args', __('A reply needs content.', 'wp-pfagent'), ['status' => 400]);
            }
            $user = wp_get_current_user();
            $new_id = wp_insert_comment([
                'comment_post_ID'      => (int) $comment->comment_post_ID,
                'comment_parent'       => $id,
                'comment_content'      => wp_kses_post($content),
                'user_id'              => (int) $user->ID,
                'comment_author'       => $user->display_name,
                'comment_author_email' => $user->user_email,
                'comment_approved'     => 1,
            ]);
            if (!$new_id) {
                return new WP_Error('wp_reply_failed', __('The reply could not be posted.', 'wp-pfagent'), ['status' => 500]);
            }
            return ['replied' => true, 'commentId' => (int) $new_id, 'parentId' => $id];
        }

        $status_map = ['approve' => 'approve', 'hold' => 'hold', 'unapprove' => 'hold', 'spam' => 'spam', 'trash' => 'trash'];
        if (!isset($status_map[$action])) {
            return new WP_Error('wp_invalid_args', __('Unknown moderation action.', 'wp-pfagent'), ['status' => 400]);
        }
        $ok = wp_set_comment_status($id, $status_map[$action]);
        if (!$ok) {
            return new WP_Error('wp_moderate_failed', __('The comment status could not be changed.', 'wp-pfagent'), ['status' => 500]);
        }
        return ['moderated' => true, 'commentId' => $id, 'action' => $action];
    }

    // -------------------------------------------------------------- settings

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_option_get(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wp_forbidden', __('You cannot read site settings.', 'wp-pfagent'), ['status' => 403]);
        }
        $key = sanitize_key((string) ($args['key'] ?? ''));
        if (!in_array($key, self::OPTION_READ_ALLOWLIST, true)) {
            return new WP_Error('wp_option_not_allowed', __('That setting is not readable through the agent.', 'wp-pfagent'), ['status' => 403]);
        }
        return ['key' => $key, 'value' => get_option($key)];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_option_set(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wp_forbidden', __('You cannot change site settings.', 'wp-pfagent'), ['status' => 403]);
        }
        $key = sanitize_key((string) ($args['key'] ?? ''));
        if (!in_array($key, self::OPTION_WRITE_ALLOWLIST, true)) {
            return new WP_Error('wp_option_not_allowed', __('That setting cannot be changed through the agent.', 'wp-pfagent'), ['status' => 403]);
        }
        if (!array_key_exists('value', $args)) {
            return new WP_Error('wp_invalid_args', __('A value is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $value = $this->sanitize_option_value($key, $args['value']);
        update_option($key, $value);
        return ['updated' => true, 'key' => $key, 'value' => get_option($key)];
    }

    // ------------------------------------------------------------------ site

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_site_info(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wp_forbidden', __('You cannot read the site overview.', 'wp-pfagent'), ['status' => 403]);
        }
        $theme = wp_get_theme();
        $counts_posts = wp_count_posts('post');
        $counts_pages = wp_count_posts('page');
        $users_total = count_users();
        $comments = wp_count_comments();

        return [
            'wpVersion'    => get_bloginfo('version'),
            'siteName'     => get_bloginfo('name'),
            'siteUrl'      => home_url(),
            'language'     => get_bloginfo('language'),
            'activeTheme'  => ['name' => $theme->get('Name'), 'version' => $theme->get('Version')],
            'multisite'    => is_multisite(),
            'counts'       => [
                'postsPublished' => (int) ($counts_posts->publish ?? 0),
                'pagesPublished' => (int) ($counts_pages->publish ?? 0),
                'users'          => (int) ($users_total['total_users'] ?? 0),
                'commentsApproved' => (int) ($comments->approved ?? 0),
                'commentsPending'  => (int) ($comments->moderated ?? 0),
            ],
            'activePlugins' => array_values((array) get_option('active_plugins', [])),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_menu_list(array $args)
    {
        if (!current_user_can('edit_theme_options') && !current_user_can('edit_posts')) {
            return new WP_Error('wp_forbidden', __('You cannot read navigation menus.', 'wp-pfagent'), ['status' => 403]);
        }
        $menus = wp_get_nav_menus();
        $out = [];
        foreach ((is_array($menus) ? $menus : []) as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id);
            $mapped = [];
            foreach ((is_array($items) ? $items : []) as $item) {
                $mapped[] = ['id' => (int) $item->ID, 'title' => $item->title, 'type' => $item->type, 'url' => $item->url];
            }
            $out[] = ['id' => (int) $menu->term_id, 'name' => $menu->name, 'slug' => $menu->slug, 'count' => (int) $menu->count, 'items' => $mapped];
        }
        return ['menus' => $out];
    }

    /**
     * Create a navigation menu or append an item to one. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_menu_manage(array $args)
    {
        if (!current_user_can('edit_theme_options')) {
            return new WP_Error('wp_forbidden', __('You cannot manage navigation menus.', 'wp-pfagent'), ['status' => 403]);
        }
        $action = sanitize_key((string) ($args['action'] ?? ''));

        if ($action === 'create_menu') {
            $name = sanitize_text_field((string) ($args['name'] ?? ''));
            if ($name === '') {
                return new WP_Error('wp_invalid_args', __('A menu name is required.', 'wp-pfagent'), ['status' => 400]);
            }
            $id = wp_create_nav_menu($name);
            if ($id instanceof WP_Error) {
                return $id;
            }
            return ['created' => true, 'menuId' => (int) $id, 'name' => $name];
        }

        if ($action === 'add_item') {
            $menu_id = $this->clamp_int($args['menu_id'] ?? 0, 1, PHP_INT_MAX);
            if (!wp_get_nav_menu_object($menu_id)) {
                return new WP_Error('wp_not_found', __('That menu does not exist.', 'wp-pfagent'), ['status' => 404]);
            }
            $title = sanitize_text_field((string) ($args['title'] ?? ''));
            $itemArgs = ['menu-item-title' => $title, 'menu-item-status' => 'publish'];
            if (isset($args['post_id'])) {
                $itemArgs['menu-item-type'] = 'post_type';
                $itemArgs['menu-item-object'] = get_post_type((int) $args['post_id']) ?: 'page';
                $itemArgs['menu-item-object-id'] = $this->clamp_int($args['post_id'], 1, PHP_INT_MAX);
            } elseif (isset($args['url']) && is_string($args['url'])) {
                $itemArgs['menu-item-type'] = 'custom';
                $itemArgs['menu-item-url'] = esc_url_raw((string) $args['url']);
            } else {
                return new WP_Error('wp_invalid_args', __('Provide a post_id or a url for the menu item.', 'wp-pfagent'), ['status' => 400]);
            }
            $item_id = wp_update_nav_menu_item($menu_id, 0, $itemArgs);
            if ($item_id instanceof WP_Error) {
                return $item_id;
            }
            return ['added' => true, 'menuId' => $menu_id, 'itemId' => (int) $item_id];
        }

        return new WP_Error('wp_invalid_args', __('Unknown menu action (use create_menu or add_item).', 'wp-pfagent'), ['status' => 400]);
    }

    /**
     * List widgets grouped by sidebar (read-only).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_widget_list(array $args)
    {
        if (!current_user_can('edit_theme_options')) {
            return new WP_Error('wp_forbidden', __('You cannot read widgets.', 'wp-pfagent'), ['status' => 403]);
        }
        global $wp_registered_sidebars;
        $map = get_option('sidebars_widgets', []);
        $out = [];
        foreach ((is_array($map) ? $map : []) as $sidebar => $widgets) {
            if ($sidebar === 'wp_inactive_widgets' || $sidebar === 'array_version' || !is_array($widgets)) {
                continue;
            }
            $name = is_array($wp_registered_sidebars) && isset($wp_registered_sidebars[$sidebar]['name'])
                ? (string) $wp_registered_sidebars[$sidebar]['name']
                : (string) $sidebar;
            $out[] = ['sidebar' => (string) $sidebar, 'name' => $name, 'widgets' => array_values(array_map('strval', (array) $widgets))];
        }
        return ['sidebars' => $out];
    }

    // ------------------------------------------------------------ discovery

    /**
     * List installed plugins and their active state (read-only).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_plugins_list(array $args)
    {
        if (!current_user_can('activate_plugins')) {
            return new WP_Error('wp_forbidden', __('You cannot read the plugin list.', 'wp-pfagent'), ['status' => 403]);
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all = get_plugins();
        $out = [];
        foreach ($all as $file => $data) {
            $out[] = [
                'file' => (string) $file,
                'name' => (string) ($data['Name'] ?? ''),
                'version' => (string) ($data['Version'] ?? ''),
                'active' => is_plugin_active($file),
            ];
        }
        return ['plugins' => $out, 'count' => count($out)];
    }

    /**
     * List registered post types + taxonomies so the agent can discover the
     * data any plugin exposes (read-only). Its CRUD then flows through the
     * generic wp_post_* tools by passing the post_type.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_posttypes_list(array $args)
    {
        $types = [];
        foreach (get_post_types(['show_ui' => true], 'objects') as $pt) {
            $types[] = [
                'slug' => $pt->name,
                'label' => is_object($pt->labels) ? (string) $pt->labels->name : (string) $pt->label,
                'public' => (bool) $pt->public,
                'taxonomies' => array_values(get_object_taxonomies($pt->name)),
            ];
        }
        return ['postTypes' => $types];
    }

    /**
     * Set a PUBLIC post meta value. Protected meta (keys starting with `_`)
     * are refused — those are internal to WordPress and plugins and must not be
     * writable through the agent. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_post_meta_set(array $args)
    {
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('wp_forbidden', __('You cannot edit this content.', 'wp-pfagent'), ['status' => 403]);
        }
        $key = (string) ($args['key'] ?? '');
        if ($key === '' || $key[0] === '_' || is_protected_meta($key, 'post')) {
            return new WP_Error('wp_meta_not_allowed', __('Only public custom fields can be set (protected keys are refused).', 'wp-pfagent'), ['status' => 403]);
        }
        if (!array_key_exists('value', $args)) {
            return new WP_Error('wp_invalid_args', __('A value is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $value = is_scalar($args['value']) ? sanitize_text_field((string) $args['value']) : $args['value'];
        update_post_meta($post->ID, $key, $value);
        return ['updated' => true, 'postId' => $post->ID, 'key' => $key, 'value' => get_post_meta($post->ID, $key, true)];
    }

    /**
     * Sideload a remote image/file URL into the media library. SSRF-guarded:
     * only http/https, and the URL must pass WordPress's own external-host
     * validation (which rejects loopback / private / link-local addresses).
     * Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wp_media_sideload(array $args)
    {
        if (!current_user_can('upload_files')) {
            return new WP_Error('wp_forbidden', __('You cannot add media.', 'wp-pfagent'), ['status' => 403]);
        }
        $url = trim((string) ($args['url'] ?? ''));
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || !in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('wp_invalid_args', __('A valid http(s) URL is required.', 'wp-pfagent'), ['status' => 400]);
        }
        // SSRF guard: WordPress validates the host is external (blocks
        // localhost / private / link-local IPs) when reject_unsafe_urls is on.
        $validated = wp_http_validate_url($url);
        if ($validated === false) {
            return new WP_Error('wp_ssrf_blocked', __('That URL is not allowed (it points at a private or unsafe address).', 'wp-pfagent'), ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($validated);
        if ($tmp instanceof WP_Error) {
            return $tmp;
        }
        $file = ['name' => basename((string) wp_parse_url($validated, PHP_URL_PATH)) ?: 'sideload', 'tmp_name' => $tmp];
        $attach_id = media_handle_sideload($file, 0, isset($args['title']) ? sanitize_text_field((string) $args['title']) : null);
        if (is_file($tmp)) {
            wp_delete_file($tmp);
        }
        if ($attach_id instanceof WP_Error) {
            return $attach_id;
        }
        return ['sideloaded' => true, 'id' => (int) $attach_id, 'url' => (string) wp_get_attachment_url((int) $attach_id)];
    }

    // --------------------------------------------------------------- helpers

    /** @return \WP_Post|WP_Error */
    private function resolve_post(array $args)
    {
        if (isset($args['id'])) {
            $post = get_post($this->clamp_int($args['id'], 1, PHP_INT_MAX));
            if ($post instanceof \WP_Post) {
                return $post;
            }
        }
        if (isset($args['slug']) && is_string($args['slug']) && $args['slug'] !== '') {
            $post_type = is_string($args['post_type'] ?? null) ? sanitize_key((string) $args['post_type']) : 'post';
            $found = get_posts([
                'name'        => sanitize_title((string) $args['slug']),
                'post_type'   => $post_type,
                'post_status' => 'any',
                'numberposts' => 1,
            ]);
            if (!empty($found)) {
                return $found[0];
            }
        }
        return new WP_Error('wp_not_found', __('That content could not be found.', 'wp-pfagent'), ['status' => 404]);
    }

    /** @return string|WP_Error */
    private function sanitize_post_type($value)
    {
        $post_type = sanitize_key((string) $value);
        if ($post_type === '' || !post_type_exists($post_type)) {
            return new WP_Error('wp_unknown_post_type', __('That content type does not exist.', 'wp-pfagent'), ['status' => 400]);
        }
        return $post_type;
    }

    /** @return array<int, string>|string */
    private function sanitize_status_list($value)
    {
        if ($value === 'any' || $value === '' || $value === null) {
            return 'any';
        }
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $out = [];
        foreach ($parts as $p) {
            $s = sanitize_key((string) $p);
            if ($s !== '') {
                $out[] = $s;
            }
        }
        return $out === [] ? 'any' : $out;
    }

    private function sanitize_single_status($value): string
    {
        $allowed = ['draft', 'publish', 'pending', 'private'];
        $s = sanitize_key((string) $value);
        return in_array($s, $allowed, true) ? $s : 'draft';
    }

    private function sanitize_comment_status_filter($value): string
    {
        $allowed = ['all', 'approve', 'hold', 'spam', 'trash'];
        $s = sanitize_key((string) $value);
        return in_array($s, $allowed, true) ? $s : 'all';
    }

    /**
     * Only allow granting a role whose capabilities the CURRENT user also has
     * — the anti-privilege-escalation guard. An editor can never mint an admin.
     *
     * @return string|WP_Error
     */
    private function sanitize_role_grant($value)
    {
        $role_slug = sanitize_key((string) $value);
        if ($role_slug === '') {
            return new WP_Error('wp_invalid_args', __('A role is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $role = get_role($role_slug);
        if (!$role) {
            return new WP_Error('wp_unknown_role', __('That role does not exist.', 'wp-pfagent'), ['status' => 400]);
        }
        // Administrator is only grantable by someone who can manage the whole
        // site (and, on multisite, a super admin).
        if ($role_slug === 'administrator') {
            if (!current_user_can('manage_options') || (is_multisite() && !is_super_admin())) {
                return new WP_Error('wp_forbidden', __('You cannot grant the administrator role.', 'wp-pfagent'), ['status' => 403]);
            }
        }
        $current = wp_get_current_user();
        foreach (array_keys(array_filter($role->capabilities)) as $cap) {
            if (!$current->has_cap($cap) && !is_super_admin()) {
                return new WP_Error('wp_forbidden', __('You cannot grant a role with more capabilities than your own.', 'wp-pfagent'), ['status' => 403]);
            }
        }
        return $role_slug;
    }

    /** @return mixed */
    private function sanitize_option_value(string $key, $value)
    {
        $int_keys = ['posts_per_page', 'start_of_week', 'page_on_front', 'page_for_posts', 'blog_public'];
        if (in_array($key, $int_keys, true)) {
            return (int) $value;
        }
        if ($key === 'gmt_offset') {
            return (float) $value;
        }
        return sanitize_text_field((string) $value);
    }

    private function clamp_int($value, int $min, int $max): int
    {
        $n = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $n));
    }

    /** @return array<string, mixed> */
    private function post_summary(\WP_Post $post): array
    {
        return [
            'id'       => (int) $post->ID,
            'title'    => get_the_title($post),
            'type'     => $post->post_type,
            'status'   => $post->post_status,
            'slug'     => $post->post_name,
            'author'   => (int) $post->post_author,
            'date'     => $post->post_date_gmt,
            'modified' => $post->post_modified_gmt,
            'excerpt'  => has_excerpt($post) ? get_the_excerpt($post) : '',
            'link'     => (string) get_permalink($post),
        ];
    }

    /** @return array<string, array<int, string>> */
    private function post_terms(\WP_Post $post): array
    {
        $out = [];
        foreach (get_object_taxonomies($post->post_type) as $tax) {
            $terms = wp_get_object_terms($post->ID, $tax, ['fields' => 'names']);
            if (!is_wp_error($terms) && !empty($terms)) {
                $out[$tax] = array_map('strval', $terms);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> Public (non-underscored) meta only. */
    private function public_meta(int $post_id): array
    {
        $all = get_post_meta($post_id);
        $out = [];
        foreach ((is_array($all) ? $all : []) as $key => $values) {
            if (is_string($key) && $key !== '' && $key[0] !== '_') {
                $out[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function attachment_summary(\WP_Post $post): array
    {
        return [
            'id'       => (int) $post->ID,
            'title'    => get_the_title($post),
            'mime'     => $post->post_mime_type,
            'date'     => $post->post_date_gmt,
            'url'      => (string) wp_get_attachment_url($post->ID),
            'author'   => (int) $post->post_author,
        ];
    }

    /** @param \WP_User $user @return array<string, mixed> */
    private function user_summary(\WP_User $user): array
    {
        $data = [
            'id'          => (int) $user->ID,
            'login'       => $user->user_login,
            'displayName' => $user->display_name,
            'roles'       => array_values((array) $user->roles),
            'registered'  => $user->user_registered,
        ];
        // Email only for those who can edit users; never the password hash.
        if (current_user_can('edit_users')) {
            $data['email'] = $user->user_email;
        }
        return $data;
    }

    /** @param \WP_Comment $comment @return array<string, mixed> */
    private function comment_summary(\WP_Comment $comment): array
    {
        return [
            'id'         => (int) $comment->comment_ID,
            'postId'     => (int) $comment->comment_post_ID,
            'author'     => $comment->comment_author,
            'content'    => $comment->comment_content,
            'status'     => wp_get_comment_status($comment->comment_ID),
            'date'       => $comment->comment_date_gmt,
            'parent'     => (int) $comment->comment_parent,
        ];
    }
}
