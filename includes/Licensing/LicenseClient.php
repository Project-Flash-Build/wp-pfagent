<?php

declare(strict_types=1);

namespace ProjectFlash\Licensing;

/**
 * ProjectFlash — shared license + update-checker drop-in.
 *
 * ONE self-contained file that every ProjectFlash plugin (WP-PFWorkflow,
 * WP-PFAgent, WP-PFManagement) bundles verbatim. It talks to the portal's
 * license/update server (`pfw-portal/v1`) and wires the NATIVE WordPress update
 * flow so the customer sees the standard "there is a new version — update" row
 * on Plugins.
 *
 * Scope (v1): update-NOTICE only. It never gates or disables features. With no
 * license key (or an inactive one) it simply offers no update and raises no
 * error — safe on dev / unlicensed sites.
 *
 * ── Adoption (each plugin, in its bootstrap) ──────────────────────────────
 *   if (!class_exists(\ProjectFlash\Licensing\LicenseClient::class)) {
 *       require_once __DIR__ . '/includes/Licensing/LicenseClient.php';
 *   }
 *   (new \ProjectFlash\Licensing\LicenseClient([
 *       'plugin_file' => __FILE__,          // main plugin file (for the update slug)
 *       'slug'        => 'wp-pfworkflow',    // portal product slug — MUST match the portal
 *       'name'        => 'WP-PFWorkflow',    // display name
 *       'version'     => PFW_VERSION,        // current installed version
 *       'option_key'  => 'pfw_license',      // wp_options key for stored state
 *       'text_domain' => 'wp-pfworkflow',
 *       'menu_parent' => 'pfw-workflow',     // parent admin menu slug (optional)
 *   ]))->register();
 *
 * The class is guarded by class_exists at require time, so whichever PF plugin
 * loads first defines it and the others reuse the same class. Each instance is
 * fully independent (its own slug / option / update hooks), so several PF
 * plugins share the code without colliding.
 *
 * Portal base URL: defaults to the production portal; override with the
 * `pf_license_portal_url` filter or a `PF_LICENSE_PORTAL_URL` constant (handy
 * for pointing a dev site at a local portal).
 */
final class LicenseClient
{
    /** Drop-in revision — bump when the shared file changes so adopters can tell copies apart. */
    public const DROPIN_VERSION = '1.4.0';

    /**
     * Shared master key (base64 of 32 bytes) for the offline ENCRYPTED license
     * token (PFL3.<b64url(nonce|tag|ciphertext)>). Symmetric: the same key the portal
     * encrypts with is embedded here so the plugin can DECRYPT the token LOCALLY and
     * activate with NO portal call.
     *
     * Crypto is OUR OWN pure-PHP AEAD — NO openssl, NO sodium, NO external lib. Only
     * the always-compiled hash extension + core CSPRNG: HKDF-SHA256 subkeys (salted
     * by the nonce), an HMAC-SHA256 CTR keystream for confidentiality, and an
     * encrypt-then-MAC HMAC-SHA256 tag (constant-time verified) for integrity. See
     * the portal's LicenseCipher for the mirror implementation.
     *
     * SECURITY: an embedded symmetric key is extractable from the shipped plugin, so
     * offline it only protects against casual tampering (the MAC), not a determined
     * forger who extracts the key. The ONLINE contrast (revalidation against the real
     * license record when the portal is reachable) is the actual backstop. This is
     * the operator's chosen model (self-decrypting key, our own crypto).
     */
    private const LICENSE_ENC_KEY = 'QH7UCry3cJG+JwbVa8MxwLA6yYhN9qy6d5LNDPLOKII=';
    /** Self-contained offline token format tag (encrypted). */
    private const TOKEN_PREFIX = 'PFL3';
    private const NONCE_LEN = 16;
    private const TAG_LEN   = 32;

    private const REST_NAMESPACE = 'pfw-portal/v1';
    private const DEFAULT_PORTAL = 'https://project-flash.com';
    /** How long a check-update result is cached (portal rate-limit is 60/min/IP; be gentle). */
    private const CHECK_TTL = 12 * HOUR_IN_SECONDS;
    /** Silent grace window (days) after a licence lapses for a site that was EVER licensed. */
    private const GRACE_DAYS = 30;

