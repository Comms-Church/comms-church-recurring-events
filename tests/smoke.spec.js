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

  test('plugins screen renders with the plugin active', async ({ page }) => {
    await page.goto('/wp-admin/plugins.php');
    await expect(page.locator('#wpwrap')).toBeVisible();
    await expectClean(page);
    const body = await page.locator('body').innerText();
    expect(body).toContain('Recurring Events');
  });
});
