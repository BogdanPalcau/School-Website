/**
 * Builder question-type matrix + publish version freeze.
 * Scoring/leakage covered elsewhere — this asserts authorship + version binding.
 */
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

test.setTimeout(120_000);

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

const QUESTION_SPECS = [
  {
    question_type: 'single_choice',
    prompt_html: '<p>SC?</p>',
    options: [
      { option_text_html: 'A', is_correct: 1, credit: 1 },
      { option_text_html: 'B', is_correct: 0, credit: 0 },
    ],
  },
  {
    question_type: 'multiple_choice',
    prompt_html: '<p>MC?</p>',
    options: [
      { option_text_html: 'A', is_correct: 1, credit: 1 },
      { option_text_html: 'B', is_correct: 1, credit: 1 },
      { option_text_html: 'C', is_correct: 0, credit: 0 },
    ],
  },
  { question_type: 'true_false', prompt_html: '<p>TF?</p>' },
  {
    question_type: 'short_text',
    prompt_html: '<p>ST?</p>',
    settings: { accepted_answers: ['paris'] },
  },
  {
    question_type: 'numeric',
    prompt_html: '<p>NUM?</p>',
    settings: { correct_value: 42, absolute_tolerance: 0.5 },
  },
  {
    question_type: 'fill_blank',
    prompt_html: '<p>The capital is [[paris]].</p>',
  },
  {
    question_type: 'ordering',
    prompt_html: '<p>Order</p>',
    options: [
      { option_text_html: 'First', is_correct: 0, credit: 0 },
      { option_text_html: 'Second', is_correct: 0, credit: 0 },
    ],
  },
  {
    question_type: 'matching',
    prompt_html: '<p>Match</p>',
    settings: { pairs: [{ left: 'A', right: '1' }, { left: 'B', right: '2' }] },
  },
  {
    question_type: 'long_response',
    prompt_html: '<p>Essay</p>',
    manual_marking: 1,
    points: 5,
  },
  {
    question_type: 'rating_scale',
    prompt_html: '<p>Rate</p>',
    points: 0,
    settings: { min: 1, max: 5 },
  },
];

test('builder can author all question types and freezes versions on publish', async ({ page }) => {
  const title = 'Builder Matrix Quiz';
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  let token = await csrfTokenFromPage(page);

  const createResp = await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'activity',
      title,
      activity_mode: 'quiz',
    },
    maxRedirects: 0,
  });
  const loc = createResp.headers().location || '';
  const match = loc.match(/id=(\d+)/);
  const activityId = match ? Number(match[1]) : lookupFixture('activity-id', title).id;
  expect(activityId).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);

  for (const spec of QUESTION_SPECS) {
    const resp = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
      data: {
        _token: token,
        action: 'add_question',
        id: activityId,
        ...spec,
      },
    });
    expect(resp.ok(), `add_question ${spec.question_type}`).toBeTruthy();
    const json = await resp.json();
    expect(json.ok, `add_question ${spec.question_type} ok`).toBeTruthy();
  }

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  let pub = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: { _token: token, action: 'publish', id: activityId },
  });
  expect(pub.ok()).toBeTruthy();
  expect((await pub.json()).ok).toBeTruthy();

  const publishedV1 = lookupFixture('published-version', String(activityId));
  expect(publishedV1.id).toBeTruthy();
  await signOut(page);

  await signInAs(page, fixtures, 'student');
  await page.goto(`/activity.php?id=${activityId}`);
  const playerToken = await page.locator('input[name="_token"], [data-csrf]').first().evaluate((el) => {
    if (el instanceof HTMLInputElement) {
      return el.value;
    }
    return el.getAttribute('data-csrf') || '';
  });
  const startResp = await page.context().request.post(`/activity.php?id=${activityId}`, {
    form: { _token: playerToken, action: 'start' },
  });
  expect(startResp.ok()).toBeTruthy();
  const startJson = await startResp.json();
  expect(startJson.ok).toBeTruthy();
  const attempt = lookupFixture('attempt', `${activityId}|${fixtures.users.student}`);
  expect(attempt.activity_version_id).toBe(publishedV1.id);
  // Leave attempt in progress (do not submit) so version binding can be checked after republish.
  await signOut(page);

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  // Editing after publish should target a new draft; add another question then republish.
  const addAfter = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'add_question',
      id: activityId,
      question_type: 'true_false',
      prompt_html: '<p>Post-publish draft Q?</p>',
    },
  });
  expect(addAfter.ok()).toBeTruthy();
  expect((await addAfter.json()).ok).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  pub = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: { _token: token, action: 'publish', id: activityId },
  });
  expect(pub.ok()).toBeTruthy();
  expect((await pub.json()).ok).toBeTruthy();

  const publishedV2 = lookupFixture('published-version', String(activityId));
  expect(publishedV2.id).toBeTruthy();
  expect(publishedV2.id).not.toBe(publishedV1.id);

  // In-progress attempt remains bound to the original published version.
  expect(countFixtureRecord('activity-attempt-version', `${activityId}|${publishedV1.id}`)).toBe(1);
  const stillBound = lookupFixture('attempt', `${activityId}|${fixtures.users.student}`);
  expect(stillBound.activity_version_id).toBe(publishedV1.id);
  expect(stillBound.status).toBe('in_progress');
});
