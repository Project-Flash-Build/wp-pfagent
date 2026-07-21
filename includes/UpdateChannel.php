<?php
/**
 * Canal de actualización de WP-PFAgent — enganche al mecanismo ESTÁNDAR de
 * WordPress (cabecera `Update URI:` + filtro `update_plugins_<host>`), gemelo
 * funcional del de WP-PFWorkflow / WP-PFManagement, pero SIN NADA de licencias.
 *
 * PFAgent es open source (GPL-2.0) y no lleva LicenseClient: no hay clave que
 * enviar. Aun así el canal necesita una IDENTIDAD para repartir el turno del
 * despacho escalonado — el censo de `check.php` responde la versión instalada
 * («todavía no hay novedad») a quien manda una identidad vacía, así que sin
 * identidad un sitio NUNCA vería una versión nueva. Por eso este cliente acuña
 * una identidad ANÓNIMA y estable por sitio: un id aleatorio de una sola vez,
 * guardado en una opción. No dice quién es nadie ni acredita derecho a nada;
 * solo da un asa estable para el escalonado.
 *
 * El resto del ciclo es idéntico al de los plugins de pago:
 *   1. El core dispara el filtro; se consulta `check.php` por POST.
 *   2. El canal contesta la versión que le toca a ESTE sitio (o la propia
 *      instalada si el escalonado dice «todavía no»): silencio limpio.
 *   3. El `package` devuelto NO lleva token; al pulsar «Actualizar»,
 *      `upgrader_pre_download` acuña un enlace de un solo uso y descarga.
 *
 * @see https://make.wordpress.org/core/2021/06/29/introducing-update-uri-plugin-header-in-wordpress-5-8/
 */

declare(strict_types=1);

namespace ProjectFlash\Agent;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(__NAMESPACE__ . '\\UpdateChannel')) {

final class UpdateChannel
{
    /**
     * Host del canal. Única fuente de esa cadena: coincide carácter a carácter
     * con el host de la cabecera `Update URI:` del fichero principal, porque de
     * él sale el nombre del filtro que dispara el core. Una mudanza de host
     * obliga a escuchar los dos durante toda la transición (los sitios con la
     * versión vieja siguen declarando el host viejo).
     */
    public const HOST = 'updates.setyenv.com';

    /** Opción donde vive la identidad anónima y estable del sitio. */
    private const IDENTITY_OPTION = 'wp_pfagent_channel_site_id';

    /** Segundos de espera con el canal. Corto: el chequeo corre en la carga del
     *  admin y un canal caído no puede colgar el escritorio de nadie. */
    private const CHECK_TIMEOUT = 5;

    /** @var array<string, array{slug:string, version:string, domain:string}> por basename */
    private static array $plugins = [];

    private static bool $hooked = false;

    /**
     * Registra el plugin en el canal.
     *
     * @param string $plugin_file ruta absoluta del fichero principal (__FILE__)
     * @param string $slug        slug del plugin (wp-pfagent)
     * @param string $version     versión instalada
     * @param string $text_domain dominio de texto para los mensajes de descarga
     */
    public static function register(string $plugin_file, string $slug, string $version, string $text_domain = 'wp-pfagent'): void
    {
        self::$plugins[plugin_basename($plugin_file)] = [
            'slug'    => $slug,
            'version' => $version,
            'domain'  => $text_domain,
        ];

        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        add_filter('update_plugins_' . self::HOST, [self::class, 'check'], 10, 3);
        add_filter('upgrader_pre_download', [self::class, 'pre_download'], 10, 4);
    }

    /**
     * Identidad ANÓNIMA y estable del sitio ante el canal.
     *
     * No es una clave de licencia (no la hay): es un id aleatorio acuñado una
     * sola vez y guardado, de modo que el escalonado reparta turnos de forma
     * estable entre instalaciones distintas sin identificar a nadie. Se mina de
     * forma perezosa la primera vez que el canal lo necesita.
     */
    private static function identity(): string
    {
        $id = get_option(self::IDENTITY_OPTION, '');
        if (is_string($id) && $id !== '') {
            return $id;
        }
        // 32 hex de aleatoriedad criptográfica; una sola vez por sitio.
        try {
            $id = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            $id = md5((string) home_url('/') . '|' . (string) wp_rand());
        }
        update_option(self::IDENTITY_OPTION, $id, false);
        return $id;
    }

