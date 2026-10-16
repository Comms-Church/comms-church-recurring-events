// Logs into wp-admin once (Playground default credentials) and saves the
// session for the smoke + screenshot projects.
const { test: setup, expect } = require('@playwright/test');

setup('authenticate as admin', async ({ page }) => {
  await page.goto('/wp-admin/');

  // Playground's --login auto-login doesn't reach a fresh browser context, so
  // expect the login form; skip it only if a session somehow carried over.
  if (!(await page.locator('#wpwrap').isVisible())) {
    const user = page.getByLabel('Username or Email Address');
    const pass = page.locator('#user_pass');

    // wp-login.php runs wp_attempt_focus(): 200ms after the form renders it
    // focuses *and selects* #user_login. Typing before that fires let the timer
    // steal focus mid-fill, so the password landed in the username box. Wait
    // for WordPress to finish moving focus before touching the fields.
    await page.waitForFunction(
      () => document.activeElement && document.activeElement.id === 'user_login',
      null,
      { timeout: 10_000 }
    );
    await user.fill('admin');
    await pass.fill('password');
    await expect(user).toHaveValue('admin');
    await expect(pass).toHaveValue('password');

    await Promise.all([
      page.waitForURL(/\/wp-admin\//, { timeout: 30_000 }),
      page.locator('#wp-submit').click(),
    ]);
  }

  await expect(page.locator('#wpwrap')).toBeVisible();
  await page.context().storageState({ path: 'test-results/.auth/admin.json' });
});
