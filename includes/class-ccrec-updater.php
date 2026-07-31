<?php
/**
 * CCREC_Updater
 *
 * Checks GitHub Releases for newer versions of this plugin and hooks into
 * WordPress's native update UI (Plugins screen, Dashboard > Updates).
 * No third-party service involved — calls the public GitHub Releases API directly.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Updater {

    const GITHUB_REPO   = 'Comms-Church/comms-church-recurring-events'; // owner/repo
    // Deliberately NOT prefixed 'ccrec_' — keeps the release cache outside
    // the plugin's data-cache namespace so content flushes never touch it.
    const CACHE_KEY      = 'ccrecupd_release';
    const CACHE_TTL       = DAY_IN_SECONDS;

    private $plugin_file;   // e.g. comms-church-recurring-events/comms-church-recurring-events.php
    private $plugin_slug;   // e.g. comms-church-recurring-events
    private $version;       // currently installed version

    public function __construct( $plugin_file, $version ) {
        $this->plugin_file = plugin_basename( $plugin_file );
        $this->plugin_slug = dirname( $this->plugin_file );
        $this->version     = $version;

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugins_api_handler' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_filter( 'plugin_row_meta', array( $this, 'add_check_now_link' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'maybe_manual_check' ) );
    }

    // -------------------------------------------------------------------------
    // Core update check
    // -------------------------------------------------------------------------

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_latest_release();
        if ( ! $release || empty( $release['tag_name'] ) ) return $transient;

        $remote_version = $this->normalize_version( $release['tag_name'] );

        $item = new stdClass();
        $item->slug         = $this->plugin_slug;
        $item->plugin       = $this->plugin_file;
        $item->new_version  = $remote_version;
        $item->url          = 'https://github.com/' . self::GITHUB_REPO;
        $item->package      = '';
        $item->tested       = get_bloginfo( 'version' );
        $item->requires     = '5.8';
        $item->requires_php = '7.4';
        $item->icons        = array();
        $item->banners      = array();

        if ( version_compare( $remote_version, $this->version, '>' ) ) {
            $zip_url = $this->get_zip_asset_url( $release );
            if ( ! $zip_url ) return $transient;

            $item->package = $zip_url;
            $transient->response[ $this->plugin_file ] = $item;
            unset( $transient->no_update[ $this->plugin_file ] );
        } else {
            // Populate no_update so WP's auto-update UI and other plugins
            // treat this plugin as "checked and current" rather than unknown.
            $transient->no_update[ $this->plugin_file ] = $item;
            unset( $transient->response[ $this->plugin_file ] );
        }

        return $transient;
    }

    // -------------------------------------------------------------------------
    // "View details" popup on the Plugins screen
    // -------------------------------------------------------------------------

    public function plugins_api_handler( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) return $result;

        $release = $this->get_latest_release();
        if ( ! $release ) return $result;

        $info               = new stdClass();
        $info->name         = 'Comms.Church — Planning Center Registrations';
        $info->slug         = $this->plugin_slug;
        $info->version      = $this->normalize_version( $release['tag_name'] );
        $info->author       = '<a href="https://comms.church">Comms.Church</a>';
        $info->homepage      = 'https://github.com/' . self::GITHUB_REPO;
        $info->sections     = array(
            'description' => wpautop( $release['body'] ?? 'See GitHub releases for changelog.' ),
            'changelog'   => wpautop( $release['body'] ?? '' ),
        );
        $info->download_link = $this->get_zip_asset_url( $release );

        return $info;
    }

    // -------------------------------------------------------------------------
    // Fix folder name after unzip (GitHub zips often unpack to repo-name-vX.X.X)
    // -------------------------------------------------------------------------

    public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
        global $wp_filesystem;

        if ( ! is_object( $upgrader ) || empty( $hook_extra['plugin'] ) ) return $source;
        if ( $hook_extra['plugin'] !== $this->plugin_file ) return $source;

        $desired = trailingslashit( $remote_source ) . $this->plugin_slug . '/';

        if ( trailingslashit( $source ) === $desired ) return $source;

        if ( $wp_filesystem->move( $source, $desired ) ) {
            return $desired;
        }

        return $source;
    }

    // -------------------------------------------------------------------------
    // Manual "Check for updates" link in plugin row
    // -------------------------------------------------------------------------

    public function add_check_now_link( $links, $plugin_file ) {
        if ( $plugin_file !== $this->plugin_file ) return $links;

        $url = wp_nonce_url(
            add_query_arg( array( 'ccrec_check_update' => 1 ), admin_url( 'plugins.php' ) ),
            'ccrec_check_update'
        );
        $links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'comms-church-recurring-events' ) . '</a>';
        return $links;
    }

    public function maybe_manual_check() {
        if ( empty( $_GET['ccrec_check_update'] ) ) return;
        if ( ! current_user_can( 'update_plugins' ) ) return;
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'ccrec_check_update' ) ) return;

        delete_transient( self::CACHE_KEY );
        delete_site_transient( 'update_plugins' );
        wp_safe_redirect( admin_url( 'plugins.php?ccrec_checked=1' ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // GitHub API
    // -------------------------------------------------------------------------

    private function get_latest_release() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) return $cached;

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
            array(
                'headers' => array( 'Accept' => 'application/vnd.github+json' ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) return false;

        set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
        return $data;
    }

    private function get_zip_asset_url( $release ) {
        if ( empty( $release['assets'] ) ) return false;
        foreach ( $release['assets'] as $asset ) {
            if ( str_ends_with( $asset['name'] ?? '', '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }
        return false;
    }

    private function normalize_version( $tag ) {
        return ltrim( $tag, 'vV' );
    }
}
