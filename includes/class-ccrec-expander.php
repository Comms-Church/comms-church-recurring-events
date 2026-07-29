<?php
/**
 * CCREC_Expander — turns a rule + a seed date into a concrete list of dates.
 *
 * Pure date math, no WordPress and no side effects, so it can be reasoned about and
 * tested on its own. Everything downstream (creating posts, updating, cleaning up)
 * consumes the list this produces.
 *
 * Two decisions worth knowing:
 *
 * 1. All arithmetic happens in the event's own timezone using DateTimeImmutable, then
 *    formats to a wall-clock string. A weekly 7pm service stays at 7pm across a DST
 *    boundary — which is what a church means by "every Tuesday at 7" — rather than
 *    drifting to 6pm or 8pm as naive UTC-second addition would.
 *
 * 2. Monthly "same date" skips months that lack the date instead of rolling forward.
 *    A rule seeded on the 31st produces Jan 31, Mar 31, May 31 — never Mar 3rd, which
 *    is what PHP's "+1 month" does to February and is never what anyone meant.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CCREC_Expander {

    /**
     * @param CCREC_Rule $rule
     * @param string     $seed_start 'Y-m-d H:i:s' — the first occurrence (the event itself)
     * @param string     $timezone   e.g. 'America/New_York'
     * @return string[]  'Y-m-d H:i:s' start datetimes, EXCLUDING the seed, in order
     */
    public static function expand( CCREC_Rule $rule, $seed_start, $timezone = null ) {
        if ( ! $rule->is_active() ) return array();

        try {
            $tz   = new DateTimeZone( $timezone ?: 'UTC' );
            $seed = new DateTimeImmutable( $seed_start, $tz );
        } catch ( Exception $e ) {
            return array();
        }

        $horizon = $seed->modify( '+' . CCREC_Rule::MAX_YEARS_AHEAD . ' years' );
        $until   = null;
        if ( 'until' === $rule->ends && $rule->until ) {
            try {
                // Inclusive of the whole end day.
                $until = new DateTimeImmutable( $rule->until . ' 23:59:59', $tz );
            } catch ( Exception $e ) {
                $until = null;
            }
        }

        $wanted = 'count' === $rule->ends ? $rule->count : CCREC_Rule::MAX_OCCURRENCES;
        $wanted = min( $wanted, CCREC_Rule::MAX_OCCURRENCES );

        $dates = self::candidates( $rule, $seed, $horizon, $until, $wanted );

        // Drop the seed itself and any explicitly skipped days.
        $seed_key = $seed->format( 'Y-m-d H:i:s' );
        $out      = array();
        foreach ( $dates as $d ) {
            $key = $d->format( 'Y-m-d H:i:s' );
            if ( $key === $seed_key ) continue;
            if ( in_array( $d->format( 'Y-m-d' ), $rule->skip, true ) ) continue;
            $out[] = $key;
            if ( count( $out ) >= $wanted ) break;
        }

        return $out;
    }

    /**
     * Generate candidate datetimes (may include the seed; caller filters).
     *
     * @return DateTimeImmutable[]
     */
    private static function candidates( CCREC_Rule $rule, DateTimeImmutable $seed, DateTimeImmutable $horizon, $until, $wanted ) {
        switch ( $rule->freq ) {
            case 'daily':   return self::daily( $rule, $seed, $horizon, $until, $wanted );
            case 'weekly':  return self::weekly( $rule, $seed, $horizon, $until, $wanted );
            case 'monthly': return self::monthly( $rule, $seed, $horizon, $until, $wanted );
        }
        return array();
    }

    private static function daily( $rule, $seed, $horizon, $until, $wanted ) {
        $out = array();
        $cur = $seed;
        // +1 because the seed occupies a slot the caller will discard.
        for ( $i = 0; $i <= $wanted + 1; $i++ ) {
            if ( $cur > $horizon ) break;
            if ( $until && $cur > $until ) break;
            $out[] = $cur;
            $cur = $cur->modify( '+' . $rule->interval . ' days' );
        }
        return $out;
    }

    private static function weekly( $rule, $seed, $horizon, $until, $wanted ) {
        $out = array();

        // No weekday chosen means "the day the event is already on".
        $days = $rule->weekdays ?: array( (int) $seed->format( 'w' ) );

        // Walk week by week from the start of the seed's week, emitting each chosen
        // weekday at the seed's time-of-day.
        $week_start = $seed->modify( '-' . (int) $seed->format( 'w' ) . ' days' );
        $guard      = 0;

        while ( count( $out ) <= $wanted + 1 && $guard++ < 500 ) {
            foreach ( $days as $d ) {
                $candidate = $week_start->modify( '+' . (int) $d . ' days' )
                                        ->setTime( (int) $seed->format( 'H' ), (int) $seed->format( 'i' ), (int) $seed->format( 's' ) );

                if ( $candidate < $seed ) continue;      // never generate before the event
                if ( $candidate > $horizon ) return $out;
                if ( $until && $candidate > $until ) return $out;

                $out[] = $candidate;
                if ( count( $out ) > $wanted + 1 ) return $out;
            }
            $week_start = $week_start->modify( '+' . $rule->interval . ' weeks' );
        }

        return $out;
    }

    private static function monthly( $rule, $seed, $horizon, $until, $wanted ) {
        $out       = array();
        $day       = (int) $seed->format( 'j' );   // date mode: 1-31
        $weekday   = (int) $seed->format( 'w' );   // weekday mode: 0-6
        $nth       = (int) ceil( $day / 7 );       // weekday mode: 1st..5th of the month
        $is_last   = $day + 7 > (int) $seed->format( 't' ); // seeded on the last such weekday
        $guard     = 0;
        $cursor    = $seed->modify( 'first day of this month' );

        while ( count( $out ) <= $wanted + 1 && $guard++ < 200 ) {
            $candidate = null;

            if ( 'date' === $rule->monthly_mode ) {
                // Skip months without this date rather than rolling into the next one.
                if ( $day <= (int) $cursor->format( 't' ) ) {
                    $candidate = $cursor->setDate( (int) $cursor->format( 'Y' ), (int) $cursor->format( 'n' ), $day )
                                        ->setTime( (int) $seed->format( 'H' ), (int) $seed->format( 'i' ), (int) $seed->format( 's' ) );
                }
            } else {
                $candidate = self::nth_weekday_of_month( $cursor, $weekday, $nth, $is_last, $seed );
            }

            if ( $candidate ) {
                if ( $until && $candidate > $until ) break;
                if ( $candidate > $horizon ) break;
                if ( $candidate >= $seed ) $out[] = $candidate;
            }

            $cursor = $cursor->modify( '+' . $rule->interval . ' months' );
            if ( $cursor > $horizon ) break;
        }

        return $out;
    }

    /**
     * The nth occurrence of $weekday in $cursor's month, at the seed's time.
     * When the seed was the last such weekday, track "last" rather than a fixed nth —
     * a "last Sunday" rule shouldn't skip months that only have four.
     */
    private static function nth_weekday_of_month( DateTimeImmutable $cursor, $weekday, $nth, $is_last, DateTimeImmutable $seed ) {
        $first     = $cursor->modify( 'first day of this month' );
        $offset    = ( $weekday - (int) $first->format( 'w' ) + 7 ) % 7;
        $first_hit = $first->modify( '+' . $offset . ' days' );

        $total_hits = 0;
        $probe      = $first_hit;
        $month      = $probe->format( 'n' );
        while ( $probe->format( 'n' ) === $month ) {
            $total_hits++;
            $probe = $probe->modify( '+7 days' );
        }

        $index = $is_last ? $total_hits : $nth;
        if ( $index > $total_hits ) return null; // e.g. no 5th Tuesday this month

        return $first_hit->modify( '+' . ( ( $index - 1 ) * 7 ) . ' days' )
                         ->setTime( (int) $seed->format( 'H' ), (int) $seed->format( 'i' ), (int) $seed->format( 's' ) );
    }
}