    /**
     * ¿Tiene este sitio la actualización automática puesta para ESTE plugin?
     *
     * El canal lo necesita para el censo: solo se escalona entre quienes se
     * actualizan solos, y cuando esa cohorte está al día la versión se libera a
     * todos. El core exige DOS cosas (`WP_Automatic_Updater::should_update()`):
     * que las automáticas estén habilitadas globalmente Y que el plugin esté en
     * la lista. Mirar solo la lista contaría como cohorte a un sitio con las
     * automáticas apagadas del todo, que nunca se actualizaría.
     */
    private static function auto_update_enabled(string $plugin_file): bool
    {
        if (!function_exists('wp_is_auto_update_enabled_for_type')) {
            $file = ABSPATH . 'wp-admin/includes/update.php';
            if (!is_readable($file)) {
                return false;
            }
            require_once $file;
        }
        if (!wp_is_auto_update_enabled_for_type('plugin')) {
            return false;
        }
        return in_array($plugin_file, (array) get_site_option('auto_update_plugins', []), true);
    }

    /**
     * Respuesta del canal para ESTE plugin. El filtro se dispara por host, así
     * que se filtra por `$plugin_file` y se devuelve intacto lo ajeno.
     *
     * @param array|false $update
     * @param array       $plugin_data
     * @param string      $plugin_file basename
     * @return array|false
     */
    public static function check($update, array $plugin_data, string $plugin_file)
    {
        unset($plugin_data);

        if (!isset(self::$plugins[$plugin_file])) {
            return $update;
        }
        $me = self::$plugins[$plugin_file];

        $response = wp_remote_post('https://' . self::HOST . '/check.php', [
            'timeout' => self::CHECK_TIMEOUT,
            'body'    => [
                'slug'    => $me['slug'],
                'version' => $me['version'],
                'id'      => self::identity(),
                'auto'    => self::auto_update_enabled($plugin_file) ? '1' : '0',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['version'])) {
            return false;
        }

        return [
            'slug'         => $me['slug'],
            'plugin'       => $plugin_file,
            'version'      => (string) $data['version'],
            'url'          => (string) ($data['url'] ?? ''),
            'package'      => (string) ($data['package'] ?? ''),
            'tested'       => (string) ($data['tested'] ?? ''),
            'requires_php' => (string) ($data['requires_php'] ?? ''),
        ];
    }

    /**
     * Descarga: aquí se acuña el enlace. El `package` del chequeo NO lleva
     * token (WordPress cachea esa respuesta ~12 h y los enlaces son de un solo
     * uso y vida corta); se pide en el instante de descargar.
     *
     * @param bool|\WP_Error $reply
     * @param string         $package
     * @return bool|string|\WP_Error
     */
    public static function pre_download($reply, $package, $upgrader = null, $hook_extra = [])
    {
        unset($upgrader);
        if (!is_string($package) || strpos($package, 'https://' . self::HOST . '/token.php') !== 0) {
            return $reply;
        }

        $plugin_file = (string) ($hook_extra['plugin'] ?? '');
        $me = self::$plugins[$plugin_file] ?? null;
        if ($me === null) {
            return $reply;
        }

        $query = [];
        parse_str((string) parse_url($package, PHP_URL_QUERY), $query);

        $minted = wp_remote_post('https://' . self::HOST . '/token.php', [
            'timeout' => 15,
            'body'    => [
                'slug'    => $me['slug'],
                'version' => (string) ($query['version'] ?? ''),
                'id'      => self::identity(),
            ],
        ]);
        if (is_wp_error($minted)) {
            return $minted;
        }
        // 404 = versión inexistente (no reintentar); 503 = «ahora no, vuelve luego».
        $status = (int) wp_remote_retrieve_response_code($minted);
        if ($status !== 200) {
            $message = $status === 503
                ? __('The update channel is busy right now. Please try again in a minute.', $me['domain'])
                : __('This update is not available from the update channel.', $me['domain']);
            return new \WP_Error('setyenv_update_unavailable', $message, ['code' => $status]);
        }
        $body = json_decode((string) wp_remote_retrieve_body($minted), true);
        if (!is_array($body) || empty($body['url'])) {
            return new \WP_Error('setyenv_update_unavailable', __('The update channel returned no download link.', $me['domain']));
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        // El 503 del semáforo llega POR AQUÍ (token.php no tiene semáforo): sin
        // traducir, el core enseña «Update failed: Service Unavailable». download_url
        // empaqueta el código HTTP en los datos del error (usa http_404 para todo
        // lo que no sea 200, así que hay que mirar el dato, no el nombre).
        $file = download_url((string) $body['url']);
        if (is_wp_error($file)) {
            $data = $file->get_error_data();
            if (is_array($data) && (int) ($data['code'] ?? 0) === 503) {
                return new \WP_Error(
                    'setyenv_update_busy',
                    __('The update channel is busy right now. Please try again in a minute.', $me['domain'])
                );
            }
        }

        return $file;
    }
}

}
