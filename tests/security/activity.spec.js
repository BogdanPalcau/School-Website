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
const phpSessionPath = path.join(rootDir, 'database');

function runActivityFixture(command, ...args) {
  return execFileSync(phpBinary, ['-d', `session.save_path=${phpSessionPath}`, activityFixtureScript, command, ...args], {
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

function allowActivityResume(activityId, username = 'sec_student') {
  return JSON.parse(runActivityFixture('allow-resume', String(activityId), username));
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

test('draft activities are invisible and inaccessible to students', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  await page.goto('/course.php?course=security-open-course&section=content');
  await expect(page.getByText(/security draft activity/i)).toHaveCount(0);
  await expect(page.locator(`a[href*="activity.php?id=${fixtures.draftActivityId}"]`)).toHaveCount(0);

  const directResponse = await page.goto(`/activity.php?id=${fixtures.draftActivityId}`);
  expect(directResponse?.status()).toBe(404);
  await expect(page.getByText(/activity not found/i)).toBeVisible();
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

test('builder exposes readiness, rewards, and assessment-integrity guidance', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/activity-builder.php?id=${fixtures.publishedActivityId}`);

  const stylesheet = await page.request.get('/style.php');
  expect(stylesheet.ok()).toBeTruthy();
  expect(stylesheet.headers()['content-type']).toContain('text/css');
  await expect(page.locator('[data-ab-setup-strip]')).toBeVisible();
  await expect(page.locator('[data-ab-rewards-settings]')).toBeVisible();
  await expect(page.locator('[data-ab-setting-bool="xp_enabled"]')).toBeChecked();
  await expect(page.locator('[data-ab-setting="xp_amount"]')).toHaveValue('30');
  await expect(page.locator('[data-ab-setting-bool="leaderboard_enabled"]')).toBeChecked();
  await expect(page.locator('[data-ab-integrity-settings]')).toHaveAttribute('open', '');
});

test('teacher sees integrity failures before opening the signal timeline', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/activity-results.php?id=${fixtures.publishedActivityId}&attempt=${fixtures.flaggedAttemptId}`);

  const flaggedRow = page.locator(`[data-ar-attempt-row="${fixtures.flaggedAttemptId}"]`);
  await expect(flaggedRow.getByText(/integrity flagged/i)).toBeVisible();
  const rowLayout = await flaggedRow.evaluate((row) => {
    const score = row.querySelector('.ar-attempt-score')?.getBoundingClientRect();
    const integrity = row.querySelector('.ar-attempt-pill--integrity')?.getBoundingClientRect();
    const bounds = row.getBoundingClientRect();
    const overlaps = !!(score && integrity
      && score.left < integrity.right && score.right > integrity.left
      && score.top < integrity.bottom && score.bottom > integrity.top);
    return {
      fits: row.scrollWidth <= row.clientWidth,
      scoreInside: !score || (score.left >= bounds.left && score.right <= bounds.right),
      integrityInside: !integrity || (integrity.left >= bounds.left && integrity.right <= bounds.right),
      overlaps,
    };
  });
  expect(rowLayout).toEqual({ fits: true, scoreInside: true, integrityInside: true, overlaps: false });
  const alert = page.locator('.ar-integrity-alert');
  await expect(alert).toBeVisible();
  await expect(alert.getByText(/integrity checks flagged this attempt/i)).toBeVisible();
  await expect(page.locator('[data-ar-integrity-panel]')).toHaveAttribute('open', '');
  await expect(page.locator('.ar-end-reason')).toContainText(/assessment page was left/i);
  await expect(page.locator('.ar-mark-banner')).toContainText(/marking complete/i);
  await expect(page.getByRole('button', { name: /^complete marking$/i })).toHaveCount(0);
  await expect(page.getByRole('button', { name: /save feedback/i })).toBeVisible();
  const reopenButton = page.getByRole('button', { name: /reopen attempt/i });
  await expect(reopenButton).toBeVisible();
  await reopenButton.click();
  const officialPrompt = page.locator('[data-ar-reopen-prompt]');
  await expect(officialPrompt).toBeVisible();
  await expect(officialPrompt).toContainText(/RIEO/i);
  await officialPrompt.locator('[data-ar-reopen-note]').fill('Reopened during browser regression test.');
  await officialPrompt.getByRole('button', { name: /authorise reopening/i }).click();
  await expect(page.locator('.ar-reopened-banner')).toContainText(/student can return once/i);

  // Restore the serial fixture so subsequent student tests start from the
  // intended attempt count and lifecycle state.
  cleanupActivityFixtures();
  fixtures = setupActivityFixtures();
});

test('released activity grades appear in the staff Grades view', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto('/grades.php');

  const returned = page.locator('.grades-work-list--returned');
  await expect(returned).toContainText(/security published assessment/i);
  await expect(returned.locator(`a[href*="attempt=${fixtures.releasedAttemptId}"]`)).toBeVisible();
});

test('released and unreleased marking workspaces keep the same width', async ({ page }) => {
  await page.setViewportSize({ width: 1720, height: 1000 });
  await signIn(page, fixtures.users.teacher, fixtures.password);

  await page.goto(`/activity-results.php?id=${fixtures.publishedActivityId}&attempt=${fixtures.flaggedAttemptId}`);
  const marked = await page.locator('.activity-results').evaluate((el) => ({
    workspace: el.getBoundingClientRect().width,
    detail: el.querySelector('.ar-detail-panel')?.getBoundingClientRect().width || 0,
  }));
  await page.goto(`/activity-results.php?id=${fixtures.publishedActivityId}&attempt=${fixtures.releasedAttemptId}`);
  const released = await page.locator('.activity-results').evaluate((el) => ({
    workspace: el.getBoundingClientRect().width,
    detail: el.querySelector('.ar-detail-panel')?.getBoundingClientRect().width || 0,
  }));

  expect(Math.abs(marked.workspace - released.workspace)).toBeLessThan(1);
  expect(Math.abs(marked.detail - released.detail)).toBeLessThan(1);
});

test('newly released grade shows an unread badge until Grades is opened', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  const gradesLink = page.locator('a.nav-link[href="grades.php"]');
  await expect(gradesLink.locator('.nav-count')).toHaveText('1');
  await page.goto('/course.php?course=security-open-course&section=content');
  const courseGradesTab = page.locator('a.course-tab[href*="section=gradebook"]');
  await expect(courseGradesTab.locator('.course-tab-badge')).toHaveText('1');
  await courseGradesTab.click();
  await expect(page).toHaveURL(/section=gradebook/);
  await expect(page.getByText(/security published assessment/i).first()).toBeVisible();
  await expect(page.locator('a[href*="activity-results.php"][href*="attempt="]')).toBeVisible();
  await expect(page.locator('a.course-tab[href*="section=gradebook"] .course-tab-badge')).toHaveCount(0);
  await expect(page.locator('a.nav-link[href="grades.php"] .nav-count')).toHaveCount(0);
});

test('student lobby shows the configured XP reward and working leaderboard', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}`);

  await expect(page.getByText(/one continuous sitting/i)).toBeVisible();
  await expect(page.getByText(/integrity checks are enabled/i)).toBeVisible();
  const dialogMessages = [];
  page.on('dialog', async (dialog) => {
    dialogMessages.push(dialog.message());
    await dialog.dismiss();
  });
  await page.getByRole('button', { name: /start assessment/i }).click();
  await expect(page.locator('[data-ap-integrity-error]')).toBeVisible();
  expect(dialogMessages).toEqual([]);
  await expect(page.getByText('+30 XP', { exact: true })).toBeVisible();
  await expect(page.locator('.activity-leaderboard--lobby')).toBeVisible();
  await expect(page.locator('.activity-leaderboard-row.is-self')).toContainText(/security student/i);
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

  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}`);
  await expect(page.getByText(/one continuous sitting/i)).toBeVisible();
  await expect(page.getByText(/attempt in progress/i)).toHaveCount(0);
  await expect(page.locator('[data-ap-action="resume"]')).toHaveCount(0);
  await expect(page.getByRole('button', { name: /view last result/i })).toBeVisible();

  const playerPage = await page.goto(
    `/activity.php?id=${fixtures.publishedActivityId}&resume=1`
  );
  expect(playerPage?.ok()).toBeTruthy();
  await expect(page.locator('[data-ap-shell]')).toBeHidden();
  const html = await page.content();
  expect(html).not.toContain('"is_correct"');
  expect(html).not.toContain('teacher_notes');
  expect(html).not.toContain('Secret teacher note — must not leak');

  // A final available attempt exercises the student-facing safety UX.
  await page.locator('[data-ap-integrity-ack]').check();
  await page.getByRole('button', { name: /start assessment/i }).click();
  await expect(page.locator('[data-ap-shell]')).toBeVisible();

  await page.locator('[data-ap-shell] .ap-back').click();
  await expect(page.getByRole('heading', { name: /leave and end assessment/i })).toBeVisible();
  await expect(page.locator('[data-ap-confirm-body]')).toContainText(/will end and submit the assessment/i);
  await page.getByRole('button', { name: /stay in assessment/i }).click();
  await expect(page.locator('[data-ap-shell]')).toBeVisible();

  await page.evaluate(() => window.dispatchEvent(new Event('offline')));
  await expect(page.locator('[data-ap-network]')).toBeVisible();
  await expect(page.locator('[data-ap-network-title]')).toContainText(/connection lost/i);
  await page.evaluate(() => window.dispatchEvent(new Event('online')));
  await expect(page.locator('[data-ap-network]')).toBeHidden();

  await page.evaluate(() => window.history.back());
  await expect(page.getByRole('heading', { name: /leave and end assessment/i })).toBeVisible();
  await page.getByRole('button', { name: /stay in assessment/i }).click();
  await expect(page.locator('[data-ap-shell]')).toBeVisible();

  await page.locator('[data-ap-shell] .ap-back').click();
  await page.getByRole('button', { name: /leave and end attempt/i }).click();
  await expect(page).toHaveURL(/course\.php/);

  // Reproduce the original autosave race: hold the save request open, then
  // submit immediately. Submission must wait and retain the selected option.
  await page.route('**/activity.php?id=*', async (route) => {
    let body = {};
    try { body = route.request().postDataJSON() || {}; } catch (_) { /* form/GET */ }
    if (body.action === 'save_answer') {
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
    await route.continue();
  });
  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}`);
  await page.locator('[data-ap-integrity-ack]').check();
  await page.getByRole('button', { name: /start assessment/i }).click();
  await expect(page.locator('[data-ap-shell]')).toBeVisible();
  await page.getByText('4', { exact: true }).click();
  await page.getByRole('button', { name: /submit quiz/i }).click();
  const submitResponsePromise = page.waitForResponse((response) => {
    if (!response.url().includes('/activity.php?id=')) return false;
    try { return response.request().postDataJSON()?.action === 'submit'; } catch (_) { return false; }
  });
  await page.locator('[data-ap-confirm-ok]').click();
  const submitJson = await (await submitResponsePromise).json();
  const savedAnswers = Object.values(submitJson.player?.answers || {});
  expect(savedAnswers.some((entry) => Number(entry?.answer?.option_id) > 0)).toBeTruthy();
});

test('saved activity answers remain selected after resume', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}`);

  await page.locator('[data-ap-integrity-ack]').check();
  await page.getByRole('button', { name: /start assessment/i }).click();
  await expect(page.locator('[data-ap-shell]')).toBeVisible();
  await expect(page.getByText('What is 2+2?')).toBeVisible();

  await page.getByText('4', { exact: true }).click();
  await expect(page.locator('[data-ap-save-state]')).toHaveText('Saved');

  // Leaving an assessment ends it; teacher reopen restores resume_allowed.
  await page.goto('/courses.php');
  await expect(page).toHaveURL(/courses\.php/);

  const allowed = allowActivityResume(fixtures.publishedActivityId, fixtures.users.student);
  expect(allowed.ok).toBeTruthy();
  expect(allowed.mode).toBe('reopened');

  await page.goto(`/activity.php?id=${fixtures.publishedActivityId}&resume=1`);
  await expect(page.locator('[data-ap-shell]')).toBeVisible();
  await expect(page.getByText('What is 2+2?')).toBeVisible();
  await expect(
    page.locator('.ap-option')
      .filter({ has: page.getByText('4', { exact: true }) })
      .locator('input[type="radio"]')
  ).toBeChecked();
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
