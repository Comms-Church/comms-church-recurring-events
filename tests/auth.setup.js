// Logs into wp-admin once (Playground default credentials) and saves the
// session for the smoke + screenshot projects.
const { test: setup, expect } = require('@playwright/test');

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/wp-login.php');
  // If Playground's boot-time auto-login already carried over, we're done.
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) {
    await page.goto('/wp-admin/');
  } else {
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
  }
  await expect(page.locator('#wpwrap')).toBeVisible();
  await page.context().storageState({ path: 'test-results/.auth/admin.json' });
});
