const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  countFixtureRecord,
  csrfTokenFromPage,
  lookupFixture,
  resetLoginAttempts,
  setupSecurityFixtures,
  signInAs,
  signOut,
} = require('./helpers');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const rootDir = path.resolve(__dirname, '..', '..');
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const fixtureScript = path.join(rootDir, 'tests', 'fixtures', 'security-fixtures.php');
const phpSessionPath = path.join(rootDir, 'database');

function seedSecurityUiEvents() {
  const raw = execFileSync(phpBinary, ['-d', `session.save_path=${phpSessionPath}`, fixtureScript, 'seed-security-ui'], {
    cwd: rootDir,
    encoding: 'utf8',
  }).trim();
  return JSON.parse(raw);
}

/**
 * Per-role CSRF matrix (Gap 12). Reuses csrfTokenFromPage — forged POSTs omit _token.
 */
test.describe.configure({ mode: 'serial' });

let fixtures;
let seeded;

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
  seeded = seedSecurityUiEvents();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('student sensitive actions without CSRF do not write', async ({ page }) => {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;

  await signInAs(page, fixtures, 'student');
  await page.goto(`${courseUrl}&section=discussions&topic=${fixtures.topicId}`);

  await page.context().request.post(courseUrl, {
    form: {
      action: 'post_reply',
      topic_id: String(fixtures.topicId),
      body: '<p>Forged CSRF reply</p>',
    },
  });
  await page.goto(`${courseUrl}&section=discussions&topic=${fixtures.topicId}`);
  await expect(page.getByText(/forged csrf reply/i)).toHaveCount(0);
  await signOut(page);

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  const teacherToken = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: teacherToken,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'submission',
      title: 'CSRF Submit Slot',
      description: '',
      submission_deadline: '2099-01-01T12:00',
      submission_max_attempts: '1',
      submission_weight: '5',
    },
    maxRedirects: 0,
  });
  await signOut(page);

  const itemId = lookupFixture('item-id', `${fixtures.courses.openSlug}|CSRF Submit Slot`).id;
  expect(itemId).toBeTruthy();

  await signInAs(page, fixtures, 'student');
  await page.goto(`${courseUrl}&section=content`);
  await page.context().request.post(courseUrl, {
    form: {
      action: 'submit_work',
      item_id: String(itemId),
      submission_text: 'Forged submission without CSRF token.',
    },
  });
  expect(countFixtureRecord('submission-user', `${itemId}|${fixtures.users.student}`)).toBe(0);
});

test('teacher sensitive actions without CSRF do not write', async ({ page }) => {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;
  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);

  await page.context().request.post(courseUrl, {
    form: {
      action: 'create_folder',
      title: 'CSRF Forged Folder',
      description: 'should not exist',
    },
  });
  expect(countFixtureRecord('folder', 'CSRF Forged Folder')).toBe(0);

  await page.context().request.post(courseUrl, {
    form: {
      action: 'mark_submission',
      submission_id: '1',
      score: '99',
      feedback: 'Forged mark',
    },
  });

  await page.context().request.post(courseUrl, {
    form: {
      action: 'release_submission_grades',
      item_id: '1',
    },
  });
});

test('admin bulk_security_action without CSRF is rejected', async ({ page }) => {
  await signInAs(page, fixtures, 'admin');
  await page.goto('/admin.php?section=security');

  await page.context().request.post('/admin.php?section=security', {
    form: {
      action: 'bulk_security_action',
      bulk_action: 'mark_reviewed',
      'event_ids[]': seeded.ids.map(String),
    },
    maxRedirects: 0,
  });
});

test('owner delete_course without CSRF does not delete', async ({ page }) => {
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(1);
  await signInAs(page, fixtures, 'owner');
  await page.goto('/admin.php?section=courses');

  await page.context().request.post('/admin.php?section=courses', {
    form: {
      action: 'delete_course',
      course_id: String(fixtures.courses.emptyCourseId),
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('course', fixtures.courses.emptySlug)).toBe(1);
});
