<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

use WP_Error;

/**
 * Lean MemberPress adapter (v1). Self-contained + PFA-owned: talks to
 * MemberPress's own public models (MeprProduct / MeprUser / MeprTransaction),
 * no PFW dependency. Only present when MemberPress is active
 * (ThirdPartyPresence gates the tools).
 *
 * Scope: READ membership products / a member's active memberships and
 * subscriptions; GRANT or REVOKE a member's access to a membership (gated).
 * Access changes are highly sensitive (billing/entitlement), so this is a
 * side-effect (human modal) and the caller must hold manage_options.
 *
 * Not render-verified against a live MemberPress on the bench (paid plugin,
 * absent there); paths use MemberPress's documented models and are
 * presence-gated so they only surface where MemberPress runs.
 */
final class MemberPressAgentApi
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function mp_read(array $args)
    {
        if (!$this->present()) {
            return new WP_Error('mp_absent', __('MemberPress is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('mp_forbidden', __('You cannot read MemberPress data.', 'wp-pfagent'), ['status' => 403]);
        }
        $kind = in_array(($args['kind'] ?? 'memberships'), ['memberships', 'member'], true) ? (string) $args['kind'] : 'memberships';

        if ($kind === 'member') {
            $user_id = isset($args['user_id']) && is_numeric($args['user_id']) ? (int) $args['user_id'] : 0;
            if ($user_id <= 0 || !get_userdata($user_id)) {
                return new WP_Error('mp_invalid_args', __('A valid user_id is required.', 'wp-pfagent'), ['status' => 400]);
            }
            $mepr_user = new \MeprUser($user_id);
            $active = method_exists($mepr_user, 'active_product_subscriptions') ? (array) $mepr_user->active_product_subscriptions('ids') : [];
            $out = [];
            foreach ($active as $pid) {
                $out[] = ['membershipId' => (int) $pid, 'title' => get_the_title((int) $pid)];
            }
            return ['kind' => 'member', 'userId' => $user_id, 'activeMemberships' => $out];
        }

        $posts = get_posts(['post_type' => 'memberpressproduct', 'numberposts' => 50, 'post_status' => 'publish']);
        $items = [];
        foreach ($posts as $p) {
            $prod = new \MeprProduct($p->ID);
            $items[] = ['id' => (int) $p->ID, 'title' => get_the_title($p), 'price' => isset($prod->price) ? (string) $prod->price : ''];
        }
        return ['kind' => 'memberships', 'items' => $items];
    }

    /**
     * Grant or revoke a member's access to a membership. Side-effect. Grant
     * creates a manual, complete transaction; revoke expires the member's
     * active transactions for that membership.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function mp_access(array $args)
    {
        if (!$this->present()) {
            return new WP_Error('mp_absent', __('MemberPress is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('mp_forbidden', __('You cannot change MemberPress access.', 'wp-pfagent'), ['status' => 403]);
        }
        $user_id = isset($args['user_id']) && is_numeric($args['user_id']) ? (int) $args['user_id'] : 0;
        $membership_id = isset($args['membership_id']) && is_numeric($args['membership_id']) ? (int) $args['membership_id'] : 0;
        if ($user_id <= 0 || !get_userdata($user_id) || get_post_type($membership_id) !== 'memberpressproduct') {
            return new WP_Error('mp_invalid_args', __('A valid user_id and membership_id are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $op = sanitize_key((string) ($args['op'] ?? 'grant'));

        if ($op === 'revoke') {
            global $wpdb;
            $mepr_db = new \MeprDb();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writing MemberPress' own transactions table via $wpdb->update (its documented storage); no object cache applies.
            $wpdb->update(
                $mepr_db->transactions,
                ['status' => 'refunded', 'expires_at' => gmdate('Y-m-d H:i:s')],
                ['user_id' => $user_id, 'product_id' => $membership_id, 'status' => 'complete']
            );
            return ['ok' => true, 'op' => 'revoke', 'userId' => $user_id, 'membershipId' => $membership_id];
        }

        // grant — create a manual complete transaction
        $txn = new \MeprTransaction();
        $txn->user_id = $user_id;
        $txn->product_id = $membership_id;
        $txn->status = 'complete';
        $txn->gateway = 'manual';
        $txn->trans_num = uniqid('pfa-', true);
        $txn->expires_at = '0000-00-00 00:00:00';
        $txn->store();
        return ['ok' => true, 'op' => 'grant', 'userId' => $user_id, 'membershipId' => $membership_id, 'txnId' => (int) $txn->id];
    }

    private function present(): bool
    {
        return defined('MEPR_VERSION') && class_exists('MeprProduct') && class_exists('MeprUser') && class_exists('MeprTransaction');
    }
}
