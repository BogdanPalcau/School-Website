// @ts-check
const { defineConfig, devices } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8011';
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';

module.exports = defineConfig({
  testDir: './tests/security',
  timeout: 30_000,
  expect: {
    timeout: 5_000,
  },
  fullyParallel: false,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  webServer: process.env.PLAYWRIGHT_BASE_URL
    ? undefined
    : {
        command: `"${phpBinary}" -S 127.0.0.1:8011 -t public`,
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 15_000,
      },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
