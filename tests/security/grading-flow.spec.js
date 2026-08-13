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

test.describe.configure({ mode: 'serial' });

let fixtures;
let itemId;

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('assignment submit before deadline, block after, grade/feedback peer-isolated', async ({ page }) => {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;
  const title = 'Grading Flow Essay';
  const futureDeadline = '2099-12-31T17:00';
  const pastDeadline = '2020-01-01T00:00';

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  let token = await csrfTokenFromPage(page);

  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'submission',
      title,
      description: 'Submit a short text file.',
      submission_deadline: futureDeadline,
      submission_max_attempts: '2',
      submission_weight: '10',
    },
    maxRedirects: 0,
  });

  itemId = lookupFixture('item-id', `${fixtures.courses.openSlug}|${title}`).id;
  expect(itemId).toBeTruthy();
  await signOut(page);

  await signInAs(page, fixtures, 'student');
  await page.goto(`${courseUrl}&section=content`);
  token = await csrfTokenFromPage(page);
  // portal_submission_min_words() === 20; keep paste text clearly above that floor.
  const onTimeEssay =
    'Student one essay body with enough words for a normal pasted submission. '
    + 'This paragraph adds detail so the integrity length gates accept the work.';
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'submit_work',
      item_id: String(itemId),
      submission_text: onTimeEssay,
      process_edit_seconds: '12',
      process_paste_events: '0',
      process_pasted_chars: '0',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('submission-user', `${itemId}|${fixtures.users.student}`)).toBe(1);
  await signOut(page);

  // Force deadline past, then student_two is blocked.
  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'update_item_settings',
      item_id: String(itemId),
      title,
      description: 'Submit a short text file.',
      submission_deadline: pastDeadline,
      submission_max_attempts: '2',
      submission_weight: '10',
    },
    maxRedirects: 0,
  });
  await signOut(page);

  await signInAs(page, fixtures, 'studentTwo');
  await page.goto(`${courseUrl}&section=content`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'submit_work',
      item_id: String(itemId),
      submission_text:
        'This late attempt should be rejected by the deadline gate even though the '
        + 'pasted text is long enough to pass the minimum word and character checks.',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('submission-user', `${itemId}|${fixtures.users.studentTwo}`)).toBe(0);
  await signOut(page);

  const submission = lookupFixture('submission', `${itemId}|${fixtures.users.student}`);
  expect(submission.id).toBeTruthy();

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=gradebook`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'mark_submission',
      submission_id: String(submission.id),
      score: '88',
      feedback: 'Strong structure — keep developing evidence.',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('submission-score', `${itemId}|${fixtures.users.student}|88`)).toBe(1);

  token = await csrfTokenFromPage(page);
  const annResp = await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'save_annotation',
      submission_id: String(submission.id),
      annotation_id: '0',
      anchor_type: 'general',
      comment: 'Highlight the thesis next time.',
      quote: '',
      range_start: '',
      range_end: '',
      pos_x: '',
      pos_y: '',
    },
  });
  expect(annResp.ok()).toBeTruthy();
  const annJson = await annResp.json();
  expect(annJson.ok).toBeTruthy();
  await signOut(page);

  await signInAs(page, fixtures, 'student');
  await page.goto(`${courseUrl}&section=gradebook`);
  await expect(page.locator('.sub-slot-status--graded').filter({ hasText: '88%' }).first()).toBeVisible();
  await page.getByRole('button', { name: /open result for grading flow/i }).click();
  await expect(page.getByText(/strong structure/i)).toBeVisible();
  await page.goto('/grades.php');
  await expect(page.getByText(/88%/).first()).toBeVisible();
  await signOut(page);

  await signInAs(page, fixtures, 'studentTwo');
  await page.goto(`${courseUrl}&section=gradebook`);
  await expect(page.getByText(/strong structure/i)).toHaveCount(0);
  await expect(page.getByText(/security student(?! two)/i)).toHaveCount(0);
  const body = await page.content();
  expect(body).not.toContain('Strong structure — keep developing evidence.');
  await page.goto('/grades.php');
  await expect(page.getByText(/strong structure/i)).toHaveCount(0);
});
