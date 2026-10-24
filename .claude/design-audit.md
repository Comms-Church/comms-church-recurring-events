# Apple Design Audit — Recurring Events

Audit of this plugin against the `apple-design` skill (`.claude/skills/apple-design/SKILL.md`), 2026-10-24. Section references (e.g. §16 *Agency*) point to headings in that skill. The audit of all five Comms.Church plugins is in the shared doc: https://claude.ai/artifact/GGvMskv9nX4bc82HuR4wmS

## Overview

The five plugins have 61 design issues between them: 4 Critical, 13 High, 20 Medium and 24 Low. The worst ones lose people's work or leave them stuck, not visual polish: an editor that hangs on a failed save, sermon edits lost without warning, a confirmation that never fires, and future event dates deleted with no warning.

Each finding gives **What** is wrong, **Why** it matters (citing the section and heading of the vendored `apple-design` skill, e.g. §16 *Agency*), and a **Fix** in the plugin's own stack: plain CSS, jQuery or plain JS, and WordPress PHP. Severity is ranked by harm to the person using it:

- **Critical:** data loss, or a broken layout most visitors will see
- **High:** blocks a task, excludes keyboard or screen-reader users, or shows a wrong state
- **Medium:** friction, unclear feedback, or contrast below WCAG minimums
- **Low:** polish and consistency

The skill is mostly about gesture-driven motion (springs, flicks, momentum). These plugins have no draggable UI, so most citations come from §1 Response, §7 Spatial consistency, §12 Materials, §14 Reduced motion & accessibility, §15 Typography and §16 Design foundations.

### Worst issues across all plugins

| Rank | Plugin | Finding | Severity | Fixed in this pass |
| --- | --- | --- | --- | --- |
| 1 | Recurring Events | Changing a pattern permanently deletes future dates without warning | Critical | Yes |
| 2 | Sermon Suite | A failed save leaves the editor stuck on "Saving…" | Critical | Yes |
| 3 | Sermon Suite | No warning before leaving with unsaved edits | Critical | Yes |
| 4 | PCO TEC | Events without a featured image show an empty gray box | Critical | Yes |
| 5 | PCO TEC | "Force Full Re-sync" never asks for confirmation | High | Yes |
| 6 | PCO | Add-to-Calendar menu opens upward and can't be used from the keyboard | High | Yes |
| 7 | Sermon Suite, PCO TEC | No visible keyboard focus | High | Yes |
| 8 | PCO | Generator preview doesn't match the live page | High | Yes |
| 9 | Groups | Filtering changes the page silently | High | Yes |
| 10 | Groups, PCO, PCO TEC | Cards lift on hover but aren't links | High / Medium / Low | Yes |

A pattern across the suite: Groups and PCO already handle reduced motion, focus rings and container sizing well, while Sermon Suite and PCO TEC don't. A shared base stylesheet for focus, press and reduced-motion styles would stop these drifting apart again.

## Comms Church Recurring Events

Recurring Events has 8 findings, all in the admin, since it has no front end. Its wording and use of WordPress's own UI are good, and the migration screen already previews every date before migrating. The repeat-rule metabox does neither: it gives no preview, and saving a changed pattern permanently deletes future dates without warning. Files: `includes/class-ccrec-metabox.php`, `includes/class-ccrec-generator.php`, `includes/class-ccrec-migrate-screen.php`.

### 1. Changing a pattern permanently deletes future dates without warning (Critical)

**What:** On save, `CCREC_Generator::sync()` calls `wp_delete_post( $child_id, true )` on every future date the new rule no longer produces. That bypasses the trash. It includes dates someone edited one by one, which the plugin itself encourages ("Changes here apply to this date only"). Switching to "Does not repeat" removes every future date. The metabox doesn't warn beforehand, and the `deleted` count that `sync()` returns is never shown.

**Why:** §16 *Agency*: "Back it with forgiveness — easy undo for slips." §16 *Feedback*: "Confirm meaningful actions… warn before problems."

**Fix:**

1. Warn in the metabox as soon as the rule changes and dates already exist:

