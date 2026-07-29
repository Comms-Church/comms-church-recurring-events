<?php
/**
 * CCREC_Generator — materialises a rule into real Events Calendar events.
 *
 * Design choice that shapes everything else: each occurrence is an ordinary,
 * independent TEC event, created through TEC's own ORM so the custom tables
 * (wp_tec_events / wp_tec_occurrences) stay consistent.
 *
 * The upside is what happens when this plugin goes away. Deactivate it and the church
 * still has N real events on their calendar — nothing collapses, nothing 404s. That is
 * the opposite of a licence-gated recurrence engine, where the dates vanish when the
 * licence lapses, and it is the whole point of this plugin.
 *
 * The parent event is itself the first occurrence, not a hidden template, so an event
 * with a repeat pattern still behaves like a normal event everywhere else in WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Generator {

    const PARENT_META = '_ccrec_parent';   // on a child: the parent post ID
    const DATE_META   = '_ccrec_date';     // on a child: its 'Y-m-d H:i:s' start

    /** Re-entrancy guard: creating children fires save_post again. */
    private static $working = false;

    public function __construct() {
        add_action( 'save_post_tribe_events', array( $this, 'on_save' ), 20, 3 );
        add_action( 'before_delete_post', array( $this, 'on_delete' ) );
        add_action( 'wp_trash_post', array( $this, 'on_trash' ) );
    }

    // ---- hooks --------------------------------------------------------------

    public function on_save( $post_id, $post, $update ) {
        if ( self::$working ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        if ( 'auto-draft' === $post->post_status ) return;

        // Children never spawn children.
        if ( get_post_meta( $post_id, self::PARENT_META, true ) ) return;

        $rule = CCREC_Rule::for_post( $post_id );
        self::sync( $post_id, $rule );
    }

    /** Deleting a parent for good takes its generated dates with it. */
    public function on_delete( $post_id ) {
        if ( 'tribe_events' !== get_post_type( $post_id ) ) return;
        if ( get_post_meta( $post_id, self::PARENT_META, true ) ) return;
        foreach ( self::children_of( $post_id ) as $child_id ) {
            wp_delete_post( $child_id, true );
        }
    }

    /** Trashing a parent trashes its dates too, so they don't linger on the calendar. */
    public function on_trash( $post_id ) {
        if ( 'tribe_events' !== get_post_type( $post_id ) ) return;
        if ( get_post_meta( $post_id, self::PARENT_META, true ) ) return;
        foreach ( self::children_of( $post_id ) as $child_id ) {
            wp_trash_post( $child_id );
        }
    }

    // ---- sync ---------------------------------------------------------------

    /**
     * Bring the generated dates in line with the rule.
     *
     * Only future occurrences are touched. Past events are historical record — a church
     * that changes "every Tuesday" to "every Wednesday" in March must not have January's
     * events silently rewritten or deleted underneath them.
     *
     * @return array{created:int,deleted:int,kept:int}
     */
    public static function sync( $parent_id, CCREC_Rule $rule ) {
        $result = array( 'created' => 0, 'deleted' => 0, 'kept' => 0 );

        $start = get_post_meta( $parent_id, '_EventStartDate', true );
        if ( ! $start ) return $result;

        $tz       = get_post_meta( $parent_id, '_EventTimezone', true ) ?: wp_timezone_string();
        $wanted   = $rule->is_active() ? CCREC_Expander::expand( $rule, $start, $tz ) : array();
        $existing = array();

        foreach ( self::children_of( $parent_id ) as $child_id ) {
            $existing[ get_post_meta( $child_id, self::DATE_META, true ) ] = $child_id;
        }

        $now = current_time( 'mysql' );

        // Remove future children the rule no longer asks for.
        foreach ( $existing as $date => $child_id ) {
            if ( $date < $now ) { $result['kept']++; continue; }   // history is not ours to edit
            if ( in_array( $date, $wanted, true ) ) { $result['kept']++; continue; }
            wp_delete_post( $child_id, true );
            $result['deleted']++;
        }

        // Add the ones that are missing.
        $duration = self::duration_seconds( $parent_id );
        self::$working = true;
        foreach ( $wanted as $date ) {
            if ( isset( $existing[ $date ] ) ) continue;
            if ( $date < $now ) continue;                          // never backfill the past
            if ( self::create_child( $parent_id, $date, $duration, $tz ) ) $result['created']++;
        }
        self::$working = false;

        return $result;
    }

    /** One generated occurrence, as a real TEC event. */
    private static function create_child( $parent_id, $start, $duration, $tz ) {
        $parent = get_post( $parent_id );
        if ( ! $parent ) return false;

        try {
            $end = ( new DateTimeImmutable( $start, new DateTimeZone( $tz ) ) )
                ->modify( '+' . max( 300, (int) $duration ) . ' seconds' )
                ->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return false;
        }

        $created = tribe_events()->set_args( array(
            'title'      => $parent->post_title,
            'content'    => $parent->post_content,
            'excerpt'    => $parent->post_excerpt,
            'status'     => $parent->post_status,
            'start_date' => $start,
            'end_date'   => $end,
            'timezone'   => $tz,
        ) )->create();

        $child_id = ( $created instanceof WP_Post ) ? $created->ID : ( is_numeric( $created ) ? (int) $created : 0 );
        if ( ! $child_id ) return false;

        update_post_meta( $child_id, self::PARENT_META, (int) $parent_id );
        update_post_meta( $child_id, self::DATE_META, $start );

        self::copy_taxonomies( $parent_id, $child_id );
        self::copy_linked_posts( $parent_id, $child_id );

        return true;
    }

    /** Categories and tags carry over, or filtered calendars miss the occurrences. */
    private static function copy_taxonomies( $from, $to ) {
        foreach ( array( 'tribe_events_cat', 'post_tag' ) as $tax ) {
            if ( ! taxonomy_exists( $tax ) ) continue;
            $terms = wp_get_object_terms( $from, $tax, array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && $terms ) {
                wp_set_object_terms( $to, $terms, $tax );
            }
        }
    }

    /** Venue, organiser, cost, and the URL/featured image a visitor would expect. */
    private static function copy_linked_posts( $from, $to ) {
        $keys = array( '_EventVenueID', '_EventOrganizerID', '_EventCost', '_EventCurrencySymbol', '_EventURL', '_thumbnail_id' );
        foreach ( $keys as $key ) {
            $val = get_post_meta( $from, $key, true );
            if ( '' !== $val && null !== $val ) {
                update_post_meta( $to, $key, $val );
            }
        }
    }

    private static function duration_seconds( $post_id ) {
        $d = (int) get_post_meta( $post_id, '_EventDuration', true );
        if ( $d > 0 ) return $d;

        $s = get_post_meta( $post_id, '_EventStartDate', true );
        $e = get_post_meta( $post_id, '_EventEndDate', true );
        if ( $s && $e ) {
            $diff = strtotime( $e ) - strtotime( $s );
            if ( $diff > 0 ) return $diff;
        }
        return HOUR_IN_SECONDS;
    }

    // ---- queries ------------------------------------------------------------

    /** @return int[] child post IDs, any status */
    public static function children_of( $parent_id ) {
        return get_posts( array(
            'post_type'      => 'tribe_events',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => self::PARENT_META,
            'meta_value'     => (int) $parent_id,
            'no_found_rows'  => true,
        ) );
    }

    public static function count_children( $parent_id ) {
        return count( self::children_of( $parent_id ) );
    }
}
