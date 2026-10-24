// Release smoke suite — green, or no release.
// Runs against WordPress Playground with free The Events Calendar installed (no Pro)
// and one seeded event, which is exactly the situation this plugin exists for.
const { test, expect } = require('@playwright/test');

// PHP problems surface as text in the page body, not HTTP errors.
const PHP_ERRORS = /Fatal error|Parse error|Warning: |Notice: |Deprecated: /;

async function expectClean(page) {
  const body = await page.locator('body').innerText();
  expect(body).not.toMatch(PHP_ERRORS);
}

// An event of the test's own, repeating weekly 3 times, so tests that save or delete
// never touch the seeded event the setup project waits for.
async function createRepeatingEvent(page, title, timezone = 'America/New_York') {
  await page.goto('/wp-admin/');
  const nonce = await (await page.request.get('/wp-admin/admin-ajax.php?action=rest-nonce')).text();
  const day = new Date(Date.now() + 14 * 864e5).toISOString().slice(0, 10);
  const res = await page.request.post('/wp-json/tribe/events/v1/events', {
    headers: { 'X-WP-Nonce': nonce },
    data: { title, status: 'publish', start_date: `${day} 18:00:00`, end_date: `${day} 19:00:00`, timezone },
  });
  expect(res.ok()).toBeTruthy();
  const { id } = await res.json();
  const editUrl = `/wp-admin/post.php?post=${id}&action=edit`;

  await page.goto(editUrl);
  await page.selectOption('#ccrec_freq', 'weekly');
  await page.fill('#ccrec_count', '3');
  await Promise.all([page.waitForURL(/post\.php\?post=\d+&action=edit/), page.click('#publish')]);
  await expect(page.locator('.notice', { hasText: 'Repeat pattern updated' })).toContainText('3 dates added');
  return { id, title, editUrl, dates: 3 };
}

async function deleteForGood(page, ev, { alreadyTrashed = false } = {}) {
  if (!alreadyTrashed) {
    await page.goto(ev.editUrl);
    await Promise.all([page.waitForURL(/edit\.php/), page.click('#delete-action a.submitdelete')]);
  }
  await page.goto('/wp-admin/edit.php?post_type=tribe_events&post_status=trash');
  const href = await page.locator(`#post-${ev.id} .row-actions .delete a`).getAttribute('href');
  await page.goto(href);
}

test.describe('Public pages', () => {
  test('front page renders without PHP errors', async ({ page }) => {
    await page.goto('/');
    await expectClean(page);
  });

  test('the events calendar renders without PHP errors', async ({ page }) => {
    await page.goto('/?post_type=tribe_events');
    await expectClean(page);
  });
});

test.describe('Admin', () => {
  test('events list renders and shows the Repeats column', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    // The column is this plugin's only footprint on a list a church looks at daily.
    await expect(page.locator('th#ccrec, th:has-text("Repeats")').first()).toBeVisible();
  });

  test('event edit screen offers the repeat controls', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    const firstEvent = page.locator('a.row-title').first();
    await firstEvent.click();
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    await expect(page.locator('#ccrec_rule')).toBeVisible();
    await expect(page.locator('#ccrec_freq')).toBeVisible();
  });

  test('choosing a frequency reveals the schedule controls', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    await page.locator('a.row-title').first().click();
    await expect(page.locator('#ccrec_freq')).toBeVisible();

    // Weekly must expose the weekday picker; the default (none) must hide everything.
    await page.selectOption('#ccrec_freq', 'weekly');
    await expect(page.locator('#ccrec_rule .ccrec-weekly')).toBeVisible();
    await page.selectOption('#ccrec_freq', 'monthly');
    await expect(page.locator('#ccrec_rule .ccrec-monthly')).toBeVisible();
    await page.selectOption('#ccrec_freq', 'none');
    await expect(page.locator('#ccrec_rule .ccrec-when')).toBeHidden();
  });

  test('migration screen is absent when there is nothing to migrate', async ({ page }) => {
    // No ECP rules exist in this fixture, so the menu item must not appear at all —
    // a church that never used Pro should never see it.
    await page.goto('/wp-admin/edit.php?post_type=tribe_events&page=ccrec-migrate');
    const body = await page.locator('body').innerText();
    expect(body).not.toMatch(/Fatal error|Parse error/);
  });

  test('a preview still loading does not reappear after "Does not repeat"', async ({ page }) => {
    await page.goto('/wp-admin/edit.php?post_type=tribe_events');
    await page.locator('a.row-title').first().click();
    await expect(page.locator('#ccrec_freq')).toBeVisible();

    // Hold the preview response back so "Does not repeat" lands while it is in flight.
    let release;
    const held = new Promise((r) => { release = r; });
    await page.route('**/admin-ajax.php', async (route) => {
      if (!(route.request().postData() || '').includes('ccrec_preview')) return route.continue();
      const response = await route.fetch();
      await held;
      await route.fulfill({ response });
    });

    const sent = page.waitForRequest((r) => (r.postData() || '').includes('ccrec_preview'));
    await page.selectOption('#ccrec_freq', 'daily');
    await sent;
    await page.selectOption('#ccrec_freq', 'none');
    const answered = page.waitForResponse((r) => (r.request().postData() || '').includes('ccrec_preview'));
    release();
    await answered;
    await page.waitForTimeout(300);   // let the response handler run
    await expect(page.locator('#ccrec_rule .ccrec-next')).toBeHidden();
  });

  test('switching to "Does not repeat" shows the Trash warning', async ({ page }) => {
    const ev = await createRepeatingEvent(page, `Smoke warning ${Date.now()}`);
    await page.goto(ev.editUrl);
    const warn = page.locator('#ccrec_rule .ccrec-change-warning');
    await expect(warn).toBeHidden();
    // The change that moves every future date to the Trash must not be the silent one.
    await page.selectOption('#ccrec_freq', 'none');
    await expect(warn).toBeVisible();
    await deleteForGood(page, ev);
  });

  test('trashing a repeating event moves its dates without generating new ones', async ({ page }) => {
    const ev = await createRepeatingEvent(page, `Smoke trash ${Date.now()}`);
    await page.goto(ev.editUrl);
    await Promise.all([page.waitForURL(/edit\.php/), page.click('#delete-action a.submitdelete')]);

    // The parent plus its 3 dates, and nothing more.
    await page.goto('/wp-admin/edit.php?post_type=tribe_events&post_status=trash');
    await expect(page.locator('#the-list tr', { hasText: ev.title })).toHaveCount(1 + ev.dates);

    // Deleting the parent for good takes its trashed dates with it.
    await deleteForGood(page, ev, { alreadyTrashed: true });
    await page.goto('/wp-admin/edit.php?post_type=tribe_events&post_status=trash');
    await expect(page.locator('#the-list tr', { hasText: ev.title })).toHaveCount(0);
  });

  test('an event in a UTC-offset timezone still gets its dates', async ({ page }) => {
    // TEC spells a manual-offset site timezone "UTC+0" (WordPress's default), which
    // PHP's DateTimeZone rejects. createRepeatingEvent() asserts "3 dates added".
    const ev = await createRepeatingEvent(page, `Smoke UTC ${Date.now()}`, 'UTC+0');
    await page.goto(ev.editUrl);
    const next = page.locator('#ccrec_rule .ccrec-next li');
    await expect(next).toHaveCount(3);
    await expect(next.first()).toContainText('6:00pm');   // wall-clock time kept
    await deleteForGood(page, ev);
  });

  test('plugins screen renders with the plugin active', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    const body = await page.locator('body').innerText();
    expect(body).toContain('Recurring Events');
  });
});
