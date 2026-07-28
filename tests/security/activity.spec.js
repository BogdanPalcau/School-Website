const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const {
  resetLoginAttempts,
  signIn,
} = require('./helpers');

const rootDir = path.resolve(__dirname, '..', '..');
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const activityFixtureScript = path.join(rootDir, 'tests', 'fixtures', 'activity-fixtures.php');

function runActivityFixture(command) {
  return execFileSync(phpBinary, [activityFixtureScript, command], {
    cwd: rootDir,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function setupActivityFixtures() {
  return JSON.parse(runActivityFixture('setup'));
}

function cleanupActivityFixtures() {
  runActivityFixture('cleanup');
}

test.describe.configure({ mode: 'serial' });

let fixtures;

test.beforeAll(() => {
  fixtures = setupActivityFixtures();
});

test.afterAll(() => {
  cleanupActivityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('student cannot open activity builder for a course they do not manage', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  const response = await page.goto(`/activity-builder.php?id=${fixtures.publishedActivityId}`);
  expect(response, 'expected a navigation response').toBeTruthy();
  const status = response.status();
  const body = await page.content();

  const denied =
    status === 403
    || /access denied/i.test(body)
    || /you cannot manage/i.test(body)
    || /dashboard\.php/.test(page.url());

  expect(denied).toBeTruthy();
  await expect(page.locator('[data-ab-root], #activity-builder, [data-ab-action="publish"]')).toHaveCount(0);
});

test('assigned teacher can open activity builder', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);

  const response = await page.goto(`/activity-builder.php?id=${fixtures.publishedActivityId}`);
  expect(response?.ok()).toBeTruthy();
  await expect(page.locator('[data-ab-title]')).toHaveText(/security published assessment/i);
  await expect(
    page.locator('[data-ab-action="publish"], [data-ab-action="unpublish"], [data-ab-action="validate"]').first()
  ).toBeVisible();
});

test('student assessment player payload does not leak answer keys while in progress', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}`);
  const token = await page.locator('input[name="_token"], [data-csrf]').first().evaluate((el) => {
    if (el instanceof HTMLInputElement) {
      return el.value;
    }
    return el.getAttribute('data-csrf') || '';
  });
  expect(token).toBeTruthy();

  const startResponse = await page.context().request.post(
    `/activity.php?id=${fixtures.publishedActivityId}`,
    {
      form: {
        _token: token,
        action: 'start',
        integrity_ack: 'I understand and will complete this assessment honestly.',
      },
    }
  );
  expect(startResponse.ok()).toBeTruthy();
  const startJson = await startResponse.json();
  expect(startJson.ok).toBeTruthy();

  const payload = JSON.stringify(startJson);
  expect(payload).not.toContain('"is_correct"');
  expect(payload).not.toContain('teacher_notes');
  expect(payload).not.toContain('Secret teacher note');
  expect(payload).not.toContain('Four is correct');

  const playerPage = await page.goto(
    `/activity.php?id=${fixtures.publishedActivityId}&resume=1`
  );
  expect(playerPage?.ok()).toBeTruthy();
  const html = await page.content();
  expect(html).not.toContain('"is_correct"');
  expect(html).not.toContain('teacher_notes');
  expect(html).not.toContain('Secret teacher note — must not leak');
});

test('builder POST without CSRF token fails', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);

  const response = await page.context().request.post(
    `/activity-builder.php?id=${fixtures.draftActivityId}`,
    {
      form: {
        action: 'save_settings',
        title: 'Forged CSRF Title Should Not Save',
        expected_revision: '1',
      },
    }
  );

  expect(response.status()).toBe(403);
  const body = await response.text();
  expect(/invalid security token|csrf|forbidden/i.test(body)).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${fixtures.draftActivityId}`);
  await expect(page.getByText(/Forged CSRF Title Should Not Save/i)).toHaveCount(0);
  await expect(page.locator('[data-ab-title]')).toHaveText(/security draft activity/i);
});
