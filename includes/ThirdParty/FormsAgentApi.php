<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

use WP_Error;

/**
 * Lean forms adapter (v1) across the popular form plugins — Contact Form 7,
 * Fluent Forms, Gravity Forms, WPForms. Self-contained + PFA-owned: talks to
 * each plugin's own public API, no suite dependency. Only present when a form
 * plugin is active (ThirdPartyPresence gates the tools).
 *
 * Scope (lean, READ-ONLY): list forms, and list a form's entries where the
 * plugin persists them. Honest about limits: Contact Form 7 does NOT store
 * submissions, so it contributes forms but no entries.
 */
final class FormsAgentApi
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function forms_list(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forms_forbidden', __('You cannot list forms.', 'wp-pfagent'), ['status' => 403]);
        }
        $forms = [];

        // Contact Form 7 — forms only (no stored entries).
        if (defined('WPCF7_VERSION') && class_exists('WPCF7_ContactForm')) {
            foreach (\WPCF7_ContactForm::find(['posts_per_page' => 100]) as $cf) {
                $forms[] = ['plugin' => 'contactform7', 'id' => (int) $cf->id(), 'title' => (string) $cf->title(), 'entries' => false];
            }
        }
        // Fluent Forms — forms + entries. Read Fluent Forms' own tables
        // directly (stable public schema); more robust than the bundled query
        // builder's row shape.
        if (defined('FLUENTFORM')) {
            global $wpdb;
            $t = $wpdb->prefix . 'fluentform_forms';
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Reading Fluent Forms' own table; the table name is a fixed plugin table (not user input) and cannot be a bound parameter, and a one-shot agent read needs no object cache.
                $rows = (array) $wpdb->get_results("SELECT id, title FROM {$t} ORDER BY id DESC LIMIT 100", ARRAY_A);
                foreach ($rows as $row) {
                    $forms[] = ['plugin' => 'fluentforms', 'id' => (int) ($row['id'] ?? 0), 'title' => (string) ($row['title'] ?? ''), 'entries' => true];
                }
            }
        }
        // Gravity Forms — forms + entries.
        if (class_exists('GFAPI')) {
            foreach (\GFAPI::get_forms() as $gf) {
                $forms[] = ['plugin' => 'gravityforms', 'id' => (int) $gf['id'], 'title' => (string) ($gf['title'] ?? ''), 'entries' => true];
            }
        }
        // WPForms — forms; entries only in Pro.
        if (defined('WPFORMS_VERSION') && function_exists('wpforms')) {
            $posts = get_posts(['post_type' => 'wpforms', 'numberposts' => 100, 'post_status' => 'publish']);
            $hasEntries = class_exists('WPForms\\Pro\\Pro');
            foreach ($posts as $p) {
                $forms[] = ['plugin' => 'wpforms', 'id' => (int) $p->ID, 'title' => get_the_title($p), 'entries' => $hasEntries];
            }
        }

        return ['forms' => $forms, 'count' => count($forms)];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function forms_entries(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forms_forbidden', __('You cannot read form entries.', 'wp-pfagent'), ['status' => 403]);
        }
        $plugin = sanitize_key((string) ($args['plugin'] ?? ''));
        $form_id = isset($args['form_id']) && is_numeric($args['form_id']) ? (int) $args['form_id'] : 0;
        $per_page = max(1, min(50, (int) ($args['per_page'] ?? 20)));
        if ($form_id <= 0) {
            return new WP_Error('forms_invalid_args', __('A form id is required.', 'wp-pfagent'), ['status' => 400]);
        }

        // Fluent Forms — read its submissions table directly.
        if (($plugin === 'fluentforms' || $plugin === '') && defined('FLUENTFORM')) {
            global $wpdb;
            $t = $wpdb->prefix . 'fluentform_submissions';
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reading Fluent Forms' own submissions table; the table name is a fixed plugin table interpolated into an otherwise-prepared query, and a one-shot agent read needs no object cache.
                $rows = (array) $wpdb->get_results($wpdb->prepare(
                    "SELECT id, response, status, created_at FROM {$t} WHERE form_id = %d ORDER BY id DESC LIMIT %d",
                    $form_id,
                    $per_page
                ), ARRAY_A);
                $entries = [];
                foreach ($rows as $row) {
                    $entries[] = ['id' => (int) ($row['id'] ?? 0), 'created' => (string) ($row['created_at'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'data' => json_decode((string) ($row['response'] ?? '{}'), true)];
                }
                return ['plugin' => 'fluentforms', 'formId' => $form_id, 'entries' => $entries, 'count' => count($entries)];
            }
        }
        // Gravity Forms
        if (($plugin === 'gravityforms' || $plugin === '') && class_exists('GFAPI')) {
            $res = \GFAPI::get_entries($form_id, [], null, ['page_size' => $per_page]);
            $entries = [];
            foreach ((is_array($res) ? $res : []) as $e) {
                $entries[] = ['id' => (int) ($e['id'] ?? 0), 'created' => (string) ($e['date_created'] ?? ''), 'status' => (string) ($e['status'] ?? ''), 'data' => $e];
            }
            return ['plugin' => 'gravityforms', 'formId' => $form_id, 'entries' => $entries, 'count' => count($entries)];
        }
        // WPForms — entries live only in the Pro add-on; Lite stores none.
        if (($plugin === 'wpforms' || $plugin === '') && defined('WPFORMS_VERSION')) {
            if (!class_exists('WPForms\\Pro\\Pro')) {
                return new WP_Error('forms_requires_pro', __('WPForms stores form entries only in the Pro version. This site runs WPForms Lite, which does not save entries.', 'wp-pfagent'), ['status' => 400]);
            }
            // WPForms Pro present — read via its entries handler.
            if (function_exists('wpforms') && isset(wpforms()->entry)) {
                $rows = wpforms()->entry->get_entries(['form_id' => $form_id, 'number' => $per_page]);
                $entries = [];
                foreach ((array) $rows as $r) {
                    $entries[] = ['id' => (int) ($r->entry_id ?? 0), 'created' => (string) ($r->date ?? ''), 'status' => (string) ($r->status ?? ''), 'data' => json_decode((string) ($r->fields ?? '{}'), true)];
                }
                return ['plugin' => 'wpforms', 'formId' => $form_id, 'entries' => $entries, 'count' => count($entries)];
            }
        }

        // Contact Form 7 stores nothing.
        if ($plugin === 'contactform7' || (defined('WPCF7_VERSION') && $plugin === '')) {
            return new WP_Error('forms_no_entries', __('Contact Form 7 does not store submissions; there are no entries to list.', 'wp-pfagent'), ['status' => 400]);
        }

        return new WP_Error('forms_unsupported', __('That form plugin does not expose entries here.', 'wp-pfagent'), ['status' => 400]);
    }

    /**
     * Manage a single stored entry: mark read, spam, trash, star, or add a
     * note — where the plugin supports it (Gravity Forms, Fluent Forms).
     * Contact Form 7 stores nothing; WPForms entries need Pro. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function forms_entry_manage(array $args)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('forms_forbidden', __('You cannot manage form entries.', 'wp-pfagent'), ['status' => 403]);
        }
        $plugin = sanitize_key((string) ($args['plugin'] ?? ''));
        $entry_id = isset($args['entry_id']) && is_numeric($args['entry_id']) ? (int) $args['entry_id'] : 0;
        $action = sanitize_key((string) ($args['action'] ?? ''));
        if ($entry_id <= 0 || !in_array($action, ['read', 'unread', 'spam', 'unspam', 'trash', 'star', 'unstar', 'note'], true)) {
            return new WP_Error('forms_invalid_args', __('An entry_id and a valid action (read/spam/trash/star/note) are required.', 'wp-pfagent'), ['status' => 400]);
        }

        // Gravity Forms — GFAPI covers all of it.
        if (($plugin === 'gravityforms' || $plugin === '') && class_exists('GFAPI')) {
            if ($action === 'note') {
                $note = sanitize_text_field((string) ($args['note'] ?? ''));
                if ($note === '' || !class_exists('RGFormsModel')) {
                    return new WP_Error('forms_invalid_args', __('A note is required.', 'wp-pfagent'), ['status' => 400]);
                }
                $user = wp_get_current_user();
                \RGFormsModel::add_note($entry_id, (int) $user->ID, $user->display_name, $note);
                return ['plugin' => 'gravityforms', 'entryId' => $entry_id, 'noted' => true];
            }
            $map = [
                'read' => ['is_read', 1], 'unread' => ['is_read', 0],
                'star' => ['is_starred', 1], 'unstar' => ['is_starred', 0],
                'spam' => ['status', 'spam'], 'unspam' => ['status', 'active'],
                'trash' => ['status', 'trash'],
            ];
            [$prop, $val] = $map[$action];
            $ok = \GFAPI::update_entry_property($entry_id, $prop, $val);
            return ['plugin' => 'gravityforms', 'entryId' => $entry_id, 'action' => $action, 'ok' => (bool) $ok];
        }

        // Fluent Forms — status column on the submissions table.
        if (($plugin === 'fluentforms' || $plugin === '') && defined('FLUENTFORM')) {
            global $wpdb;
            $t = $wpdb->prefix . 'fluentform_submissions';
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) {
                return new WP_Error('forms_unsupported', __('Fluent Forms submissions table not found.', 'wp-pfagent'), ['status' => 400]);
            }
            if ($action === 'note') {
                return new WP_Error('forms_unsupported', __('Notes are not supported for Fluent Forms entries here.', 'wp-pfagent'), ['status' => 400]);
            }
            $statusMap = ['read' => 'read', 'unread' => 'unread', 'spam' => 'spam', 'unspam' => 'unread', 'trash' => 'trashed'];
            if (in_array($action, ['star', 'unstar'], true)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing Fluent Forms' own submissions table via $wpdb->update (its documented storage); no object cache applies.
                $wpdb->update($t, ['is_favourite' => $action === 'star' ? 1 : 0], ['id' => $entry_id]);
                return ['plugin' => 'fluentforms', 'entryId' => $entry_id, 'action' => $action, 'ok' => true];
            }
            if (isset($statusMap[$action])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing Fluent Forms' own submissions table via $wpdb->update (its documented storage); no object cache applies.
                $wpdb->update($t, ['status' => $statusMap[$action]], ['id' => $entry_id]);
                return ['plugin' => 'fluentforms', 'entryId' => $entry_id, 'action' => $action, 'ok' => true];
            }
        }

        return new WP_Error('forms_unsupported', __('That form plugin does not support managing entries here.', 'wp-pfagent'), ['status' => 400]);
    }
}
