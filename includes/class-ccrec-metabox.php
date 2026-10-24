<?php
/**
 * CCREC_Metabox — the repeat controls on the event edit screen, plus the
 * "Repeats" column on the events list.
 *
 * Kept to plain form controls with no build step. The one piece of interactivity is
 * showing the weekday picker only for weekly rules, which is a few lines of vanilla JS.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Metabox {

    const NONCE = 'ccrec_rule_nonce';

    public function __construct() {
        add_action( 'add_meta_boxes_tribe_events', array( $this, 'add' ) );
        // Priority 10 so the rule is stored before CCREC_Generator (20) reads it.
        add_action( 'save_post_tribe_events', array( $this, 'save' ), 10, 2 );
        add_filter( 'manage_tribe_events_posts_columns', array( $this, 'column' ) );
        add_action( 'manage_tribe_events_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
        add_action( 'admin_notices', array( $this, 'child_notice' ) );
    }

    public function add( $post ) {
        // A generated date shows a pointer to its parent instead of its own controls —
        // editing the pattern belongs in one place.
        $title = get_post_meta( $post->ID, CCREC_Generator::PARENT_META, true )
            ? __( 'Part of a repeating event', 'comms-church-recurring-events' )
            : __( 'Repeat this event', 'comms-church-recurring-events' );

        add_meta_box( 'ccrec_rule', $title, array( $this, 'render' ), 'tribe_events', 'side', 'default' );
    }

    public function render( $post ) {
        $parent_id = (int) get_post_meta( $post->ID, CCREC_Generator::PARENT_META, true );
        if ( $parent_id ) {
            printf(
                '<p>%s</p><p><a class="button" href="%s">%s</a></p>',
                esc_html__( 'This date was generated from a repeating event. Edit the original to change the pattern.', 'comms-church-recurring-events' ),
                esc_url( get_edit_post_link( $parent_id ) ),
                esc_html__( 'Edit the original', 'comms-church-recurring-events' )
            );
            return;
        }

        $rule     = CCREC_Rule::for_post( $post->ID );
        $children = CCREC_Generator::count_children( $post->ID );
        $units    = array( 'daily' => __( 'days', 'comms-church-recurring-events' ), 'weekly' => __( 'weeks', 'comms-church-recurring-events' ), 'monthly' => __( 'months', 'comms-church-recurring-events' ) );

        // Same strings CCREC_Rule::describe() uses, so the live summary reads
        // exactly like the Repeats column will after saving.
        $day_names = array();
        for ( $d = 0; $d <= 6; $d++ ) $day_names[] = date_i18n( 'D', strtotime( 'Sunday +' . $d . ' days' ) );
        $summary_i18n = array(
            'daily'          => __( 'Daily', 'comms-church-recurring-events' ),
            'everyDays'      => __( 'Every %d days', 'comms-church-recurring-events' ),
            'weekly'         => __( 'Weekly', 'comms-church-recurring-events' ),
            'weeklyOn'       => __( 'Weekly on %s', 'comms-church-recurring-events' ),
            'everyWeeksOn'   => __( 'Every %1$d weeks on %2$s', 'comms-church-recurring-events' ),
            'monthlyDate'    => __( 'Monthly, same date', 'comms-church-recurring-events' ),
            'monthlyWeekday' => __( 'Monthly, same weekday', 'comms-church-recurring-events' ),
            'everyMonths'    => __( 'Every %d months', 'comms-church-recurring-events' ),
            'until'          => __( 'until %s', 'comms-church-recurring-events' ),
            'time'           => _n( '(%d time)', '(%d times)', 1, 'comms-church-recurring-events' ),
            'times'          => _n( '(%d time)', '(%d times)', 2, 'comms-church-recurring-events' ),
            'days'           => $day_names,
            'units'          => $units,
        );
        wp_nonce_field( 'ccrec_save_rule', self::NONCE );
        ?>
        <p>
            <label for="ccrec_freq"><strong><?php esc_html_e( 'Repeats', 'comms-church-recurring-events' ); ?></strong></label><br>
            <select name="ccrec_freq" id="ccrec_freq" style="width:100%">
                <option value="none"    <?php selected( $rule->freq, 'none' ); ?>><?php esc_html_e( 'Does not repeat', 'comms-church-recurring-events' ); ?></option>
                <option value="daily"   <?php selected( $rule->freq, 'daily' ); ?>><?php esc_html_e( 'Daily', 'comms-church-recurring-events' ); ?></option>
                <option value="weekly"  <?php selected( $rule->freq, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'comms-church-recurring-events' ); ?></option>
                <option value="monthly" <?php selected( $rule->freq, 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'comms-church-recurring-events' ); ?></option>
            </select>
        </p>
        <p class="ccrec-summary description" aria-live="polite"
           data-i18n="<?php echo esc_attr( wp_json_encode( $summary_i18n ) ); ?>"><?php echo esc_html( $rule->describe() ); ?></p>

        <div class="ccrec-when" style="<?php echo 'none' === $rule->freq ? 'display:none' : ''; ?>">

            <p>
                <label for="ccrec_interval"><?php esc_html_e( 'Every', 'comms-church-recurring-events' ); ?></label>
                <input type="number" name="ccrec_interval" id="ccrec_interval" min="1" max="52" style="width:70px"
                       value="<?php echo esc_attr( $rule->interval ); ?>">
                <span class="ccrec-unit"><?php echo esc_html( $units[ $rule->freq ] ?? '' ); ?></span>
            </p>

            <div class="ccrec-weekly" style="<?php echo 'weekly' === $rule->freq ? '' : 'display:none'; ?>">
                <p style="margin-bottom:4px"><?php esc_html_e( 'On these days', 'comms-church-recurring-events' ); ?></p>
                <p>
                <?php
                $labels = array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' );
                $full   = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
                for ( $d = 0; $d <= 6; $d++ ) : ?>
                    <label style="display:inline-block;margin:0 6px 6px 0;text-align:center">
                        <input type="checkbox" name="ccrec_weekdays[]" value="<?php echo (int) $d; ?>"
                               <?php checked( in_array( $d, $rule->weekdays, true ) ); ?>
                               aria-label="<?php echo esc_attr( $full[ $d ] ); ?>">
                        <span style="display:block;font-size:11px"><?php echo esc_html( $labels[ $d ] ); ?></span>
                    </label>
                <?php endfor; ?>
                </p>
                <p class="description"><?php esc_html_e( 'Leave empty to use the day this event already falls on.', 'comms-church-recurring-events' ); ?></p>
            </div>

            <div class="ccrec-monthly" style="<?php echo 'monthly' === $rule->freq ? '' : 'display:none'; ?>">
                <p>
                    <label><input type="radio" name="ccrec_monthly_mode" value="date" <?php checked( $rule->monthly_mode, 'date' ); ?>>
                        <?php esc_html_e( 'On the same date each month', 'comms-church-recurring-events' ); ?></label><br>
                    <label><input type="radio" name="ccrec_monthly_mode" value="weekday" <?php checked( $rule->monthly_mode, 'weekday' ); ?>>
                        <?php esc_html_e( 'On the same weekday (e.g. 2nd Tuesday)', 'comms-church-recurring-events' ); ?></label>
                </p>
                <p class="description"><?php esc_html_e( 'Months without that date are skipped rather than shifted.', 'comms-church-recurring-events' ); ?></p>
            </div>

            <p>
                <label for="ccrec_ends"><strong><?php esc_html_e( 'Ends', 'comms-church-recurring-events' ); ?></strong></label><br>
                <select name="ccrec_ends" id="ccrec_ends" style="width:100%">
                    <option value="count" <?php selected( $rule->ends, 'count' ); ?>><?php esc_html_e( 'After a number of times', 'comms-church-recurring-events' ); ?></option>
                    <option value="until" <?php selected( $rule->ends, 'until' ); ?>><?php esc_html_e( 'On a date', 'comms-church-recurring-events' ); ?></option>
                </select>
            </p>

            <p class="ccrec-ends-count" style="<?php echo 'count' === $rule->ends ? '' : 'display:none'; ?>">
                <input type="number" name="ccrec_count" min="1" max="<?php echo (int) CCREC_Rule::MAX_OCCURRENCES; ?>" style="width:80px"
                       value="<?php echo esc_attr( $rule->count ); ?>">
                <?php esc_html_e( 'times', 'comms-church-recurring-events' ); ?>
                <span class="description" style="display:block"><?php
                    printf( esc_html__( 'Maximum %d.', 'comms-church-recurring-events' ), (int) CCREC_Rule::MAX_OCCURRENCES ); ?></span>
            </p>

            <p class="ccrec-ends-until" style="<?php echo 'until' === $rule->ends ? '' : 'display:none'; ?>">
                <input type="date" name="ccrec_until" value="<?php echo esc_attr( $rule->until ); ?>">
            </p>

            <?php if ( $children ) : ?>
                <p class="ccrec-change-warning notice notice-warning inline" hidden>
                    <?php esc_html_e( 'Future dates that no longer fit the new pattern will be removed when you update, including any you edited individually.', 'comms-church-recurring-events' ); ?>
                </p>
                <p class="description" style="border-top:1px solid #dcdcde;padding-top:8px">
                    <?php printf(
                        esc_html( _n( '%d date generated.', '%d dates generated.', $children, 'comms-church-recurring-events' ) ),
                        (int) $children
                    ); ?><br>
                    <?php esc_html_e( 'Past dates are left alone when you change the pattern.', 'comms-church-recurring-events' ); ?>
                </p>
            <?php endif; ?>
        </div>

        <script>
        ( function () {
            var box = document.getElementById( 'ccrec_rule' );
            if ( ! box ) return;
            var freq = box.querySelector( '#ccrec_freq' ),
                ends = box.querySelector( '#ccrec_ends' ),
                when = box.querySelector( '.ccrec-when' ),
                wk   = box.querySelector( '.ccrec-weekly' ),
                mo   = box.querySelector( '.ccrec-monthly' ),
                unit = box.querySelector( '.ccrec-unit' ),
                cnt  = box.querySelector( '.ccrec-ends-count' ),
                unt  = box.querySelector( '.ccrec-ends-until' );
            var summaryEl = box.querySelector( '.ccrec-summary' ),
                warn      = box.querySelector( '.ccrec-change-warning' ),
                t         = JSON.parse( summaryEl.getAttribute( 'data-i18n' ) || '{}' ),
                units     = t.units || {};

            function fmt( s ) {
                var args = [].slice.call( arguments, 1 ), i = 0;
                return s.replace( /%(\d\$)?[ds]/g, function ( m, pos ) {
                    return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
                } );
            }

            // Mirrors CCREC_Rule::describe() so editors see the result before saving.
            function summary() {
                var f = freq.value,
                    n = parseInt( box.querySelector( '#ccrec_interval' ).value, 10 ) || 1,
                    s = '';
                if ( f === 'daily' ) {
                    s = n > 1 ? fmt( t.everyDays, n ) : t.daily;
                } else if ( f === 'weekly' ) {
                    var names = [].map.call( box.querySelectorAll( '[name="ccrec_weekdays[]"]:checked' ), function ( c ) { return t.days[ c.value ]; } ).join( ', ' );
                    s = n > 1 && names ? fmt( t.everyWeeksOn, n, names ) : ( names ? fmt( t.weeklyOn, names ) : t.weekly );
                } else if ( f === 'monthly' ) {
                    var weekday = box.querySelector( '[name="ccrec_monthly_mode"]:checked' );
                    s = n > 1 ? fmt( t.everyMonths, n ) : ( weekday && weekday.value === 'weekday' ? t.monthlyWeekday : t.monthlyDate );
                } else {
                    return '';
                }
                var until = box.querySelector( '[name="ccrec_until"]' ).value,
                    count = parseInt( box.querySelector( '[name="ccrec_count"]' ).value, 10 ) || 0;
                if ( ends.value === 'until' && until ) {
                    s += ' ' + fmt( t.until, new Date( until + 'T00:00' ).toLocaleDateString() );
                } else if ( ends.value === 'count' && count ) {
                    s += ' ' + fmt( count === 1 ? t.time : t.times, count );
                }
                return s;
            }

            function sync() {
                var f = freq.value;
                when.style.display = f === 'none' ? 'none' : '';
                wk.style.display   = f === 'weekly' ? '' : 'none';
                mo.style.display   = f === 'monthly' ? '' : 'none';
                if ( unit ) unit.textContent = units[ f ] || '';
                cnt.style.display = ends.value === 'count' ? '' : 'none';
                unt.style.display = ends.value === 'until' ? '' : 'none';
                if ( summaryEl ) summaryEl.textContent = summary();
            }
            freq.addEventListener( 'change', sync );
            ends.addEventListener( 'change', sync );
            box.addEventListener( 'input', sync );
            // Saving a changed rule removes future dates that no longer fit — warn first.
            box.addEventListener( 'change', function () { if ( warn ) warn.hidden = false; } );
            sync();
        } )();
        </script>
        <?php
    }

    public function save( $post_id, $post ) {
        if ( ! isset( $_POST[ self::NONCE ] ) ) return;                       // metabox wasn't on screen
        if ( ! wp_verify_nonce( $_POST[ self::NONCE ], 'ccrec_save_rule' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( get_post_meta( $post_id, CCREC_Generator::PARENT_META, true ) ) return;

        $rule = new CCREC_Rule( array(
            'freq'         => isset( $_POST['ccrec_freq'] ) ? sanitize_text_field( wp_unslash( $_POST['ccrec_freq'] ) ) : 'none',
            'interval'     => isset( $_POST['ccrec_interval'] ) ? (int) $_POST['ccrec_interval'] : 1,
            'weekdays'     => isset( $_POST['ccrec_weekdays'] ) ? (array) $_POST['ccrec_weekdays'] : array(),
            'monthly_mode' => isset( $_POST['ccrec_monthly_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['ccrec_monthly_mode'] ) ) : 'date',
            'ends'         => isset( $_POST['ccrec_ends'] ) ? sanitize_text_field( wp_unslash( $_POST['ccrec_ends'] ) ) : 'count',
            'until'        => isset( $_POST['ccrec_until'] ) ? sanitize_text_field( wp_unslash( $_POST['ccrec_until'] ) ) : '',
            'count'        => isset( $_POST['ccrec_count'] ) ? (int) $_POST['ccrec_count'] : 12,
        ) );

        $rule->save_to( $post_id );
    }

    // ---- events list column -------------------------------------------------

    public function column( $cols ) {
        $cols['ccrec'] = __( 'Repeats', 'comms-church-recurring-events' );
        return $cols;
    }

    public function column_content( $col, $post_id ) {
        if ( 'ccrec' !== $col ) return;

        $parent_id = (int) get_post_meta( $post_id, CCREC_Generator::PARENT_META, true );
        if ( $parent_id ) {
            printf(
                '<span title="%s"><span aria-hidden="true">↳</span> %s</span>',
                esc_attr__( 'Generated from a repeating event', 'comms-church-recurring-events' ),
                esc_html__( 'generated date', 'comms-church-recurring-events' )
            );
            return;
        }

        $rule = CCREC_Rule::for_post( $post_id );
        if ( ! $rule->is_active() ) {
            echo '<span aria-hidden="true">—</span><span class="screen-reader-text">'
                . esc_html__( 'Does not repeat', 'comms-church-recurring-events' ) . '</span>';
            return;
        }

        printf(
            '%s<br><small>%s</small>',
            esc_html( $rule->describe() ),
            esc_html( sprintf(
                _n( '%d date', '%d dates', CCREC_Generator::count_children( $post_id ), 'comms-church-recurring-events' ),
                CCREC_Generator::count_children( $post_id )
            ) )
        );
    }

    /** Explain, once, why a generated date can't be edited into something else. */
    public function child_notice() {
        $screen = get_current_screen();
        if ( ! $screen || 'tribe_events' !== $screen->post_type || 'post' !== $screen->base ) return;
        global $post;
        if ( ! $post || ! get_post_meta( $post->ID, CCREC_Generator::PARENT_META, true ) ) return;

        echo '<div class="notice notice-info"><p>'
            . esc_html__( 'This is one date of a repeating event. Changes here apply to this date only — edit the original event to change the whole pattern.', 'comms-church-recurring-events' )
            . '</p></div>';
    }
}
