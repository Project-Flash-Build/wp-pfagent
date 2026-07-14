<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

use WP_Error;

/**
 * SEO adapter across the three popular engines — Yoast SEO, Rank Math,
 * SEOPress. Self-contained + PFA-owned: reads/writes each engine's own
 * post-meta directly (no dependency on PFW's SeoPluginAdapterHelper), so it
 * works with the suite absent. Only present when an SEO engine is active
 * (ThirdPartyPresence gates the tools). The engine is auto-detected.
 *
 * Fields: SEO title, meta description, focus keyword, canonical URL, robots
 * noindex, and Open Graph title/description. Robots noindex is normalised to a
 * boolean here and mapped to each engine's own storage on the way in/out.
 */
final class SeoAgentApi
{
    /** engine → [logical field => meta key]. `noindex` is handled specially. */
    private const FIELDS = [
        'yoast' => [
            'title'          => '_yoast_wpseo_title',
            'description'    => '_yoast_wpseo_metadesc',
            'focus_keyword'  => '_yoast_wpseo_focuskw',
            'canonical'      => '_yoast_wpseo_canonical',
            'og_title'       => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description',
        ],
        'rankmath' => [
            'title'          => 'rank_math_title',
            'description'    => 'rank_math_description',
            'focus_keyword'  => 'rank_math_focus_keyword',
            'canonical'      => 'rank_math_canonical_url',
            'og_title'       => 'rank_math_facebook_title',
            'og_description' => 'rank_math_facebook_description',
        ],
        'seopress' => [
            'title'          => '_seopress_titles_title',
            'description'    => '_seopress_titles_desc',
            'focus_keyword'  => '_seopress_analysis_target_kw',
            'canonical'      => '_seopress_robots_canonical',
            'og_title'       => '_seopress_social_fb_title',
            'og_description' => '_seopress_social_fb_desc',
        ],
    ];

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function seo_get(array $args)
    {
        $engine = ThirdPartyPresence::seo_engine();
        if ($engine === '') {
            return new WP_Error('seo_absent', __('No SEO plugin is installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('seo_forbidden', __('You cannot read this content.', 'wp-pfagent'), ['status' => 403]);
        }
        $out = ['engine' => $engine, 'postId' => $post->ID, 'postTitle' => get_the_title($post)];
        foreach (self::FIELDS[$engine] as $field => $key) {
            $out[self::OUT[$field]] = (string) get_post_meta($post->ID, $key, true);
        }
        $out['noindex'] = $this->get_noindex($engine, $post->ID);
        return $out;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function seo_set(array $args)
    {
        $engine = ThirdPartyPresence::seo_engine();
        if ($engine === '') {
            return new WP_Error('seo_absent', __('No SEO plugin is installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        $post = $this->resolve_post($args);
        if ($post instanceof WP_Error) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID)) {
            return new WP_Error('seo_forbidden', __('You cannot edit this content.', 'wp-pfagent'), ['status' => 403]);
        }
        $touched = [];
        foreach (self::FIELDS[$engine] as $field => $key) {
            if (array_key_exists($field, $args)) {
                $val = $field === 'canonical' ? esc_url_raw((string) $args[$field]) : sanitize_text_field((string) $args[$field]);
                update_post_meta($post->ID, $key, $val);
                $touched[] = $field;
            }
        }
        if (array_key_exists('noindex', $args)) {
            $this->set_noindex($engine, $post->ID, (bool) $args['noindex']);
            $touched[] = 'noindex';
        }
        if ($touched === []) {
            return new WP_Error('seo_invalid_args', __('Provide at least one SEO field to set (title, description, focus_keyword, canonical, noindex, og_title, og_description).', 'wp-pfagent'), ['status' => 400]);
        }
        return array_merge(['updated' => true, 'fields' => $touched], $this->seo_get(['post_id' => $post->ID]));
    }

    private function get_noindex(string $engine, int $id): bool
    {
        switch ($engine) {
            case 'yoast':
                return (string) get_post_meta($id, '_yoast_wpseo_meta-robots-noindex', true) === '1';
            case 'rankmath':
                $robots = get_post_meta($id, 'rank_math_robots', true);
                return is_array($robots) && in_array('noindex', $robots, true);
            case 'seopress':
                return (string) get_post_meta($id, '_seopress_robots_index', true) === 'yes';
        }
        return false;
    }

    private function set_noindex(string $engine, int $id, bool $noindex): void
    {
        switch ($engine) {
            case 'yoast':
                update_post_meta($id, '_yoast_wpseo_meta-robots-noindex', $noindex ? '1' : '2');
                break;
            case 'rankmath':
                update_post_meta($id, 'rank_math_robots', [$noindex ? 'noindex' : 'index']);
                break;
            case 'seopress':
                update_post_meta($id, '_seopress_robots_index', $noindex ? 'yes' : '');
                break;
        }
    }

    /** logical field → seo_get output key. */
    private const OUT = [
        'title'          => 'seoTitle',
        'description'    => 'metaDescription',
        'focus_keyword'  => 'focusKeyword',
        'canonical'      => 'canonical',
        'og_title'       => 'ogTitle',
        'og_description' => 'ogDescription',
    ];

    /** @return \WP_Post|WP_Error */
    private function resolve_post(array $args)
    {
        $id = 0;
        foreach (['post_id', 'id'] as $k) {
            if (isset($args[$k]) && is_numeric($args[$k])) { $id = (int) $args[$k]; break; }
        }
        if ($id > 0) {
            $post = get_post($id);
            if ($post instanceof \WP_Post) {
                return $post;
            }
        }
        return new WP_Error('seo_not_found', __('That content could not be found.', 'wp-pfagent'), ['status' => 404]);
    }
}
