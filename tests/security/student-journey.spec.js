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

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('student journey links dashboard, course, discussion, grades, notifications, and settings', async ({ page }) => {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;
  const materialTitle = 'Journey Reading Link';
  const annTitle = 'Journey Announcement';

  // Seed content + an announcement (creates unread notifications for enrolled students).
  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  let token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'link',
      title: materialTitle,
      description: 'Journey material',
      url: 'https://example.com/journey-reading',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('item', materialTitle)).toBe(1);

  await page.goto(`${courseUrl}&section=announcements`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'post_announcement',
      title: annTitle,
      body: '<p>Please check the new reading.</p>',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('announcement', `${fixtures.courses.openSlug}|${annTitle}`)).toBe(1);
  expect(countFixtureRecord('unread-notifications', fixtures.users.student)).toBeGreaterThan(0);
  await signOut(page);

  // Continuous student session (no sign-out between handoffs).
  await signInAs(page, fixtures, 'student');

  await page.goto('/dashboard.php');
  await expect(page.getByRole('heading', { name: /security test - open course/i }).or(page.getByText(/open course/i)).first()).toBeVisible();
  const dashHasCourse = await page.locator(`a[href*="course=${fixtures.courses.openSlug}"]`).count();
  expect(dashHasCourse).toBeGreaterThan(0);

  await page.goto(`${courseUrl}&section=content`);
  // Unread announcement overlay blocks folder clicks until dismissed.
  const annOverlay = page.locator('#ann-notification.ann-notify-overlay');
  if (await annOverlay.isVisible().catch(() => false)) {
    await page.locator('#ann-mark-read').click();
    await expect(annOverlay).toBeHidden();
  }
  // Folder rows start collapsed for students — open the seeded upload folder.
  await page.locator('.folder-row').first().click();
  await expect(page.getByText(materialTitle)).toBeVisible();
  await expect(page.locator(`a[href*="example.com/journey-reading"]`)).toBeVisible();

  await page.goto(`${courseUrl}&section=discussions&topic=${fixtures.topicId}`);
  token = await csrfTokenFromPage(page);
  const replyBody = `<p>Journey reply ${Date.now()}</p>`;
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'post_reply',
      topic_id: String(fixtures.topicId),
      body: replyBody,
    },
    maxRedirects: 0,
  });
  await page.goto(`${courseUrl}&section=discussions&topic=${fixtures.topicId}`);
  await expect(page.getByText(/journey reply/i)).toBeVisible();

  await page.goto('/grades.php');
  await expect(page.locator('.grades-page, .grades-summary, .grades-module').first()).toBeVisible();
  await expect(page.getByText(/security student two/i)).toHaveCount(0);
  await expect(page.locator('.grades-person')).toHaveCount(0);

  const unreadBefore = countFixtureRecord('unread-notifications', fixtures.users.student);
  expect(unreadBefore).toBeGreaterThan(0);
  const notif = lookupFixture('unread-notification-id', fixtures.users.student);
  expect(notif.id).toBeTruthy();

  await page.goto('/notifications.php');
  // CSRF forms disappear once every notification is read — capture the token
  // while unread items still render mark-read / mark-all controls.
  token = await csrfTokenFromPage(page);
  await page.context().request.post('/notifications.php', {
    form: {
      _token: token,
      action: 'mark_notification_read',
      notification_id: String(notif.id),
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('unread-notifications', fixtures.users.student)).toBeLessThan(unreadBefore);

  await page.context().request.post('/notifications.php', {
    form: {
      _token: token,
      action: 'mark_all_notifications_read',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('unread-notifications', fixtures.users.student)).toBe(0);

  await page.goto('/settings.php#tab-profile');
  await expect(page.locator('input[name="email"]')).toBeEditable();
  await expect(page.getByRole('button', { name: /save profile/i })).toBeVisible();
});
