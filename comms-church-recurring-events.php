<?php
/**
 * Plugin Name:       Comms.Church — Recurring Events for The Events Calendar
 * Plugin URI:        https://github.com/Comms-Church/comms-church-recurring-events
 * Description:       Repeating events for the free version of The Events Calendar. Set a repeat pattern on any event and this generates the individual dates as real events — no Events Calendar Pro licence required.
 * Version:           0.1.0
 * Author:            Comms.Church
 * Author URI:        https://comms.church
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       comms-church-recurring-events
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Comms.Church Recurring Events:</strong> requires PHP 7.4 or higher (running ' . esc_html( PHP_VERSION ) . ').</p></div>';
    } );
    add_action( 'admin_init', function () { deactivate_plugins( plugin_basename( __FILE__ ) ); } );
    return;
}

define( 'CCREC_VERSION', '0.1.0' );
define( 'CCREC_FILE', __FILE__ );
define( 'CCREC_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCREC_URL', plugin_dir_url( __FILE__ ) );

require_once CCREC_DIR . 'includes/class-ccrec-rule.php';
require_once CCREC_DIR . 'includes/class-ccrec-expander.php';

/*
 * The Events Calendar is a hard dependency: without it there is no event post type
 * to attach a repeat pattern to. Fail loudly in admin rather than half-working.
 */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'Tribe__Events__Main' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning"><p><strong>Comms.Church Recurring Events:</strong> '
                . esc_html__( 'The Events Calendar must be installed and active.', 'comms-church-recurring-events' )
                . '</p></div>';
        } );
        return;
    }

    require_once CCREC_DIR . 'includes/class-ccrec-generator.php';
    require_once CCREC_DIR . 'includes/class-ccrec-ecp-migrator.php';
    if ( is_admin() ) {
        require_once CCREC_DIR . 'includes/class-ccrec-metabox.php';
        new CCREC_Metabox();

        /* The migration screen only earns its menu slot when there is something to
           migrate — a church that never used Pro shouldn't see it at all. */
        require_once CCREC_DIR . 'includes/class-ccrec-migrate-screen.php';
        if ( CCREC_ECP_Migrator::find_candidates() ) {
            new CCREC_Migrate_Screen();
        }
    }
    new CCREC_Generator();
}, 20 );