    private string $pluginFile;
    private string $pluginBasename;
    private string $slug;
    private string $name;
    private string $version;
    private string $optionKey;
    private string $textDomain;
    private string $menuParent;
    private string $menuTitle;

    /**
     * @param array{plugin_file:string, slug:string, name:string, version:string,
     *              option_key:string, text_domain?:string, menu_parent?:string,
     *              menu_title?:string} $config
     */
    public function __construct(array $config)
    {
        $this->pluginFile     = (string) ($config['plugin_file'] ?? '');
        $this->pluginBasename = $this->pluginFile !== '' ? plugin_basename($this->pluginFile) : '';
        $this->slug           = (string) ($config['slug'] ?? '');
        $this->name           = (string) ($config['name'] ?? $this->slug);
        $this->version        = (string) ($config['version'] ?? '0.0.0');
        $this->optionKey      = (string) ($config['option_key'] ?? ('pf_license_' . $this->slug));
        $this->textDomain     = (string) ($config['text_domain'] ?? 'default');
        $this->menuParent     = (string) ($config['menu_parent'] ?? '');
        $this->menuTitle      = (string) ($config['menu_title'] ?? __('License', 'default'));
    }

    // ── wiring ────────────────────────────────────────────────────────────

    public function register(): void
    {
        if ($this->slug === '' || $this->pluginBasename === '') {
            return; // misconfigured — do nothing rather than fatal.
        }

        // Native WP update flow.
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
        // Drop our cached result when WP clears its update cache (e.g. after an update).
        add_action('upgrader_process_complete', [$this, 'flush_cache']);

        // Daily background refresh: both the update-check and the license state.
        $cron = $this->cron_hook();
        add_action($cron, [$this, 'refresh_check']);
        add_action($cron, [$this, 'refresh_state']);
        if (!wp_next_scheduled($cron)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', $cron);
        }

        // License settings screen + form handler.
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_post_' . $this->action_slug(), [$this, 'handle_form']);

        // Publish the enforcement decision so the host plugin can gate its own
        // surfaces (dispatcher, REST, UI). Fired early on init; hosts add_action
        // on 'pf_license_gate_<slug>' and react to 'licensed' | 'grace' | 'cut'.
        add_action('init', function (): void {
            do_action('pf_license_gate_' . $this->slug, $this->gate_state(), $this);
        }, 5);
    }

    // ── identity / config helpers ───────────────────────────────────────────

    private function cron_hook(): string
    {
        return 'pf_license_check_' . str_replace('-', '_', $this->slug);
    }

    private function action_slug(): string
    {
        return 'pf_license_save_' . str_replace('-', '_', $this->slug);
    }

    private function page_slug(): string
    {
        return 'pf-license-' . $this->slug;
    }

    private function portal_base(): string
    {
        $base = defined('PF_LICENSE_PORTAL_URL') ? (string) PF_LICENSE_PORTAL_URL : self::DEFAULT_PORTAL;
        /** Filter the portal base URL (scheme + host, no trailing slash). */
        $base = (string) apply_filters('pf_license_portal_url', $base, $this->slug);
        return untrailingslashit($base);
    }

    private function rest_url(string $path): string
    {
        return $this->portal_base() . '/wp-json/' . self::REST_NAMESPACE . '/' . ltrim($path, '/');
    }

    /**
     * Opaque per-site fingerprint: sha256(home_url + a per-site random secret).
     * The secret is minted once and stored, so the fingerprint is stable across
     * activations on this site yet unique per install. The portal stores it at
     * activation and compares it on verify / update-check.
     */
    private function fingerprint(): string
    {
        $secretKey = $this->optionKey . '_fp_secret';
        $secret = get_option($secretKey, '');
        if (!is_string($secret) || $secret === '') {
            $secret = wp_generate_password(64, false, false);
            update_option($secretKey, $secret, false);
        }
        return hash('sha256', home_url('/') . '|' . $secret);
    }

    // ── stored state ────────────────────────────────────────────────────────