```js
var warn = box.querySelector( '.ccrec-change-warning' );
box.addEventListener( 'change', function () { if ( warn ) warn.hidden = false; } );
```

```php
<?php if ( $children ) : ?>
<p class="ccrec-change-warning notice notice-warning inline" hidden><?php esc_html_e( 'Future dates that no longer fit the new pattern will be removed when you update, including any you edited individually.', 'comms-church-recurring-events' ); ?></p>
<?php endif; ?>
```

2. Use `wp_trash_post( $child_id )` instead of force-deleting, so removed dates can be recovered.
3. Store the `sync()` result in a transient and show it after the redirect: "3 dates added, 2 removed (in Trash)."

### 2. No preview of the dates a rule will create (High)

**What:** The metabox shows the controls but never the result. Editors only see "Every 2 weeks on Sun, Wed" in the list column after saving. The plugin already has `CCREC_Rule::describe()` and `CCREC_Expander::expand()`, and the migration screen previews dates, but the place people actually set a rule doesn't.

**Why:** §16 *Simplicity*: "sometimes *adding* context simplifies (a video scrubber that shows time remaining)." §16 *Responsibility*: "Add previews."

**Fix:** add a live summary line under "Repeats" that updates on every change:

```js
var dayNames = [ 'Sun','Mon','Tue','Wed','Thu','Fri','Sat' ];
function summary() {
  if ( freq.value === 'none' ) return '';
  var n = +box.querySelector( '#ccrec_interval' ).value || 1;
  var s = 'Every ' + ( n > 1 ? n + ' ' + units[ freq.value ] : units[ freq.value ].replace( /s$/, '' ) );
  if ( freq.value === 'weekly' ) { var d = [].filter.call( box.querySelectorAll( '[name="ccrec_weekdays[]"]:checked' ), Boolean ).map( function ( c ) { return dayNames[ c.value ]; } ); if ( d.length ) s += ' on ' + d.join( ', ' ); }
  return s + ( ends.value === 'count' ? ', ' + countInput.value + ' times' : untilInput.value ? ', until ' + untilInput.value : '' ) + '.';
}
```

Next step: an admin-ajax endpoint that returns the next 5 dates from `CCREC_Expander::expand()`.

### 3. The weekday picker is small and ambiguous (Medium)

**What:** Days are seven native checkboxes, each with an 11 px single letter under it: S M T W T F S. That's two S's and two T's, and each target is about 16 px.

**Why:** §15: small text needs a legible size. §10: "Add \~10px of hysteresis/hit padding around the target." §16 *Familiarity*: calendar apps use toggle pills for weekdays.

**Fix:** keep the checkboxes for semantics and style the label as a pill:

```css
#ccrec_rule .ccrec-day input { position:absolute; opacity:0; }
#ccrec_rule .ccrec-day span { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; border:1px solid #8c8f94; border-radius:16px; font-size:12px; }
#ccrec_rule .ccrec-day input:checked + span { background: var(--wp-admin-theme-color, #2271b1); border-color: transparent; color:#fff; }
#ccrec_rule .ccrec-day input:focus-visible + span { outline: 2px solid var(--wp-admin-theme-color, #2271b1); outline-offset: 2px; }
```

Use three-letter labels (Sun, Mon…) so no two look the same.

### 4. "Roll back" deletes dates without asking (Medium)

**What:** On the migration screen, *Roll back* is a red delete-style link that permanently deletes every date the migration generated, with no confirmation. *Migrate all* asks first. Its own undo doesn't.

**Why:** §16 *Agency*: confirmation for "genuinely destructive, irreversible actions".

**Fix:** in `button()`, add `onsubmit="return confirm('Roll back this event? Its generated dates will be deleted.');"` when `$action === 'rollback'`.

### 5. "Every 2 \_\_\_": the unit appears only after JavaScript runs (Low)

**What:** `<span class="ccrec-unit"></span>` is printed empty and filled by script. On first paint, or if a script error stops it, the field reads "Every 2" with nothing after it.

**Why:** §1 *Response*: correct on first paint, with no late change.

