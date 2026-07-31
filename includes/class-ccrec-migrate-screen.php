<?php
/**
 * CCREC_Migrate_Screen — Events → Migrate from Pro.
 *
 * The screen is a dry run by default and stays that way until someone clicks a specific
 * button. Migration rewrites a church's live calendar, so the order is deliberately:
 * see the exact dates first, migrate one event to build confidence, then do the rest.
 *
 * Every destructive action is nonced, capability-checked, and individually reversible.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Migrate_Screen {

    const SLUG = 'ccrec-migrate';
    const CAP  = 'manage_options';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_ccrec_migrate', array( $this, 'handle' ) );
    }

    public function menu() {
        add_submenu_page(
            'edit.php?post_type=tribe_events',
            __( 'Migrate from Events Calendar Pro', 'comms-church-recurring-events' ),
            __( 'Migrate from Pro', 'comms-church-recurring-events' ),
            self::CAP,
            self::SLUG,
            array( $this, 'render' )
        );
    }

    // ---- actions ------------------------------------------------------------

    public function handle() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'comms-church-recurring-events' ) );
        }
        check_admin_referer( 'ccrec_migrate' );

        $action  = isset( $_POST['ccrec_action'] ) ? sanitize_text_field( wp_unslash( $_POST['ccrec_action'] ) ) : '';
        $id      = isset( $_POST['event_id'] ) ? (int) $_POST['event_id'] : 0;
        $detach  = ! empty( $_POST['detach'] );
        $msg     = '';

        if ( 'migrate_one' === $action && $id ) {
            $r   = CCREC_ECP_Migrator::migrate( $id, $detach );
            $msg = $r['ok']
                ? sprintf( __( 'Migrated "%1$s" — %2$d dates created.', 'comms-church-recurring-events' ), get_the_title( $id ), $r['created'] )
                : sprintf( __( 'Could not migrate "%1$s": %2$s', 'comms-church-recurring-events' ), get_the_title( $id ), $r['note'] );

        } elseif ( 'migrate_all' === $action ) {
            $done = 0; $dates = 0; $skipped = 0;
            foreach ( CCREC_ECP_Migrator::preview() as $row ) {
                if ( 'ready' !== $row['status'] ) { $skipped++; continue; }
                $r = CCREC_ECP_Migrator::migrate( $row['id'], $detach );
                if ( $r['ok'] ) { $done++; $dates += $r['created']; }
            }
            $msg = sprintf(
                __( 'Migrated %1$d events (%2$d dates created). %3$d left alone.', 'comms-church-recurring-events' ),
                $done, $dates, $skipped
            );

        } elseif ( 'rollback' === $action && $id ) {
            $msg = CCREC_ECP_Migrator::rollback( $id )
                ? sprintf( __( 'Rolled back "%s" — generated dates removed and the Pro rule restored.', 'comms-church-recurring-events' ), get_the_title( $id ) )
                : __( 'Nothing to roll back for that event.', 'comms-church-recurring-events' );
        }

        wp_safe_redirect( add_query_arg(
            array( 'post_type' => 'tribe_events', 'page' => self::SLUG, 'ccrec_msg' => rawurlencode( $msg ) ),
            admin_url( 'edit.php' )
        ) );
        exit;
    }

    // ---- screen -------------------------------------------------------------

    public function render() {
        if ( ! current_user_can( self::CAP ) ) return;

        $rows      = CCREC_ECP_Migrator::preview();
        $ready     = array_filter( $rows, function ( $r ) { return 'ready' === $r['status']; } );
        $blocked   = array_filter( $rows, function ( $r ) { return 'unsupported' === $r['status']; } );
        $migrated  = array_filter( $rows, function ( $r ) { return 'already migrated' === $r['status']; } );
        $pro_on    = class_exists( 'Tribe__Events__Pro__Main' ) || defined( 'EVENTS_CALENDAR_PRO_FILE' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Migrate from Events Calendar Pro', 'comms-church-recurring-events' ); ?></h1>

            <?php if ( ! empty( $_GET['ccrec_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php echo esc_html( rawurldecode( wp_unslash( $_GET['ccrec_msg'] ) ) ); ?>
                </p></div>
            <?php endif; ?>

            <p class="description" style="max-width:760px">
                <?php esc_html_e( 'This converts repeating events managed by Events Calendar Pro into individual events managed by this plugin. Each date becomes an ordinary event, so the calendar keeps working if Pro is ever removed.', 'comms-church-recurring-events' ); ?>
            </p>

            <?php if ( ! $rows ) : ?>
                <div class="notice notice-info inline"><p>
                    <?php esc_html_e( 'No events with a Pro recurrence rule were found. Nothing to migrate.', 'comms-church-recurring-events' ); ?>
                </p></div>
                <?php return; ?>
            <?php endif; ?>

            <div class="notice notice-warning inline" style="max-width:760px">
                <p><strong><?php esc_html_e( 'Before you start', 'comms-church-recurring-events' ); ?></strong></p>
                <ul style="list-style:disc;margin-left:20px">
                    <li><?php esc_html_e( 'Take a database backup. This creates and deletes events.', 'comms-church-recurring-events' ); ?></li>
                    <li><?php esc_html_e( 'Check the dates below first, then migrate one event and look at your calendar before doing the rest.', 'comms-church-recurring-events' ); ?></li>
                    <li><?php esc_html_e( 'Past dates are never created. Only upcoming ones are generated.', 'comms-church-recurring-events' ); ?></li>
                    <?php if ( $pro_on ) : ?>
                        <li><?php esc_html_e( 'Events Calendar Pro is still active. Leave it on until you have checked the results — you can deactivate it afterwards.', 'comms-church-recurring-events' ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>

            <h2><?php printf( esc_html__( 'Found %d repeating events', 'comms-church-recurring-events' ), count( $rows ) ); ?></h2>
            <p>
                <?php printf(
                    esc_html__( '%1$d ready to migrate · %2$d need attention · %3$d already migrated', 'comms-church-recurring-events' ),
                    count( $ready ), count( $blocked ), count( $migrated )
                ); ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:26%"><?php esc_html_e( 'Event', 'comms-church-recurring-events' ); ?></th>
                        <th style="width:20%"><?php esc_html_e( 'Pro rule', 'comms-church-recurring-events' ); ?></th>
                        <th style="width:14%"><?php esc_html_e( 'Status', 'comms-church-recurring-events' ); ?></th>
                        <th><?php esc_html_e( 'What will happen', 'comms-church-recurring-events' ); ?></th>
                        <th style="width:130px"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td>
                            <strong><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ?: '(untitled)' ); ?></a></strong><br>
                            <small><?php echo esc_html( $row['start'] ); ?></small>
                        </td>
                        <td><code style="font-size:11px"><?php echo esc_html( $row['ecp'] ); ?></code></td>
                        <td>
                            <?php
                            $colors = array( 'ready' => '#00733f', 'unsupported' => '#b32d2e', 'already migrated' => '#646970' );
                            printf(
                                '<span style="color:%s;font-weight:600">%s</span>',
                                esc_attr( $colors[ $row['status'] ] ?? '#646970' ),
                                esc_html( $row['status'] )
                            );
                            ?>
                        </td>
                        <td>
                            <?php if ( 'ready' === $row['status'] ) : ?>
                                <?php printf(
                                    esc_html( _n( '%d date will be created:', '%d dates will be created:', count( $row['dates'] ), 'comms-church-recurring-events' ) ),
                                    count( $row['dates'] )
                                ); ?>
                                <details style="margin-top:4px">
                                    <summary style="cursor:pointer"><?php esc_html_e( 'show dates', 'comms-church-recurring-events' ); ?></summary>
                                    <ul style="margin:6px 0 0 16px;list-style:disc;font-size:12px">
                                        <?php foreach ( $row['dates'] as $d ) : ?>
                                            <li><?php echo esc_html( date_i18n( 'D, M j Y — g:ia', strtotime( $d ) ) ); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                                <?php if ( $row['note'] ) : ?>
                                    <p class="description" style="margin-top:4px"><?php echo esc_html( $row['note'] ); ?></p>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="description"><?php echo esc_html( $row['note'] ?: __( 'Already converted.', 'comms-church-recurring-events' ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( 'ready' === $row['status'] ) : ?>
                                <?php $this->button( 'migrate_one', $row['id'], __( 'Migrate', 'comms-church-recurring-events' ), 'button-primary' ); ?>
                            <?php elseif ( 'already migrated' === $row['status'] ) : ?>
                                <?php $this->button( 'rollback', $row['id'], __( 'Roll back', 'comms-church-recurring-events' ), 'button-link-delete' ); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $ready ) : ?>
                <h2 style="margin-top:28px"><?php esc_html_e( 'Migrate everything that is ready', 'comms-church-recurring-events' ); ?></h2>
                <p class="description">
                    <?php printf(
                        esc_html__( 'Events that need attention are skipped and left exactly as they are. Recommended: migrate one event first and check your calendar.', 'comms-church-recurring-events' )
                    ); ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'Migrate %d events? Take a database backup first.', 'comms-church-recurring-events' ), count( $ready ) ) ); ?>');">
                    <?php wp_nonce_field( 'ccrec_migrate' ); ?>
                    <input type="hidden" name="action" value="ccrec_migrate">
                    <input type="hidden" name="ccrec_action" value="migrate_all">
                    <p>
                        <label>
                            <input type="checkbox" name="detach" value="1" checked>
                            <?php esc_html_e( 'Remove the Pro recurrence rule afterwards (recommended — otherwise both systems manage the same event)', 'comms-church-recurring-events' ); ?>
                        </label>
                    </p>
                    <?php submit_button(
                        sprintf( __( 'Migrate %d events', 'comms-church-recurring-events' ), count( $ready ) ),
                        'primary', 'submit', false
                    ); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Single-action form; each row posts on its own so one click does one thing. */
    private function button( $action, $id, $label, $class ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
            <?php wp_nonce_field( 'ccrec_migrate' ); ?>
            <input type="hidden" name="action" value="ccrec_migrate">
            <input type="hidden" name="ccrec_action" value="<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="event_id" value="<?php echo (int) $id; ?>">
            <input type="hidden" name="detach" value="1">
            <button type="submit" class="button <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }
}