    /** @return array{key:string, status:string, site_match:bool, expires_at:string, last_activation:string, ever_licensed:bool, lapsed_at:string} */
    private function state(): array
    {
        $s = get_option($this->optionKey, []);
        if (!is_array($s)) {
            $s = [];
        }
        return [
            'key'             => (string) ($s['key'] ?? ''),
            'status'          => (string) ($s['status'] ?? 'inactive'),
            'site_match'      => (bool) ($s['site_match'] ?? false),
            'expires_at'      => (string) ($s['expires_at'] ?? ''),
            'last_activation' => (string) ($s['last_activation'] ?? ''),
            // Sticky: true once this site ever activated a valid licence. Drives
            // the grace window (ever-licensed lapse → 30d grace; never → cut).
            'ever_licensed'   => (bool) ($s['ever_licensed'] ?? false),
            // When a definitive lapse was first seen after being licensed.
            'lapsed_at'       => (string) ($s['lapsed_at'] ?? ''),
        ];
    }

    /** @param array<string,mixed> $patch */
    private function save_state(array $patch): void
    {
        $s = $this->state();
        update_option($this->optionKey, array_merge($s, $patch), false);
    }

    private function is_licensed(): bool
    {
        $s = $this->state();
        return $s['key'] !== '' && in_array($s['status'], ['active', 'grace'], true) && $s['site_match'];
    }

    // ── offline signed token (PFL1) ──────────────────────────────────────────

