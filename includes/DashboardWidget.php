<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Wp-admin Dashboard widget for Setyenv Agent. Mirrors the PFM /
 * PFW tile layout for visual continuity across the family. Surfaces:
 *
 *   - conversation count (last 30 days)
 *   - configured-provider count
 *   - rate-limit status snapshot
 *
 * The widget is the operator's "is the agent healthy?" glance — they
 * see live counts on the Dashboard without opening the SPA. Self-
 * contained: inline styles, no enqueued assets, no template files.
 */
final class DashboardWidget
{
    private const WIDGET_ID = 'pfa_dashboard_overview';

    public function init(): void
    {
        add_action('wp_dashboard_setup', [$this, 'register']);
    }

    public function register(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        wp_add_dashboard_widget(
            self::WIDGET_ID,
            __('Setyenv Agent', 'wp-pfagent'),
            [$this, 'render']
        );
    }

    public function render(): void
    {
        // PFA's SPA has no URL routing for inner sections — credentials,
        // traces, permissions are React state, not hash routes. Every
        // link below collapses to the same admin page; the SPA picks
        // its initial view from operator session state. Cross-plugin
        // footer links jump to the rest of the family instead.
        $admin_url = admin_url('admin.php?page=wp-pfagent');

        // Presence-aware copy + footer. When the Setyenv suite is present the
        // widget speaks to modelling data and drawing workflows and links to
        // PFM/PFW; a standalone install (suite absent) speaks purely to the
        // WordPress surface and drops the dead cross-plugin links.
        $has_management = \ProjectFlash\Agent\ManagementDependency::is_active();
        $has_workflow = \ProjectFlash\Agent\WorkflowDependency::is_active();

        [$conversations_30d, $providers_configured, $messages_30d] = $this->collect_stats();

        $accent = '#0d9488';

        $tagline = __('Your natural-language copilot', 'wp-pfagent');
        $blurb = ($has_management || $has_workflow)
            ? __('Ask it to create entities, draw workflows or explore your data. The agent knows the PFM model and the PFW catalog — it builds whatever you describe.', 'wp-pfagent')
            : __('Ask it in plain language to manage your posts, media, users, comments and settings — and your WooCommerce, SEO and forms when those plugins are active. It proposes; you approve every change.', 'wp-pfagent');

        // Every dynamic value below is escaped at construction: the hero /
        // stat / footer builders run each interpolated part through esc_url(),
        // esc_attr(), esc_html() or esc_html__(), and the accent is a fixed
        // literal. Plugin Check cannot trace that through the returning
        // helpers, so the echo/printf sites are flagged; they are safe.
        // wp_kses_post() is not usable here — it would strip the inline style
        // and onmouseover/onmouseout hover handlers the widget relies on.
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="pf-dw" style="margin:-12px;padding:0;font-family:inherit;">';
        echo $this->hero_block(
            WP_PFAGENT_URL . 'assets/img/logo.png',
            $accent,
            $tagline,
            $blurb
        );

        echo '<div class="pf-dw-stats" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1px;background:#e0e0e0;border-top:1px solid #e0e0e0;border-bottom:1px solid #e0e0e0;">';
        echo $this->stat_cell((string) $conversations_30d, __('Conversations (30d)', 'wp-pfagent'), $admin_url);
        echo $this->stat_cell((string) $providers_configured, __('Providers configured', 'wp-pfagent'), $admin_url);
        echo $this->stat_cell($this->format_short($messages_30d), __('Messages (30d)', 'wp-pfagent'), $admin_url);
        echo '</div>';

        echo '<div class="pf-dw-actions" style="padding:14px 16px;display:flex;gap:8px;flex-wrap:wrap;">';
        printf(
            '<a class="button button-primary" href="%s" style="background:%s;border-color:%s;">%s</a>',
            esc_url($admin_url),
            esc_attr($accent),
            esc_attr($accent),
            esc_html__('Open agent', 'wp-pfagent')
        );
        echo '</div>';

        $footer = [];
        if ($has_management) {
            $footer[] = ['icon' => 'dashicons-database-view', 'label' => __('PF Management', 'wp-pfagent'), 'href' => admin_url('admin.php?page=pfm')];
        }
        if ($has_workflow) {
            $footer[] = ['icon' => 'dashicons-networking', 'label' => __('PF Workflow', 'wp-pfagent'), 'href' => admin_url('admin.php?page=wp-pfworkflow')];
        }
        if ($footer !== []) {
            echo $this->footer_links($footer);
        }

        echo '</div>';
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * @return array{0:int,1:int,2:int} [conversations_30d, providers_configured, messages_30d]
     */
    private function collect_stats(): array
    {
        global $wpdb;

        // Conversations + messages: both live in the pfaf_* tables. Guard
        // each lookup against a missing table so the widget renders zero
        // instead of throwing on a very fresh install (before activation
        // has run).
        $conversations_30d = $this->count_in_table_last_30d($wpdb->prefix . 'pfaf_conversations');
        $messages_30d = $this->count_in_table_last_30d($wpdb->prefix . 'pfaf_messages');

        // Providers: walk CredentialStore::statuses() (returns one entry
        // per known preset) and count the ones where the operator has
        // saved a credential. `configured === true` flips when there's
        // a stored API key, regardless of whether the connection has
        // been live-validated.
        $providers = 0;
        if (class_exists('\\ProjectFlash\\Agent\\CredentialStore')
            && class_exists('\\ProjectFlash\\Agent\\ProviderPresets')) {
            try {
                $store = new \ProjectFlash\Agent\CredentialStore(new \ProjectFlash\Agent\ProviderPresets());
                foreach ($store->statuses() as $row) {
                    if (is_array($row) && !empty($row['configured'])) {
                        $providers++;
                    }
                }
            } catch (\Throwable $e) {
                $providers = 0;
            }
        }

        return [$conversations_30d, $providers, $messages_30d];
    }

    private function count_in_table_last_30d(string $table): int
    {
        global $wpdb;
        $exists = (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        ) === $table;
        if (!$exists) {
            return 0;
        }
        // created_at is varchar(ISO) in pfaf_* tables, so use a
        // lexicographic compare against the threshold's ISO form.
        $threshold = gmdate('Y-m-d\TH:i:s', time() - 30 * 86400);
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE created_at >= %s",
            $threshold
        ));
    }

    /**
     * Compact large counters: 13.1K instead of 13101. Keeps the stat
     * cell readable under WP's narrow widget column.
     */
    private function format_short(int $n): string
    {
        if ($n < 1000) {
            return (string) $n;
        }
        if ($n < 1_000_000) {
            return number_format($n / 1000, $n < 10_000 ? 1 : 0, '.', '') . 'K';
        }
        return number_format($n / 1_000_000, 1, '.', '') . 'M';
    }

    private function hero_block(string $logo_url, string $accent, string $title, string $copy): string
    {
        $hero_bg = 'background:linear-gradient(135deg,' . $accent . '14,' . $accent . '05);';
        $out = '<div class="pf-dw-hero" style="padding:18px 16px 14px;text-align:center;' . esc_attr($hero_bg) . '">';
        $out .= '<img src="' . esc_url($logo_url) . '" alt="" style="width:64px;height:64px;object-fit:contain;margin-bottom:8px;display:inline-block;" />';
        $out .= '<h3 style="margin:0 0 6px;font-size:14px;color:#1d2327;">' . esc_html($title) . '</h3>';
        $out .= '<p style="margin:0;font-size:12px;color:#50575e;line-height:1.5;">' . esc_html($copy) . '</p>';
        $out .= '</div>';
        return $out;
    }

    private function stat_cell(string $value, string $label, string $href): string
    {
        $out = '<a href="' . esc_url($href) . '" class="pf-dw-stat" style="text-decoration:none;background:#fff;padding:10px 8px;text-align:center;color:inherit;transition:background 0.15s;" onmouseover="this.style.background=\'#f6f7f7\';" onmouseout="this.style.background=\'#fff\';">';
        $out .= '<div style="font-size:18px;font-weight:600;color:#1d2327;line-height:1;">' . esc_html($value) . '</div>';
        $out .= '<div style="font-size:11px;color:#646970;margin-top:4px;">' . esc_html($label) . '</div>';
        $out .= '</a>';
        return $out;
    }

    /**
     * @param array<int, array{icon:string,label:string,href:string}> $links
     */
    private function footer_links(array $links): string
    {
        $out = '<div class="pf-dw-footer" style="border-top:1px solid #e0e0e0;background:#f6f7f7;padding:8px 12px;display:flex;gap:14px;flex-wrap:wrap;font-size:12px;">';
        foreach ($links as $link) {
            $out .= sprintf(
                '<a href="%s" style="text-decoration:none;color:#2c3338;display:inline-flex;align-items:center;gap:4px;"><span class="dashicons %s" style="font-size:14px;width:14px;height:14px;"></span>%s</a>',
                esc_url($link['href']),
                esc_attr($link['icon']),
                esc_html($link['label'])
            );
        }
        $out .= '</div>';
        return $out;
    }
}
