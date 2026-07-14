<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

/**
 * Presence detection for the popular third-party plugins the lean v1 layer
 * touches. Self-contained + PFA-owned: it never calls into PFW's
 * IntegrationDiscoveryHelper (that would couple this module to the suite and
 * break the "works without PFM/PFW" contract). Detection is by each plugin's
 * own public constant / class, exactly like the suite's discovery does but
 * without depending on it.
 *
 * `has()` gates tool availability (AgentToolRegistry) so a wc_ / seo_ / forms_
 * tool only appears to the agent when its family of plugins is actually
 * present — no dangling tools for absent plugins.
 */
final class ThirdPartyPresence
{
    public static function has(string $family): bool
    {
        switch ($family) {
            case 'woocommerce':
                return class_exists('WooCommerce');
            case 'seo':
                return defined('WPSEO_VERSION')
                    || defined('RANK_MATH_VERSION')
                    || defined('SEOPRESS_VERSION')
                    || function_exists('seopress_get_service');
            case 'forms':
                return defined('WPCF7_VERSION')
                    || defined('FLUENTFORM')
                    || class_exists('GFAPI')
                    || defined('WPFORMS_VERSION');
            case 'lms':
                return defined('LEARNDASH_VERSION');
            case 'membership':
                return defined('MEPR_VERSION') || class_exists('MeprAppCtrl');
            default:
                return false;
        }
    }

    /**
     * The active SEO engine, in a stable preference order. Returns '' when
     * none is present.
     */
    public static function seo_engine(): string
    {
        if (defined('WPSEO_VERSION')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION')) {
            return 'rankmath';
        }
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_service')) {
            return 'seopress';
        }
        return '';
    }
}