    /**
     * Decrypt a self-contained OFFLINE license token (PFL3.<b64url(nonce|tag|ct)>)
     * LOCALLY with the embedded master key, using our own pure-PHP AEAD (no openssl,
     * no sodium). Returns the decoded payload when the token decrypts, authenticates
     * (encrypt-then-MAC), and is bound to THIS product; null for anything else — a
     * legacy opaque key, a tampered/foreign-key token, or a wrong-product token.
     * NEVER makes a network call. Expiry is NOT checked here (the caller decides what
     * to do with an expired-but-valid token); `prod` and the MAC ARE checked.
     *
     * @return array<string,mixed>|null
     */
    public function decrypt_token_offline(string $key): ?array
    {
        $key = trim($key);
        $prefix = self::TOKEN_PREFIX . '.';
        if (strncmp($key, $prefix, strlen($prefix)) !== 0) {
            return null; // not a PFL3 token → legacy/opaque key, use the online path.
        }
        $enc = base64_decode(self::LICENSE_ENC_KEY, true);
        if (!is_string($enc) || strlen($enc) !== 32) {
            return null;
        }
        $blob = self::b64url_decode(substr($key, strlen($prefix)));
        // Need at least nonce(16) + tag(32) + 1 byte of ciphertext.
        if ($blob === null || strlen($blob) < self::NONCE_LEN + self::TAG_LEN + 1) {
            return null;
        }
        $nonce = substr($blob, 0, self::NONCE_LEN);
        $tag   = substr($blob, self::NONCE_LEN, self::TAG_LEN);
        $ct    = substr($blob, self::NONCE_LEN + self::TAG_LEN);
        // Encrypt-then-MAC: authenticate BEFORE decrypting, in constant time.
        $km   = hash_hkdf('sha256', $enc, 32, 'PFL3-mac', $nonce);
        $calc = hash_hmac('sha256', $nonce . $ct, $km, true);
        if (!hash_equals($calc, $tag)) {
            return null; // tampered / wrong key.
        }
        $ke   = hash_hkdf('sha256', $enc, 32, 'PFL3-enc', $nonce);
        $json = $ct ^ self::keystream($ke, $nonce, strlen($ct));
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return null;
        }
        // Product binding: the token must be for this plugin's product slug.
        if ((string) ($payload['prod'] ?? '') !== $this->slug) {
            return null;
        }
        return $payload;
    }

    /**
     * HMAC-SHA256 keystream in CTR mode — the decrypt mirror of the portal's
     * LicenseCipher::keystream(). block_i = HMAC(Ke, nonce || counter_i), truncated
     * to $len bytes.
     */
    private static function keystream(string $ke, string $nonce, int $len): string
    {
        $out = '';
        $counter = 0;
        while (strlen($out) < $len) {
            $out .= hash_hmac('sha256', $nonce . pack('N', $counter), $ke, true);
            $counter++;
        }
        return substr($out, 0, $len);
    }

    /** URL-safe base64 decode (tolerates missing padding), or null on failure. */
    private static function b64url_decode(string $s): ?string
    {
        $s = strtr($s, '-_', '+/');
        $mod = strlen($s) % 4;
        if ($mod > 0) {
            $s .= str_repeat('=', 4 - $mod);
        }
        $d = base64_decode($s, true);
        return is_string($d) ? $d : null;
    }

    // ── enforcement ─────────────────────────────────────────────────────────

    /**
     * Dev / test / portal bypass. Our own environments set the constant (via
     * WORDPRESS_CONFIG_EXTRA) so licensing never gets in the operator's way and
     * never touches cert / portal. Deliberately NOT tied to
     * wp_get_environment_type() — that would be a one-flag customer bypass.
     * Constant / env var / filter only.
     */
    public function is_dev_bypass(): bool
    {
        if (defined('PF_LICENSE_DEV_BYPASS') && PF_LICENSE_DEV_BYPASS) {
            return true;
        }
        $env = getenv('PF_LICENSE_DEV_BYPASS');
        if ($env !== false && $env !== '' && $env !== '0' && strtolower((string) $env) !== 'false') {
            return true;
        }
        return (bool) apply_filters('pf_license_dev_bypass', false, $this->slug);
    }

    /**
     * The enforcement decision: 'licensed' | 'grace' | 'cut'.
     *
     * grace is SILENT — the host treats it exactly like 'licensed' for feature
     * access and never surfaces it. FAIL-OPEN: the state is only ever driven to
     * 'cut' by a DEFINITIVE portal signal (not-licensed, or expired past the
     * grace window). A network blip never cuts, because the stored status does
     * not flip on transient errors (see refresh_state).
     */
    public function gate_state(): string
    {
        if ($this->is_dev_bypass()) {
            return 'licensed';
        }
        $s = $this->state();

        // OFFLINE encrypted token: decide entirely from the local token — no network.
        // Decrypts + authenticates for this product + not past the embedded expiry →
        // licensed, even with the portal unreachable. Past expiry → cut (the embedded
        // date is definitive; a renewal ships a new token to activate). An explicit
        // portal 'revoked' status (learned via the optional online contrast) overrides
        // a still-valid token. Legacy opaque keys fall through to the online logic.
        if ($s['key'] !== '') {
            $tok = $this->decrypt_token_offline($s['key']);
            if ($tok !== null) {
                if ($s['status'] === 'revoked') {
                    return 'cut';
                }
                $exp = (int) ($tok['exp'] ?? 0);
                return ($exp === 0 || time() < $exp) ? 'licensed' : 'cut';
            }
        }

        // Currently valid per the last stored verify → full function.
        if ($this->is_licensed()) {
            return 'licensed';
        }
        // Never licensed on this site → cut (the UI still offers onboarding).
        if (!$s['ever_licensed']) {
            return 'cut';
        }
        // Was licensed, not currently valid, but no definitive lapse recorded
        // (only transient/network failures seen) → fail-open to silent grace.
        if ($s['lapsed_at'] === '') {
            return 'grace';
        }
        $lapsed = strtotime($s['lapsed_at']);
        if ($lapsed === false) {
            return 'grace';
        }
        return (time() - $lapsed) <= self::GRACE_DAYS * DAY_IN_SECONDS ? 'grace' : 'cut';
    }

    /**
     * Refresh the stored licence state from the portal /verify. FAIL-OPEN: on a
     * network / parse error we DON'T touch status or lapsed_at (a blip must
     * never trigger a cut). Only a definitive portal response updates the state
     * and, when it reports not-licensed after the site was ever licensed, stamps
     * lapsed_at to start the silent grace clock.
     */
    public function refresh_state(): void
    {
        $s = $this->state();
        if ($s['key'] === '') {
            return; // nothing to verify.
        }
        $res = wp_remote_post($this->rest_url('licenses/' . rawurlencode($s['key']) . '/verify'), [
            'timeout' => 15,
            'body'    => ['site_fingerprint' => $this->fingerprint()],
        ]);
        $body = $this->parse_raw($res);
        if ($body === null) {
            return; // network / portal error → fail-open, keep prior state.
        }

        $status    = (string) ($body['license_status'] ?? ($body['status'] ?? ''));
        $siteMatch = ($body['site_match'] ?? null) !== false;
        $valid     = in_array($status, ['active', 'grace'], true) && $siteMatch;

        $patch = [
            'status'     => $status !== '' ? $status : $s['status'],
            'site_match' => $siteMatch,
            'expires_at' => (string) ($body['expires_at'] ?? $s['expires_at']),
        ];
        if ($valid) {
            $patch['ever_licensed'] = true;
            $patch['lapsed_at']     = ''; // recovered — clear any grace clock.
        } elseif ($s['ever_licensed'] && $s['lapsed_at'] === '') {
            $patch['lapsed_at'] = gmdate('c'); // definitive lapse → start grace.
        }
        $this->save_state($patch);
    }

    // ── portal calls ────────────────────────────────────────────────────────

    /**
     * @return array{ok:bool, error?:string, data?:array<string,mixed>}
     */
    private function activate(string $key): array
    {
        $res = wp_remote_post($this->rest_url('licenses/' . rawurlencode($key) . '/activate'), [
            'timeout' => 15,
            'body'    => [
                'site_url'         => home_url('/'),
                'site_fingerprint' => $this->fingerprint(),
                'wp_version'       => get_bloginfo('version'),
                'php_version'      => PHP_VERSION,
                'plugin_version'   => $this->version,
            ],
        ]);
        return $this->parse($res);
    }

    /**
     * @return array{ok:bool, error?:string, data?:array<string,mixed>}
     */
    private function deactivate(string $key): array
    {
        $res = wp_remote_post($this->rest_url('licenses/' . rawurlencode($key) . '/deactivate'), [
            'timeout' => 15,
            'body'    => ['site_fingerprint' => $this->fingerprint()],
        ]);
        return $this->parse($res);
    }

    /**
     * Ask the portal whether a newer release exists. Cached in a transient for
     * CHECK_TTL. Returns the decoded portal payload (or a safe no-update stub).
     *
     * @return array<string,mixed>
     */
    public function check_update(bool $force = false): array
    {
        $cacheKey = $this->optionKey . '_upd';
        if (!$force) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $stub = ['has_update' => false];
        $state = $this->state();
        if ($state['key'] === '') {
            set_transient($cacheKey, $stub, self::CHECK_TTL);
            return $stub; // unlicensed → no update, no error.
        }

        $url = add_query_arg([
            'license'          => $state['key'],
            'site_fingerprint' => $this->fingerprint(),
            'current_version'  => $this->version,
        ], $this->rest_url('updates/' . rawurlencode($this->slug)));

        $res = wp_remote_get($url, ['timeout' => 15]);
        $parsed = $this->parse_raw($res);
        if (!is_array($parsed)) {
            // Network / portal error — cache the stub briefly so we retry soon.
            set_transient($cacheKey, $stub, HOUR_IN_SECONDS);
            return $stub;
        }

        set_transient($cacheKey, $parsed, self::CHECK_TTL);
        return $parsed;
    }

    /**
     * Normalise a portal response that uses the {status, data|error} envelope.
     *
     * @param mixed $res
     * @return array{ok:bool, error?:string, data?:array<string,mixed>}
     */
    private function parse($res): array
    {
        $body = $this->parse_raw($res);
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'network_error'];
        }
        if (($body['status'] ?? '') === 'ok') {
            return ['ok' => true, 'data' => is_array($body['data'] ?? null) ? $body['data'] : $body];
        }
        return ['ok' => false, 'error' => (string) ($body['error'] ?? 'unknown_error')];
    }

    /**
     * Decode a wp_remote_* response body to an array, or null on any failure.
     *
     * @param mixed $res
     * @return array<string,mixed>|null
     */
    private function parse_raw($res): ?array
    {
        if (is_wp_error($res)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $decoded = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($decoded) ? $decoded : null;
    }

    // ── native WP update flow ────────────────────────────────────────────────

    /**
     * Inject our update into the plugins update transient when the portal
     * reports a newer version with a signed download URL.
     *
     * @param mixed $transient
     * @return mixed
     */
    public function inject_update($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $info = $this->check_update();
        if (empty($info['has_update']) || empty($info['download_url']) || empty($info['latest_version'])) {
            // No update (or notice-only degrade): make sure we don't leave a stale offer.
            if (isset($transient->response[$this->pluginBasename])) {
                unset($transient->response[$this->pluginBasename]);
            }
            return $transient;
        }

        $update = (object) [
            'slug'        => $this->slug,
            'plugin'      => $this->pluginBasename,
            'new_version' => (string) $info['latest_version'],
            'url'         => $this->portal_base(),
            'package'     => (string) $info['download_url'],
            'tested'      => (string) ($info['tested'] ?? ''),
            'requires'    => (string) ($info['requires'] ?? ''),
            'requires_php' => (string) ($info['requires_php'] ?? ''),
        ];
        $transient->response[$this->pluginBasename] = $update;
        return $transient;
    }

    /**
     * Supply the "View version details" modal content.
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $info = $this->check_update();
        $latest = (string) ($info['latest_version'] ?? $this->version);

        return (object) [
            'name'          => $this->name,
            'slug'          => $this->slug,
            'version'       => $latest,
            'requires'      => (string) ($info['requires'] ?? ''),
            'tested'        => (string) ($info['tested'] ?? ''),
            'requires_php'  => (string) ($info['requires_php'] ?? ''),
            'download_link' => (string) ($info['download_url'] ?? ''),
            'sections'      => [
                'description' => sprintf(
                    /* translators: %s: plugin name */
                    esc_html__('%s is distributed and updated through the Project Flash portal. Updates require an active license.', $this->textDomain),
                    esc_html($this->name)
                ),
            ],
        ];
    }

    public function flush_cache(): void
    {
        delete_transient($this->optionKey . '_upd');
    }

    public function refresh_check(): void
    {
        $this->check_update(true);
    }

    // ── admin: license settings ──────────────────────────────────────────────

    public function admin_menu(): void
    {
        $cap = 'manage_options';
        if ($this->menuParent !== '') {
            add_submenu_page(
                $this->menuParent,
                $this->name . ' — ' . $this->menuTitle,
                $this->menuTitle,
                $cap,
                $this->page_slug(),
                [$this, 'render_page']
            );
        } else {
            add_options_page(
                $this->name . ' — ' . $this->menuTitle,
                $this->name . ' ' . $this->menuTitle,
                $cap,
                $this->page_slug(),
                [$this, 'render_page']
            );
        }
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = $this->state();
        $masked = $state['key'] !== ''
            ? str_repeat('•', max(0, strlen($state['key']) - 4)) . substr($state['key'], -4)
            : '';
        $notice = isset($_GET['pf_lic']) ? sanitize_key((string) wp_unslash($_GET['pf_lic'])) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->name . ' — ' . $this->menuTitle) . '</h1>';

        if ($notice !== '') {
            $map = [
                'activated'   => [__('License activated. This site will now receive updates.', $this->textDomain), 'success'],
                'deactivated' => [__('License deactivated on this site.', $this->textDomain), 'info'],
                'error'       => [__('Could not reach the license server or the key was rejected. Check the key and try again.', $this->textDomain), 'error'],
            ];
            if (isset($map[$notice])) {
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr($map[$notice][1]),
                    esc_html($map[$notice][0])
                );
            }
        }

        $isLicensed = $this->is_licensed();
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Status', $this->textDomain) . '</th><td>';
        if ($isLicensed) {
            echo '<span style="color:#008a20;font-weight:600;">● ' . esc_html__('Active', $this->textDomain) . '</span>';
            if ($state['expires_at'] !== '') {
                echo ' <span class="description">' . sprintf(
                    /* translators: %s: expiry date */
                    esc_html__('(renews / expires %s)', $this->textDomain),
                    esc_html($state['expires_at'])
                ) . '</span>';
            }
        } else {
            echo '<span style="color:#b32d2e;font-weight:600;">● ' . esc_html__('Not active', $this->textDomain) . '</span>';
            echo ' <span class="description">' . esc_html__('Enter your license key to receive updates.', $this->textDomain) . '</span>';
        }
        echo '</td></tr>';
        echo '</tbody></table>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr($this->action_slug()) . '">';
        wp_nonce_field($this->action_slug());
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="pf_license_key">' . esc_html__('License key', $this->textDomain) . '</label></th><td>';
        echo '<input name="pf_license_key" id="pf_license_key" type="text" class="regular-text" autocomplete="off" placeholder="PFW-XXXX-XXXX-XXXX" value="' . esc_attr($isLicensed ? $masked : $state['key']) . '"' . ($isLicensed ? ' readonly' : '') . '>';
        echo '</td></tr>';
        echo '</tbody></table>';

        if ($isLicensed) {
            submit_button(__('Deactivate on this site', $this->textDomain), 'secondary', 'pf_license_deactivate', false);
        } else {
            submit_button(__('Activate license', $this->textDomain), 'primary', 'pf_license_activate', false);
        }
        echo '</form>';
        echo '</div>';
    }

    public function handle_form(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', $this->textDomain));
        }
        check_admin_referer($this->action_slug());

        $redirect = add_query_arg('page', $this->page_slug(), admin_url($this->menuParent !== '' ? 'admin.php' : 'options-general.php'));

        // Deactivate.
        if (isset($_POST['pf_license_deactivate'])) {
            $state = $this->state();
            if ($state['key'] !== '') {
                $this->deactivate($state['key']);
            }
            update_option($this->optionKey, ['key' => '', 'status' => 'inactive', 'site_match' => false], false);
            $this->flush_cache();
            wp_safe_redirect(add_query_arg('pf_lic', 'deactivated', $redirect));
            exit;
        }

        // Activate.
        $key = isset($_POST['pf_license_key']) ? trim(sanitize_text_field((string) wp_unslash($_POST['pf_license_key']))) : '';
        if ($key === '') {
            wp_safe_redirect(add_query_arg('pf_lic', 'error', $redirect));
            exit;
        }

        // OFFLINE encrypted token: decrypt locally — activation succeeds with the
        // portal unreachable (the whole point of the offline license). Only a token
        // that decrypts + authenticates for THIS product, in-date, activates; an
        // expired or tampered one is rejected like a bad key. Legacy opaque keys fall
        // through to the online activate below.
        $tok = $this->decrypt_token_offline($key);
        if ($tok !== null) {
            $exp = (int) ($tok['exp'] ?? 0);
            if ($exp !== 0 && time() >= $exp) {
                wp_safe_redirect(add_query_arg('pf_lic', 'error', $redirect)); // expired token.
                exit;
            }
            $this->save_state([
                'key'             => $key,
                'status'          => 'active',
                'site_match'      => true,
                'expires_at'      => $exp > 0 ? gmdate('c', $exp) : '',
                'last_activation' => gmdate('c'),
                'ever_licensed'   => true,
                'lapsed_at'       => '',
            ]);
            $this->flush_cache();
            // Best-effort online: register the site fingerprint for seat tracking /
            // revocation. Ignored on failure — activation already succeeded offline.
            $this->activate($key);
            $this->check_update(true);
            wp_safe_redirect(add_query_arg('pf_lic', 'activated', $redirect));
            exit;
        }

        $act = $this->activate($key);
        if (!$act['ok']) {
            wp_safe_redirect(add_query_arg('pf_lic', 'error', $redirect));
            exit;
        }

        // Confirm status + product + site match via verify.
        $verifyRes = wp_remote_post($this->rest_url('licenses/' . rawurlencode($key) . '/verify'), [
            'timeout' => 15,
            'body'    => ['site_fingerprint' => $this->fingerprint()],
        ]);
        $verify = $this->parse_raw($verifyRes) ?? [];

        $this->save_state([
            'key'             => $key,
            'status'          => (string) ($verify['license_status'] ?? 'active'),
            'site_match'      => ($verify['site_match'] ?? true) !== false,
            'expires_at'      => (string) ($verify['expires_at'] ?? ''),
            'last_activation' => gmdate('c'),
            'ever_licensed'   => true, // sticky — enables the grace window on a future lapse.
            'lapsed_at'       => '',
        ]);
        $this->flush_cache();
        $this->check_update(true); // warm the cache so the update row can appear immediately.

        wp_safe_redirect(add_query_arg('pf_lic', 'activated', $redirect));
        exit;
    }
}
