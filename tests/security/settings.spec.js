const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  countFixtureRecord,
  csrfTokenFromPage,
  resetLoginAttempts,
  setupSecurityFixtures,
  signInAs,
  signOut,
} = require('./helpers');

test.describe.configure({ mode: 'serial' });

let fixtures;

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('student can edit email with password confirm and rejects duplicates', async ({ page }) => {
  await signInAs(page, fixtures, 'student');
  await page.goto('/settings.php#tab-profile');

  const emailInput = page.locator('input[name="email"]');
  await expect(emailInput).toBeVisible();
  await expect(emailInput).toBeEditable();

  const token = await csrfTokenFromPage(page);
  const attempted = 'sec_student_two@example.test';

  await page.context().request.post('/settings.php', {
    form: {
      _token: token,
      action: 'update_profile',
      name: 'Security Student',
      email: attempted,
      email_confirm_password: fixtures.password,
    },
    maxRedirects: 0,
  });

  expect(countFixtureRecord('user-email', `${fixtures.users.student}|${attempted}`)).toBe(0);
  expect(countFixtureRecord('user-email', `${fixtures.users.student}|${fixtures.emails.student}`)).toBe(1);

  await page.goto('/settings.php#tab-profile');
  const token2 = await csrfTokenFromPage(page);
  const uniqueEmail = 'sec_student_renamed@example.test';

  await page.context().request.post('/settings.php', {
    form: {
      _token: token2,
      action: 'update_profile',
      name: 'Security Student',
      email: uniqueEmail,
      email_confirm_password: fixtures.password,
    },
    maxRedirects: 0,
  });

  expect(countFixtureRecord('user-email', `${fixtures.users.student}|${uniqueEmail}`)).toBe(1);

  await page.goto('/settings.php#tab-profile');
  const token3 = await csrfTokenFromPage(page);
  await page.context().request.post('/settings.php', {
    form: {
      _token: token3,
      action: 'update_profile',
      name: 'Security Student',
      email: fixtures.emails.student,
      email_confirm_password: fixtures.password,
    },
    maxRedirects: 0,
  });

  expect(countFixtureRecord('user-email', `${fixtures.users.student}|${fixtures.emails.student}`)).toBe(1);
  await signOut(page);
});

test('owner can edit email on settings', async ({ page }) => {
  await signInAs(page, fixtures, 'owner');
  await page.goto('/settings.php#tab-profile');

  await expect(page.locator('input[name="email"]')).toBeVisible();
  await expect(page.locator('input[name="email"]')).toBeEditable();
  await expect(page.getByText(/ask an admin to change this/i)).toHaveCount(0);
  await signOut(page);
});

test('teacher and admin cannot change email via UI or forged POST', async ({ page }) => {
  for (const roleKey of ['teacher', 'admin']) {
    await signInAs(page, fixtures, roleKey);
    await page.goto('/settings.php#tab-profile');

    await expect(page.locator('input[name="email"]')).toHaveCount(0);
    await expect(page.getByText(/ask an admin to change this/i)).toBeVisible();

    const originalEmail = fixtures.emails[roleKey];
    const token = await csrfTokenFromPage(page);
    await page.context().request.post('/settings.php', {
      form: {
        _token: token,
        action: 'update_profile',
        name: roleKey === 'teacher' ? 'Security Teacher' : 'Security Admin',
        email: `forged-${roleKey}@example.test`,
        email_confirm_password: fixtures.password,
      },
      maxRedirects: 0,
    });

    expect(countFixtureRecord('user-email', `${fixtures.users[roleKey]}|${originalEmail}`)).toBe(1);
    expect(countFixtureRecord('user-email', `${fixtures.users[roleKey]}|forged-${roleKey}@example.test`)).toBe(0);

    await signOut(page);
  }
});
