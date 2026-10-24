<?php
/**
 * CCREC_ECP_Migrator — convert Events Calendar Pro recurrence rules into generated dates.
 *
 * The premise: ECP's `_EventRecurrence` meta is the source of truth for a repeating
 * event, whether or not ECP has materialised it. Read that rule, translate it, and let
 * CCREC_Generator produce real standalone events. Afterwards the calendar no longer
 * needs Pro.
 *
 * Two rules this code follows without exception:
 *
 * 1. **Dry run first.** Nothing is written until someone has seen what would happen.
 *    Migration touches a church's live calendar; a surprise is unacceptable.
 *
 * 2. **Refuse rather than approximate.** Any ECP pattern this can't represent exactly
 *    (yearly, nth-weekday-of-month with an explicit ordinal, multi-rule stacks) is
 *    reported as unsupported and left alone. A calendar that is quietly wrong is worse
 *    than one that still needs Pro.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_ECP_Migrator {

    const ECP_META    = '_EventRecurrence';
    /** Kept on migrated events so a rerun is idempotent and the original is auditable. */
    const BACKUP_META = '_ccrec_migrated_from_ecp';

    /**
     * ECP numbers weekdays ISO-style: 1 = Monday … 7 = Sunday.
     * (Verified in ECP's Recurrence/Meta.php, which indexes a Monday-first array
     * with [$day - 1].) CCREC uses PHP's `w`: 0 = Sunday … 6 = Saturday.
     */
    private static function iso_to_w( $iso ) {
        $iso = (int) $iso;
        if ( $iso < 1 || $iso > 7 ) return null;
        return 7 === $iso ? 0 : $iso;
    }

    // ---- discovery ----------------------------------------------------------

    /**
     * @return int[] real event post IDs carrying an ECP recurrence rule
     *
     * Deliberately a direct postmeta query. With TEC 6 custom tables active, a normal
     * event query hands back *provisional* occurrence IDs (10000001, …) rather than post
     * IDs. Migrating against those writes meta nowhere, leaves the ECP rule in place, and
     * produces a pile of orphaned events — which is exactly what happened the first time
     * this was run against real ECP data.
     */
    public static function find_candidates() {
        global $wpdb;

        /* Two meta keys, deliberately. Migrating with "detach" removes the ECP rule, so
           an already-migrated event would vanish from this list — taking its Roll back
           button with it. Including the backup key keeps migrated events visible and
           reversible, which is the whole point of keeping a backup. */
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
               FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key IN ( %s, %s )
                AND p.post_type = 'tribe_events'
                AND p.post_status != 'trash'
              ORDER BY pm.post_id",
            self::ECP_META,
            self::BACKUP_META
        ) );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * What migration would do, without doing it.
     *
     * @return array[] one row per candidate: id, title, ecp (summary), status, rule, note
     */
    public static function preview() {
        $rows = array();

        foreach ( self::find_candidates() as $id ) {
            $backup = get_post_meta( $id, self::BACKUP_META, true );
            $raw    = get_post_meta( $id, self::ECP_META, true );

            /* A migrated-and-detached event has no live ECP rule left. Describe it from
               the backup so the row still shows what it came from. */
            if ( ! $raw && $backup ) $raw = $backup;

            $conv = self::convert( $raw );

            $row = array(
                'id'     => $id,
                'title'  => get_the_title( $id ),
                'start'  => get_post_meta( $id, '_EventStartDate', true ),
                'ecp'    => self::describe_ecp( $raw ),
                'status' => $conv['ok'] ? 'ready' : 'unsupported',
                'note'   => $conv['note'],
                'rule'   => $conv['rule'],
                'dates'  => array(),
            );

            if ( $conv['ok'] && $row['start'] ) {
                $tz            = get_post_meta( $id, '_EventTimezone', true ) ?: wp_timezone_string();
                $row['dates']  = CCREC_Expander::expand( $conv['rule'], $row['start'], $tz );
            }

            if ( $backup ) {
                $row['status'] = 'already migrated';
                $row['note']   = sprintf(
                    /* translators: %d: number of generated dates */
                    _n( '%d date generated. Roll back to restore the Pro rule.', '%d dates generated. Roll back to restore the Pro rule.',
                        CCREC_Generator::count_children( $id ), 'comms-church-recurring-events' ),
                    CCREC_Generator::count_children( $id )
                );
                $row['dates'] = array();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    // ---- translation --------------------------------------------------------

    /**
     * ECP rule array -> CCREC_Rule.
     *
     * @return array{ok:bool,rule:CCREC_Rule|null,note:string}
     */
    public static function convert( $ecp ) {
        $fail = function ( $note ) { return array( 'ok' => false, 'rule' => null, 'note' => $note ); };

        if ( ! is_array( $ecp ) || empty( $ecp['rules'] ) || ! is_array( $ecp['rules'] ) ) {
            return $fail( __( 'No readable recurrence rule.', 'comms-church-recurring-events' ) );
        }

        /* ECP allows several stacked rules on one event. Collapsing them into a single
           pattern would change the dates, so decline instead of guessing. */
        if ( count( $ecp['rules'] ) > 1 ) {
            return $fail( sprintf(
                __( 'Uses %d stacked recurrence rules — migrate this one by hand.', 'comms-church-recurring-events' ),
                count( $ecp['rules'] )
            ) );
        }

        $r      = $ecp['rules'][0];
        $type   = isset( $r['type'] ) ? (string) $r['type'] : '';
        $custom = isset( $r['custom'] ) && is_array( $r['custom'] ) ? $r['custom'] : array();

        $freq         = '';
        $interval     = 1;
        $weekdays     = array();
        $monthly_mode = 'date';

        switch ( $type ) {
            case 'Every Day':   $freq = 'daily';   break;
            case 'Every Week':  $freq = 'weekly';  break;
            case 'Every Month': $freq = 'monthly'; break;
            case 'Every Year':
                return $fail( __( 'Yearly repeats are not supported.', 'comms-church-recurring-events' ) );

            case 'Custom':
                $ctype    = isset( $custom['type'] ) ? (string) $custom['type'] : '';
                $interval = max( 1, (int) ( $custom['interval'] ?? 1 ) );

                switch ( $ctype ) {
                    case 'Daily':   $freq = 'daily';   break;
                    case 'Weekly':
                        $freq = 'weekly';
                        $days = $custom['week']['day'] ?? array();
                        foreach ( (array) $days as $d ) {
                            $w = self::iso_to_w( $d );
                            if ( null !== $w ) $weekdays[] = $w;
                        }
                        break;
                    case 'Monthly':
                        $freq  = 'monthly';
                        $month = $custom['month'] ?? array();
                        /* ECP can target "the 3rd Tuesday" via number/day. CCREC only
                           reproduces the seed event's own position, so anything that
                           deviates from that would silently move the dates. */
                        if ( ! empty( $month['number'] ) || ! empty( $month['day'] ) ) {
                            $monthly_mode = 'weekday';
                            return array(
                                'ok'   => false,
                                'rule' => null,
                                'note' => __( 'Monthly by a specific weekday position (e.g. "3rd Tuesday") — check the date, then set it by hand.', 'comms-church-recurring-events' ),
                            );
                        }
                        $monthly_mode = 'date';
                        break;
                    case 'Yearly':
                        return $fail( __( 'Yearly repeats are not supported.', 'comms-church-recurring-events' ) );
                    default:
                        return $fail( sprintf( __( 'Unrecognised custom type "%s".', 'comms-church-recurring-events' ), $ctype ) );
                }
                break;

            default:
                return $fail( sprintf( __( 'Unrecognised recurrence type "%s".', 'comms-church-recurring-events' ), $type ) );
        }

        // End condition.
        $end_type = isset( $r['end-type'] ) ? (string) $r['end-type'] : 'Never';
        $ends     = 'count';
        $count    = 12;
        $until    = '';

        if ( 'After' === $end_type ) {
            $ends  = 'count';
            $count = max( 1, (int) ( $r['end-count'] ?? 12 ) );
        } elseif ( 'On' === $end_type ) {
            $ends  = 'until';
            $until = CCREC_Rule::sanitize_date( substr( (string) ( $r['end'] ?? '' ), 0, 10 ) );
            if ( ! $until ) return $fail( __( 'Ends on a date, but the date is unreadable.', 'comms-church-recurring-events' ) );
        } else {
            /* "Never" has to become something finite. Cap it and say so in the note,
               rather than pretending the calendar runs forever. */
            $ends  = 'count';
            $count = CCREC_Rule::MAX_OCCURRENCES;
        }

        // ECP exclusions -> skip dates, where they're plain dates.
        $skip = array();
        foreach ( (array) ( $ecp['exclusions'] ?? array() ) as $ex ) {
            $d = '';
            if ( is_string( $ex ) )                      $d = substr( $ex, 0, 10 );
            elseif ( is_array( $ex ) && ! empty( $ex['custom']['date'] ) ) $d = substr( (string) $ex['custom']['date'], 0, 10 );
            $d = CCREC_Rule::sanitize_date( $d );
            if ( $d ) $skip[] = $d;
        }

        $rule = new CCREC_Rule( array(
            'freq'         => $freq,
            'interval'     => $interval,
            'weekdays'     => $weekdays,
            'monthly_mode' => $monthly_mode,
            'ends'         => $ends,
            'until'        => $until,
            'count'        => $count,
            'skip'         => $skip,
        ) );

        $note = '';
        if ( 'Never' === $end_type ) {
            $note = sprintf(
                __( 'ECP had no end date; capped at %d dates.', 'comms-church-recurring-events' ),
                CCREC_Rule::MAX_OCCURRENCES
            );
        }
        if ( $skip ) {
            $note = trim( $note . ' ' . sprintf(
                _n( '%d excluded date carried over.', '%d excluded dates carried over.', count( $skip ), 'comms-church-recurring-events' ),
                count( $skip )
            ) );
        }

        return array( 'ok' => true, 'rule' => $rule, 'note' => $note );
    }

    // ---- execution ----------------------------------------------------------

    /**
     * Migrate one event.
     *
     * @param bool $detach Remove ECP's rule afterwards so Pro stops managing the event.
     * @return array{ok:bool,created:int,note:string}
     */
    public static function migrate( $post_id, $detach = true ) {
        $raw  = get_post_meta( $post_id, self::ECP_META, true );
        $conv = self::convert( $raw );

        if ( ! $conv['ok'] ) {
            return array( 'ok' => false, 'created' => 0, 'note' => $conv['note'] );
        }

        /* Keep the original rule. If a church later decides the migration was wrong,
           the ECP definition is still on the post rather than gone. */
        if ( ! get_post_meta( $post_id, self::BACKUP_META, true ) ) {
            update_post_meta( $post_id, self::BACKUP_META, $raw );
        }

        $conv['rule']->save_to( $post_id );
        $result = CCREC_Generator::sync( $post_id, $conv['rule'] );

        if ( $detach ) {
            delete_post_meta( $post_id, self::ECP_META );
        }

        return array( 'ok' => true, 'created' => $result['created'], 'note' => $conv['note'] );
    }

    /** Put the ECP rule back and remove what we generated. */
    public static function rollback( $post_id ) {
        $backup = get_post_meta( $post_id, self::BACKUP_META, true );
        if ( ! $backup ) return false;

        foreach ( CCREC_Generator::children_of( $post_id, true ) as $child ) {
            wp_delete_post( $child, true );
        }
        delete_post_meta( $post_id, CCREC_Rule::META_KEY );
        update_post_meta( $post_id, self::ECP_META, $backup );
        delete_post_meta( $post_id, self::BACKUP_META );
        return true;
    }

    // ---- helpers ------------------------------------------------------------

    /** Human-readable summary of what ECP had, for the preview table. */
    public static function describe_ecp( $ecp ) {
        if ( ! is_array( $ecp ) || empty( $ecp['rules'][0] ) ) return '—';

        $r    = $ecp['rules'][0];
        $type = $r['type'] ?? '?';
        $out  = $type;

        if ( 'Custom' === $type && ! empty( $r['custom']['type'] ) ) {
            $out = $r['custom']['type'];
            $iv  = (int) ( $r['custom']['interval'] ?? 1 );
            if ( $iv > 1 ) $out .= ' ×' . $iv;

            if ( ! empty( $r['custom']['week']['day'] ) ) {
                $names = array( 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun' );
                $days  = array();
                foreach ( (array) $r['custom']['week']['day'] as $d ) {
                    if ( isset( $names[ (int) $d ] ) ) $days[] = $names[ (int) $d ];
                }
                if ( $days ) $out .= ' (' . implode( ', ', $days ) . ')';
            }
        }

        $end = $r['end-type'] ?? 'Never';
        if ( 'After' === $end )   $out .= ', ' . (int) ( $r['end-count'] ?? 0 ) . '×';
        elseif ( 'On' === $end )  $out .= ', until ' . substr( (string) ( $r['end'] ?? '' ), 0, 10 );
        else                      $out .= ', no end';

        return $out;
    }
}
