// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8011';
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const path = require('node:path');
const phpSessionPath = path.join(__dirname, 'database');

module.exports = defineConfig({
  testDir: './tests/security',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: false,
  // Both security suites intentionally exercise the same SQLite database and
  // fixture identities. A single worker keeps setup/cleanup deterministic.
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  webServer: process.env.PLAYWRIGHT_BASE_URL
    ? undefined
    : {
        command: `"${phpBinary}" -d "session.save_path=${phpSessionPath}" -S 127.0.0.1:8011 -t public`,
        url: baseURL,
        // The suite relies on the session-path override above. Reusing a stray
        // PHP/XAMPP server can silently switch back to C:\xampp\tmp and make
        // every successful login look like a failed login.
        reuseExistingServer: false,
        timeout: 15_000,
        stdout: 'ignore',
        stderr: 'ignore',
        // Owner developer diagnostics (admin.php) also need this flag on the
        // PHP built-in server process. Flag-off behaviour is asserted via the
        // fixture CLI (`check-dev-security`) because this process cannot flip
        // mid-suite without restarting the server.
        env: {
          ...process.env,
          PORTAL_SHOW_DEVELOPER_SECURITY: '1',
        },
      },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
