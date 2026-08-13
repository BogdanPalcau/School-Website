/**
 * Password-reset UX / enumeration checks.
 *
 * Code truth (`portal_password_reset_request` in bootstrap.php):
 * - Always returns without revealing whether the email exists.
 * - When SMTP is not configured (`portal_mail_configured()` false), no token is
 *   stored and no mail is sent — but `forgot-password.php` still sets `$done`
 *   and shows the same neutral success copy for known and unknown emails.
 *
 * Expired/reused tokens are seeded via `security-fixtures.php seed-reset-token`
 * because the UI path never inserts a token without SMTP + PORTAL_BASE_URL.
 */
const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  csrfTokenFromPage,
  resetLoginAttempts,
  seedPasswordResetToken,
  setupSecurityFixtures,
} = require('./helpers');

test.describe.configure({ mode: 'serial' });

let fixtures;
const NEUTRAL_COPY = /if that email is on file, we sent a reset link/i;

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

async function submitForgotPassword(page, email) {
  await page.goto('/forgot-password.php');
  const token = await csrfTokenFromPage(page);
  const response = await page.context().request.post('/forgot-password.php', {
    form: {
      _token: token,
      email,
    },
    maxRedirects: 0,
  });
  const body = await response.text();
  return { status: response.status(), body };
}

test('known and unknown emails both show the same neutral forgot-password success', async ({ page }) => {
  const known = await submitForgotPassword(page, fixtures.emails.student);
  const unknown = await submitForgotPassword(page, 'definitely-not-a-user@example.test');

  expect(known.status).toBe(unknown.status);
  expect(known.body).toMatch(NEUTRAL_COPY);
  expect(unknown.body).toMatch(NEUTRAL_COPY);

  // Neither branch should reveal account existence.
  expect(known.body).not.toMatch(/no account|not found|does not exist|unknown email/i);
  expect(unknown.body).not.toMatch(/no account|not found|does not exist|unknown email/i);
});

test('expired reset tokens are rejected', async ({ page }) => {
  const seeded = seedPasswordResetToken(fixtures.users.student, 'expired');
  await page.goto(`/reset-password.php?token=${encodeURIComponent(seeded.token)}`);

  await expect(page.getByText(/invalid or has expired|request a new one/i)).toBeVisible();
  await expect(page.locator('input[name="new_password"]')).toHaveCount(0);
});

test('used (reused) reset tokens are rejected', async ({ page }) => {
  const seeded = seedPasswordResetToken(fixtures.users.student, 'used');
  await page.goto(`/reset-password.php?token=${encodeURIComponent(seeded.token)}`);

  await expect(page.getByText(/invalid or has expired|request a new one/i)).toBeVisible();
  await expect(page.locator('input[name="new_password"]')).toHaveCount(0);
});
