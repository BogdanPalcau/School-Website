/**
 * Owner-vs-admin gates (Gap 8).
 *
 * Developer security panel:
 * - Browser assertions assume Playwright's webServer sets
 *   PORTAL_SHOW_DEVELOPER_SECURITY=1 (see playwright.config.js).
 * - Flag-off behaviour cannot be flipped mid-suite without restarting PHP, so
 *   `checkDeveloperSecurityFlag()` exercises putenv 0/1 against
 *   portal_show_developer_security() in the fixture CLI process.
 */
const { test, expect } = require('@playwright/test');
const {
  checkDeveloperSecurityFlag,
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
  expect(countFixtureRecord('assignment-role', `${fixtures.courses.openSlug}|${fixtures.users.supervisorTeacher}|supervisor`)).toBe(1);
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(1);
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('delete_course succeeds for owner and is a no-op for admin', async ({ page }) => {
  const emptyId = String(fixtures.courses.emptyCourseId);
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(1);

  await signInAs(page, fixtures, 'admin');
  await page.goto('/admin.php?section=courses');
  await expect(page.getByRole('button', { name: /^delete$/i })).toHaveCount(0);

  const adminToken = await csrfTokenFromPage(page);
  await page.context().request.post('/admin.php?section=courses', {
    form: {
      _token: adminToken,
      action: 'delete_course',
      course_id: emptyId,
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(1);
  await signOut(page);

  await signInAs(page, fixtures, 'owner');
  await page.goto('/admin.php?section=courses');
  await expect(page.getByRole('button', { name: /^delete$/i }).first()).toBeVisible();

  const ownerToken = await csrfTokenFromPage(page);
  await page.context().request.post('/admin.php?section=courses', {
    form: {
      _token: ownerToken,
      action: 'delete_course',
      course_id: emptyId,
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(0);
  await signOut(page);
});

test('developer security panel is owner-only when the env flag is on', async ({ page }) => {
  const flag = checkDeveloperSecurityFlag();
  expect(flag.off).toBe(false);
  expect(flag.on).toBe(true);

  await signInAs(page, fixtures, 'admin');
  await page.goto('/admin.php?section=security');
  await expect(page.getByRole('heading', { name: /developer diagnostics/i })).toHaveCount(0);
  await expect(page.getByText(/contact the system developer/i)).toBeVisible();
  await signOut(page);

  await signInAs(page, fixtures, 'owner');
  await page.goto('/admin.php?section=security');
  await expect(page.getByRole('heading', { name: /developer diagnostics/i })).toBeVisible();
  await expect(page.getByText(/contact the system developer/i)).toHaveCount(0);
  await signOut(page);
});
