// Release smoke suite. Boots WordPress Playground (WASM — no Docker/PHP needed)
// with the plugin auto-mounted and seeded via tests/blueprint.json.
const { defineConfig } = require('@playwright/test');

const PORT = 9401;

module.exports = defineConfig({
  testDir: './tests',
  timeout: 60_000,
  retries: 1, // absorb Playground cold-start flakes
  workers: 1, // Playground's SQLite backend dislikes parallel writes
  reporter: [['list']],
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    screenshot: 'only-on-failure',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.js/ },
    {
      name: 'smoke',
      testMatch: /smoke\.spec\.js/,
      dependencies: ['setup'],
      use: { storageState: 'test-results/.auth/admin.json' },
    },
    {
      name: 'screenshots',
      testMatch: /screenshots\.spec\.js/,
      dependencies: ['setup'],
      use: { storageState: 'test-results/.auth/admin.json' },
    },
  ],
  webServer: {
    command: `npx wp-playground-cli server --auto-mount --login --port ${PORT} --blueprint tests/blueprint.json`,
    url: `http://127.0.0.1:${PORT}/wp-json/`,
    timeout: 180_000, // first boot downloads WordPress
    reuseExistingServer: true,
  },
});
