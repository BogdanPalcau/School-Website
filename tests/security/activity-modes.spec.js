/**
 * Browser UI matrix for Practice / Quiz / Challenge / Survey.
 *
 * Intentionally does NOT re-test scoring math, answer-key leakage, XP idempotency,
 * or integrity clipboard rules — those are covered by tests/activity_system_check.php
 * and tests/security/activity.spec.js (Assessment). Focus here: mode lobby CTAs,
 * start/autosave/submit handoffs, and mode-specific attempt/feedback expectations.
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

async function createPublishedTrueFalse(page, fixtures, { title, mode, maxAttempts = 0, xpEnabled = 0 }) {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;
  await page.goto(`${courseUrl}&section=content`);
  let token = await csrfTokenFromPage(page);

  const createResp = await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'activity',
      title,
      activity_mode: mode,
    },
    maxRedirects: 0,
  });
  const location = createResp.headers().location || '';
  const idMatch = location.match(/activity-builder\.php\?id=(\d+)/);
  let activityId = idMatch ? Number(idMatch[1]) : lookupFixture('activity-id', title).id;
  expect(activityId).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  const revision = Number(await page.locator('[data-revision]').first().getAttribute('data-revision') || '1');

  const settingsResp = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'save_settings',
      id: activityId,
      revision,
      max_attempts: maxAttempts,
      xp_enabled: xpEnabled,
      xp_amount: xpEnabled ? 15 : 0,
      leaderboard_enabled: 0,
    },
  });
  expect(settingsResp.ok()).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  const addResp = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'add_question',
      id: activityId,
      question_type: mode === 'survey' ? 'rating_scale' : 'true_false',
      prompt_html: mode === 'survey' ? '<p>How useful was this?</p>' : '<p>Penguins can fly?</p>',
      points: mode === 'survey' ? 0 : 1,
      settings: mode === 'survey' ? { min: 1, max: 5 } : undefined,
    },
  });
  expect(addResp.ok()).toBeTruthy();
  const addJson = await addResp.json();
  expect(addJson.ok).toBeTruthy();

  await page.goto(`/activity-builder.php?id=${activityId}`);
  token = await csrfTokenFromPage(page);
  const pub = await page.context().request.post(`/activity-builder.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'publish',
      id: activityId,
    },
  });
  expect(pub.ok()).toBeTruthy();
  const pubJson = await pub.json();
  expect(pubJson.ok).toBeTruthy();

  return activityId;
}

async function startAndSubmitTrueFalse(page, activityId, { expectCorrectConcept = true } = {}) {
  await page.goto(`/activity.php?id=${activityId}`);
  const token = await page.locator('input[name="_token"], [data-csrf]').first().evaluate((el) => {
    if (el instanceof HTMLInputElement) {
      return el.value;
    }
    return el.getAttribute('data-csrf') || '';
  });

  const startResp = await page.context().request.post(`/activity.php?id=${activityId}`, {
    form: { _token: token, action: 'start' },
  });
  expect(startResp.ok()).toBeTruthy();
  const startJson = await startResp.json();
  expect(startJson.ok).toBeTruthy();

  const attemptId = startJson.attempt?.id || startJson.attempt_id;
  const sessionToken = startJson.token;
  const question = (startJson.questions || [])[0];
  expect(question).toBeTruthy();

  if (expectCorrectConcept) {
    expect(JSON.stringify(startJson)).not.toMatch(/"is_correct"\s*:\s*1/);
  } else {
    // Survey: no correct-answer framing in lobby/player payload.
    expect(JSON.stringify(question)).not.toContain('"is_correct"');
  }

  let answer;
  if (question.question_type === 'rating_scale') {
    answer = { value: 4 };
  } else {
    const options = question.options || [];
    const falseOpt = options.find((o) => /false/i.test(String(o.option_text_html || o.label || ''))) || options[1] || options[0];
    answer = { option_id: Number(falseOpt.id) };
  }

  const saveResp = await page.context().request.post(`/activity.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'save_answer',
      attempt_id: attemptId,
      token: sessionToken,
      question_id: question.id,
      answer,
      revision: 1,
    },
  });
  expect(saveResp.ok()).toBeTruthy();
  const saveJson = await saveResp.json();
  expect(saveJson.ok).toBeTruthy();

  const submitResp = await page.context().request.post(`/activity.php?id=${activityId}`, {
    data: {
      _token: token,
      action: 'submit',
      attempt_id: attemptId,
      token: sessionToken,
    },
  });
  expect(submitResp.ok()).toBeTruthy();
  const submitJson = await submitResp.json();
  expect(submitJson.ok).toBeTruthy();

  return { startJson, submitJson };
}

test('practice / quiz / challenge / survey support start, autosave, and submit', async ({ page }) => {
  await signInAs(page, fixtures, 'teacher');
  const practiceId = await createPublishedTrueFalse(page, fixtures, {
    title: 'Modes Practice TF',
    mode: 'practice',
    maxAttempts: 0,
    xpEnabled: 1,
  });
  const quizId = await createPublishedTrueFalse(page, fixtures, {
    title: 'Modes Quiz TF',
    mode: 'quiz',
    maxAttempts: 1,
  });
  const challengeId = await createPublishedTrueFalse(page, fixtures, {
    title: 'Modes Challenge TF',
    mode: 'challenge',
    maxAttempts: 2,
    xpEnabled: 1,
  });
  const surveyId = await createPublishedTrueFalse(page, fixtures, {
    title: 'Modes Survey Scale',
    mode: 'survey',
    maxAttempts: 1,
  });
  await signOut(page);

  await signInAs(page, fixtures, 'student');

  await page.goto(`/activity.php?id=${practiceId}`);
  await expect(page.getByRole('button', { name: /start activity/i })).toBeVisible();
  await startAndSubmitTrueFalse(page, practiceId);
  // Practice allows another attempt (unlimited).
  await page.goto(`/activity.php?id=${practiceId}`);
  await expect(page.getByRole('button', { name: /start activity/i })).toBeVisible();
  await startAndSubmitTrueFalse(page, practiceId);

  await page.goto(`/activity.php?id=${quizId}`);
  await expect(page.getByRole('button', { name: /start activity/i })).toBeVisible();
  await startAndSubmitTrueFalse(page, quizId);
  await page.goto(`/activity.php?id=${quizId}`);
  // max_attempts=1 — no fresh start CTA after using the only attempt.
  await expect(page.getByRole('button', { name: /start activity/i })).toHaveCount(0);

  await page.goto(`/activity.php?id=${challengeId}`);
  await expect(page.getByRole('button', { name: /start activity/i })).toBeVisible();
  await startAndSubmitTrueFalse(page, challengeId);

  await page.goto(`/activity.php?id=${surveyId}`);
  await expect(page.getByRole('button', { name: /start activity/i })).toBeVisible();
  const survey = await startAndSubmitTrueFalse(page, surveyId, { expectCorrectConcept: false });
  expect(JSON.stringify(survey.submitJson)).not.toMatch(/correct answer/i);

  expect(countFixtureRecord('activity-by-title', 'Modes Practice TF')).toBe(1);
  expect(countFixtureRecord('activity-by-title', 'Modes Survey Scale')).toBe(1);
});