**Fix:** print the unit on the server: `<span class="ccrec-unit"><?php echo esc_html( array( 'daily' => 'days', 'weekly' => 'weeks', 'monthly' => 'months' )[ $rule->freq ] ?? '' ); ?></span>`.

### 6. Out-of-range values are only checked on save (Low)

**What:** The "On a date" end field accepts a date before the event starts, and the interval and count limits rely on `min`/`max`, which browsers only check when the form submits.

**Why:** §16: "validate inline (not on submit)."

**Fix:** set `min="<?php echo esc_attr( substr( get_post_meta( $post->ID, '_EventStartDate', true ), 0, 10 ) ); ?>"` on `ccrec_until`. On `input`, show a `.description` error with `aria-invalid="true"`.

### 7. Inline styles and hard-coded status colors (Low)

**What:** Every control carries inline `style=` widths and font sizes. The migration table colors its status words (`#00733f`, `#b32d2e`) inline and in lowercase ("ready", "unsupported").

**Why:** §16 *Craft*: every value deliberate and consistent. §16 *Familiarity*: WordPress admin conventions.

**Fix:** move the styles into a small `admin.css` scoped to `#ccrec_rule` and `.ccrec-migrate`. Render status as `<span class="ccrec-status ccrec-status--ready">Ready</span>`, using WordPress's notice colors.

### 8. Decorative glyphs are read aloud (Low)

**What:** The Repeats column prints "↳ generated date" and a bare "—". Screen readers announce "rightwards arrow with tip downwards" and "em dash".

**Why:** §16 *Craft*: "clear iconography."

**Fix:** `<span aria-hidden="true">↳</span>` and `<span aria-hidden="true">—</span><span class="screen-reader-text">Does not repeat</span>`.

## Fixes applied in this pass

All 61 fixes are now applied on the `claude/quirky-feynman-2ppasd` branch of each repo: 29 in the first pass and the other 32 in a second. Each repo also has the `apple-design` skill and a Markdown copy of its section under `.claude/`, which the release zip excludes.

| Plugin | First pass | Second pass | Also changed |
| --- | --- | --- | --- |
| Sermon Suite | 1–7, part of 8, 9 and 11 | Rest of 8, 9 and 11; 10, 12–17 | Emoji removed from every admin screen, not only the examples cited |
| Groups | 1–4, 7 | 5, 6, 8–12 | Saving credentials now runs the connection test automatically |
| PCO | 1–4, 7, 8 | 5, 6, 9–12 | Fixed an older bug: on phones, 3- and 4-column grids never dropped to one column |
| PCO TEC | 1–8 | 9–12, plus the destructive-button styling from #2 | — |
| Recurring Events | 4, 5, 8, part of 1 and 2 | Rest of 1 and 2; 3, 6, 7 | Removed dates go to the Trash, and a notice says what changed |

Check these behavior changes before merging:

- **Recurring Events:** dates a changed rule drops now go to the Trash, and `children_of()` ignores trashed dates. If the rule later wants that date again, it creates a fresh event instead of restoring the trashed one. Permanently deleting a parent, or rolling back a migration, still removes trashed dates as well.
- **PCO:** signup cards no longer clip their overflow, so the calendar menu can drop down. The grid is also wrapped in a new `.ccpco-signup-list-wrap` div for the container queries. Check any theme CSS that targets the grid's parent.
- **Sermon Suite:** corner radii now follow a five-step scale (3 px becomes 4 px, 10 px becomes 12 px, pills become 999 px), and grid card titles drop from 1.4× to 1.1×. Expect small visual differences.
- **Groups:** the Secret field now loads blank. Leaving it blank keeps the saved secret.

**Verification.** The WordPress test suites still can't run here, because the network policy blocks `wordpress.org` and `playground.wordpress.net`. Every changed PHP file passes `php -l`, and every changed script, including inline `<script>` blocks, passes `node --check`. The headless-Chromium harness now runs 64 checks against each plugin's real CSS and JS, and all pass. The Recurring Events next-dates preview was checked against a stubbed admin-ajax response, so its server endpoint is untested. Run `npm test` in each repo locally before merging.
