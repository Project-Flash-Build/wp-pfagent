<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

use WP_Error;

/**
 * Lean LearnDash adapter (v1). Self-contained + PFA-owned: talks to
 * LearnDash's own public API (CPTs + ld_update_course_access), no PFW
 * dependency. Only present when LearnDash is active (ThirdPartyPresence gates
 * the tools).
 *
 * Scope: READ courses / lessons / a user's enrollments; ENROLL or UNENROLL a
 * user in a course (gated). Enrollment is a side-effect (human modal).
 *
 * Not render-verified against a live LearnDash on the bench (it is a paid
 * plugin, absent there); the read/write paths use LearnDash's documented API
 * and are presence-gated so they only surface where LearnDash runs.
 */
final class LearnDashAgentApi
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function ld_read(array $args)
    {
        if (!defined('LEARNDASH_VERSION')) {
            return new WP_Error('ld_absent', __('LearnDash is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('edit_courses') && !current_user_can('manage_options')) {
            return new WP_Error('ld_forbidden', __('You cannot read LearnDash data.', 'wp-pfagent'), ['status' => 403]);
        }
        $kind = in_array(($args['kind'] ?? 'courses'), ['courses', 'lessons', 'enrollments'], true) ? (string) $args['kind'] : 'courses';
        $per_page = max(1, min(50, (int) ($args['per_page'] ?? 20)));

        if ($kind === 'enrollments') {
            $user_id = isset($args['user_id']) && is_numeric($args['user_id']) ? (int) $args['user_id'] : 0;
            if ($user_id <= 0 || !function_exists('ld_get_mycourses')) {
                return new WP_Error('ld_invalid_args', __('A user_id is required to read enrollments.', 'wp-pfagent'), ['status' => 400]);
            }
            $course_ids = ld_get_mycourses($user_id);
            $out = [];
            foreach ((array) $course_ids as $cid) {
                $out[] = ['courseId' => (int) $cid, 'title' => get_the_title((int) $cid)];
            }
            return ['kind' => 'enrollments', 'userId' => $user_id, 'courses' => $out];
        }

        $post_type = $kind === 'lessons' ? 'sfwd-lessons' : 'sfwd-courses';
        $posts = get_posts(['post_type' => $post_type, 'numberposts' => $per_page, 'post_status' => 'publish']);
        $items = [];
        foreach ($posts as $p) {
            $items[] = ['id' => (int) $p->ID, 'title' => get_the_title($p), 'status' => $p->post_status];
        }
        return ['kind' => $kind, 'items' => $items];
    }

    /**
     * Enroll or unenroll a user in a course. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function ld_enroll(array $args)
    {
        if (!defined('LEARNDASH_VERSION') || !function_exists('ld_update_course_access')) {
            return new WP_Error('ld_absent', __('LearnDash is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('edit_courses') && !current_user_can('manage_options')) {
            return new WP_Error('ld_forbidden', __('You cannot manage LearnDash enrollments.', 'wp-pfagent'), ['status' => 403]);
        }
        $user_id = isset($args['user_id']) && is_numeric($args['user_id']) ? (int) $args['user_id'] : 0;
        $course_id = isset($args['course_id']) && is_numeric($args['course_id']) ? (int) $args['course_id'] : 0;
        if ($user_id <= 0 || $course_id <= 0 || !get_userdata($user_id) || get_post_type($course_id) !== 'sfwd-courses') {
            return new WP_Error('ld_invalid_args', __('A valid user_id and course_id are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $remove = sanitize_key((string) ($args['op'] ?? 'enroll')) === 'unenroll';
        ld_update_course_access($user_id, $course_id, $remove);
        return ['ok' => true, 'op' => $remove ? 'unenroll' : 'enroll', 'userId' => $user_id, 'courseId' => $course_id];
    }
}
