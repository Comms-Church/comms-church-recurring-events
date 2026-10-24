# Comms.Church — Recurring Events for The Events Calendar

Repeating events for the **free** version of The Events Calendar. Set a repeat pattern on
any event and this generates the individual dates as real events — no Events Calendar Pro
licence required.

## Status

**0.2.0 — released.** Recurrence engine, generation, edit-screen controls, and the
migration path off Pro all work and are tested end to end with Playwright against free TEC
with no Pro installed. 0.2.0 adds a live preview of the next dates, sends dates a changed
rule drops to the Trash instead of deleting them, and supports sites on a UTC-offset
timezone.

## Why this exists

The cost isn't the point — the **handoff** is. When a church takes over a site we built,
they don't get our plugin licences. If their calendar depends on Events Calendar Pro, they
either buy their own or sit on a plugin they can't update, and event plugins get security
patches.

So the goal is narrow: make the one Pro feature churches actually depend on — recurring
events — work on free TEC. Measured on a real client site, only `list` and `month` views
were in use (both free) and recurrence was the sole load-bearing Pro feature.

**This is not a reimplementation of The Events Calendar.** Free TEC core does all the
storage, querying, and display. This adds a repeat pattern and expands it.

## How it works

Each occurrence becomes an **ordinary, independent TEC event**, created through TEC's own
ORM so the custom tables (`wp_tec_events`, `wp_tec_occurrences`) stay consistent.

That design choice is the whole point: **deactivate this plugin and the church still has
N real events on their calendar.** Nothing collapses, nothing 404s. That's the opposite of
licence-gated recurrence, where the dates vanish when the licence lapses.

The parent event is itself the first occurrence — not a hidden template — so an event with
a repeat pattern behaves like a normal event everywhere else in WordPress.

## Patterns supported

| Pattern | Notes |
|---|---|
| Daily | every N days |
| Weekly | every N weeks, on any combination of weekdays |
| Monthly — same date | the 15th of each month |
| Monthly — same weekday | 2nd Tuesday; tracks "last" correctly when seeded on the last one |

Ends after a number of times, or on a date. Specific dates can be skipped.

Deliberately narrower than iCal RRULE: churches repeat things weekly, monthly-by-weekday,
monthly-by-date, and occasionally daily. Supporting the full RFC 5545 grammar would add
failure modes without serving anyone.

## Two date decisions worth knowing

**Timezone-correct, not UTC-arithmetic.** All maths happens in the event's own timezone
with `DateTimeImmutable`. A weekly 7pm service stays at 7pm across a DST boundary — which
is what a church means by "every Tuesday at 7" — rather than drifting an hour.

**Months without the date are skipped, not shifted.** A rule seeded on the 31st produces
Jan 31, Mar 31, May 31 — never Mar 3rd, which is what PHP's `+1 month` does to February
and is never what anyone meant.

## Safety

- Hard cap of **200** generated occurrences and **3 years** ahead, whatever the rule says.
- **Past dates are never touched.** Changing "every Tuesday" to "every Wednesday" in March
  doesn't rewrite or delete January's events — history is record, not state.
- Sync is **idempotent**: re-running creates and deletes nothing.
- Deleting or trashing the parent takes its generated dates with it — no orphans.
- Generated dates carry the parent's categories, tags, venue, organiser, cost, and
  featured image, so filtered calendars and single-event pages behave.

## Migrating off Events Calendar Pro

**Events → Migrate from Pro** (the menu item only appears if there is anything to migrate).

The screen is a dry run. It lists every event with a Pro recurrence rule, what that rule
says, and — expandable per event — the exact dates that would be created. Nothing is
written until you click.

- **Refuses rather than approximates.** Yearly repeats, monthly-by-nth-weekday
  ("3rd Tuesday"), and stacked multi-rule events are marked *needs attention* and left
  untouched. A quietly wrong calendar is worse than one that still needs Pro.
- **Reversible.** The original Pro rule is kept on the event, and migrated events stay
  listed with a **Roll back** button that removes the generated dates and restores it.
- **Past dates are never created.**
- Migrating detaches the Pro rule so both systems aren't managing the same event.

Recommended order: back up the database, migrate one event, look at the calendar, then
do the rest.

## Remaining

- Block-editor parity (TEC uses the classic editor for events by default)
- Screenshots for the comms.church landing page

## Architecture

```
comms-church-recurring-events.php   bootstrap, PHP + TEC dependency guards
includes/
  class-ccrec-rule.php       the repeat pattern: validation, persistence, plain-English summary
  class-ccrec-expander.php   pure date maths — rule + seed -> list of datetimes (no WordPress)
  class-ccrec-generator.php  materialises dates as real TEC events via the ORM; sync/cleanup
  class-ccrec-metabox.php    edit-screen controls, events-list "Repeats" column
  class-ccrec-ecp-migrator.php  reads ECP rules, converts, migrates, rolls back
  class-ccrec-migrate-screen.php  Events -> Migrate from Pro (dry run by default)
  class-ccrec-updater.php    GitHub Releases -> native WP update UI
```

### A TEC 6 gotcha this cost us

With custom tables active, `get_posts()` on `tribe_events` returns *provisional*
occurrence IDs (`10000001`+) rather than post IDs — even for single-occurrence events.
Operating on a provisional ID is fine, because TEC's compatibility layer resolves reads
and writes to the real post. The failure is **storing one as data**: an early version
recorded provisional IDs as parent references, so later lookups by real ID matched
nothing and left orphaned events behind. Anywhere a real post ID is needed, this queries
postmeta directly via `$wpdb`.

`class-ccrec-expander.php` has no WordPress dependencies on purpose — the date maths is the
part most likely to be wrong, so it can be reasoned about and tested on its own.

## License

GPL-2.0+
