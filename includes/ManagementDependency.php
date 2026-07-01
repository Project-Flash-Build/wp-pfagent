<?php

declare(strict_types=1);

namespace ProjectFlash\Agent;

/**
 * Mirror of WorkflowDependency for the wp-pfmanagement (pfm) plugin.
 *
 * Reports whether pfm is installed/active, exposes its admin URL so the
 * pfa SPA can render a non-destructive iframe of the pfm UI, and surfaces
 * the REST namespace for any future direct calls.
 */
final class ManagementDependency
{
    private const FALLBACK_REST_NAMESPACE = 'pfm/v1';
    private const ADMIN_MENU_SLUG = 'pfm';

    public static function is_active(): bool
    {
        return defined('PFM_REST_NAMESPACE')
            || class_exists('\\ProjectFlash\\Management\\Plugin');
    }

    public static function rest_namespace(): string
    {
        if (defined('PFM_REST_NAMESPACE')) {
            return (string) PFM_REST_NAMESPACE;
        }

        return self::FALLBACK_REST_NAMESPACE;
    }

    public static function admin_url(): string
    {
        return esc_url_raw(admin_url('admin.php?page=' . self::ADMIN_MENU_SLUG));
    }

    /**
     * @return array<string, mixed>
     */
    public static function status_payload(): array
    {
        $namespace = self::rest_namespace();

        return [
            'active' => self::is_active(),
            'namespace' => $namespace,
            'restUrl' => esc_url_raw(rest_url($namespace . '/')),
            'adminUrl' => self::admin_url(),
            'source' => defined('PFM_REST_NAMESPACE') ? 'management_constant' : 'fallback',
        ];
    }
}
