<?php
/**
 * CCREC_Rule — a repeat pattern, validated.
 *
 * Deliberately narrower than iCal RRULE. Churches repeat things weekly ("Sundays"),
 * monthly by weekday ("second Tuesday"), monthly by date ("the 15th"), and occasionally
 * daily. Supporting the full RFC 5545 grammar would add failure modes without serving
 * anyone, so this covers the real patterns and refuses the rest.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Rule {

    /** Hard ceiling on generated occurrences — a runaway rule must not fill the DB. */
    const MAX_OCCURRENCES = 200;

    /** How far ahead we will ever generate, regardless of the rule. */
    const MAX_YEARS_AHEAD = 3;

    public $freq;      // daily | weekly | monthly | none
    public $interval;  // every N periods
    public $weekdays;  // weekly: array of 0(Sun)-6(Sat)
    public $monthly_mode; // monthly: 'date' (15th) | 'weekday' (2nd Tuesday)
    public $ends;      // 'never' | 'until' | 'count'
    public $until;     // Y-m-d
    public $count;     // int
    public $skip;      // array of Y-m-d to omit

    public function __construct( array $args = array() ) {
        $args = array_merge( array(
            'freq'         => 'none',
            'interval'     => 1,
            'weekdays'     => array(),
            'monthly_mode' => 'date',
            'ends'         => 'count',
            'until'        => '',
            'count'        => 12,
            'skip'         => array(),
        ), $args );

        $this->freq         = in_array( $args['freq'], array( 'daily', 'weekly', 'monthly' ), true ) ? $args['freq'] : 'none';
        $this->interval     = max( 1, min( 52, (int) $args['interval'] ) );
        $this->monthly_mode = 'weekday' === $args['monthly_mode'] ? 'weekday' : 'date';
        $this->ends         = in_array( $args['ends'], array( 'never', 'until', 'count' ), true ) ? $args['ends'] : 'count';
        $this->count        = max( 1, min( self::MAX_OCCURRENCES, (int) $args['count'] ) );
        $this->until        = self::sanitize_date( $args['until'] );

        $this->weekdays = array();
        foreach ( (array) $args['weekdays'] as $d ) {
            $d = (int) $d;
            if ( $d >= 0 && $d <= 6 ) $this->weekdays[] = $d;
        }
        $this->weekdays = array_values( array_unique( $this->weekdays ) );
        sort( $this->weekdays );

        $this->skip = array();
        foreach ( (array) $args['skip'] as $s ) {
            $s = self::sanitize_date( $s );
            if ( $s ) $this->skip[] = $s;
        }
        $this->skip = array_values( array_unique( $this->skip ) );
    }

    /** Is this rule actually asking for repetition? */
    public function is_active() {
        return 'none' !== $this->freq;
    }

    /**
     * Why this rule can't be used, or '' if it's fine.
     * Returned to the editor rather than silently generating nothing.
     */
    public function validation_error() {
        if ( ! $this->is_active() ) return '';

        if ( 'until' === $this->ends && ! $this->until ) {
            return __( 'Choose the date the repeat should stop.', 'comms-church-recurring-events' );
        }
        if ( 'never' === $this->ends ) {
            /* "Never" is a lie we refuse to tell: something has to bound generation, so
               it becomes "as far as we generate", which the UI states plainly. */
            return '';
        }
        return '';
    }

    public static function sanitize_date( $d ) {
        $d = trim( (string) $d );
        if ( '' === $d ) return '';
        $dt = DateTime::createFromFormat( 'Y-m-d', $d );
        return ( $dt && $dt->format( 'Y-m-d' ) === $d ) ? $d : '';
    }

    // ---- persistence --------------------------------------------------------

    const META_KEY = '_ccrec_rule';

    public function to_array() {
        return array(
            'freq'         => $this->freq,
            'interval'     => $this->interval,
            'weekdays'     => $this->weekdays,
            'monthly_mode' => $this->monthly_mode,
            'ends'         => $this->ends,
            'until'        => $this->until,
            'count'        => $this->count,
            'skip'         => $this->skip,
        );
    }

    public static function for_post( $post_id ) {
        $raw = get_post_meta( $post_id, self::META_KEY, true );
        return new self( is_array( $raw ) ? $raw : array() );
    }

    public function save_to( $post_id ) {
        if ( $this->is_active() ) {
            update_post_meta( $post_id, self::META_KEY, $this->to_array() );
        } else {
            delete_post_meta( $post_id, self::META_KEY );
        }
    }

    /** Plain-English summary for admin columns and the editor. */
    public function describe() {
        if ( ! $this->is_active() ) return '';

        $every = $this->interval > 1
            ? sprintf( __( 'every %d ', 'comms-church-recurring-events' ), $this->interval )
            : '';

        switch ( $this->freq ) {
            case 'daily':
                $base = $this->interval > 1
                    ? sprintf( __( 'Every %d days', 'comms-church-recurring-events' ), $this->interval )
                    : __( 'Daily', 'comms-church-recurring-events' );
                break;
            case 'weekly':
                $names = array();
                foreach ( $this->weekdays as $d ) {
                    $names[] = date_i18n( 'D', strtotime( 'Sunday +' . $d . ' days' ) );
                }
                $base = $names
                    ? sprintf( __( 'Weekly on %s', 'comms-church-recurring-events' ), implode( ', ', $names ) )
                    : __( 'Weekly', 'comms-church-recurring-events' );
                if ( $this->interval > 1 ) {
                    $base = sprintf( __( 'Every %1$d weeks on %2$s', 'comms-church-recurring-events' ), $this->interval, implode( ', ', $names ) );
                }
                break;
            case 'monthly':
                $base = 'weekday' === $this->monthly_mode
                    ? __( 'Monthly, same weekday', 'comms-church-recurring-events' )
                    : __( 'Monthly, same date', 'comms-church-recurring-events' );
                if ( $this->interval > 1 ) {
                    $base = sprintf( __( 'Every %d months', 'comms-church-recurring-events' ), $this->interval );
                }
                break;
            default:
                return '';
        }

        if ( 'until' === $this->ends && $this->until ) {
            $base .= ' ' . sprintf( __( 'until %s', 'comms-church-recurring-events' ), date_i18n( get_option( 'date_format' ), strtotime( $this->until ) ) );
        } elseif ( 'count' === $this->ends ) {
            $base .= ' ' . sprintf( _n( '(%d time)', '(%d times)', $this->count, 'comms-church-recurring-events' ), $this->count );
        }

        return $base;
    }
}
