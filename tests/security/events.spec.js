const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  countFixtureRecord,
  resetLoginAttempts,
  setupSecurityFixtures,
  signIn,
  signOut,
} = require('./helpers');

function localDateTimePlusDays(days) {
  const d = new Date(Date.now() + days * 24 * 60 * 60 * 1000);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

test.describe.configure({ mode: 'serial' });

let fixtures;
let schoolEventId = 0;
let openCourseEventId = 0;
let blockedCourseEventId = 0;

test.beforeAll(() => {
  fixtures = setupSecurityFixtures();
});

test.afterAll(() => {
  cleanupSecurityFixtures();
});

test.beforeEach(() => {
  resetLoginAttempts();
});

test('admin can create a school-wide event and it appears on Events', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto('/events.php');

  await page.locator('#create-event').locator('summary').click();
  await page.locator('#create-event input[name="title"]').fill('Security School Assembly');
  await page.locator('#create-event input[name="summary"]').fill('Playwright school-wide event');
  await page.locator('#create-event input[name="starts_at"]').fill(localDateTimePlusDays(3));
  await page.locator('#create-event select[name="scope"]').selectOption('school');
  await page.locator('#create-event input[name="important"]').check();
  await page.locator('#create-event button[type="submit"]').click();

  await expect(page).toHaveURL(/events\.php\?event=\d+/);
  await expect(page.locator('.ev-hero-title')).toHaveText(/security school assembly/i);
  const match = page.url().match(/event=(\d+)/);
  schoolEventId = match ? Number(match[1]) : 0;
  expect(schoolEventId).toBeGreaterThan(0);
});

test('teacher can create a course event for an assigned course but not school-wide scope', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto('/events.php');

  await page.locator('#create-event').locator('summary').click();
  await expect(page.locator('#create-event select[name="scope"]')).toHaveCount(0);
  await expect(page.locator('#create-event input[name="important"]')).toHaveCount(0);

  await page.locator('#create-event input[name="title"]').fill('Security Open Course Event');
  await page.locator('#create-event input[name="summary"]').fill('Teacher course event');
  await page.locator('#create-event input[name="starts_at"]').fill(localDateTimePlusDays(4));
  await page.locator('#create-event select[name="course_id"]').selectOption(String(fixtures.courses.openCourseId));
  await page.locator('#create-event button[type="submit"]').click();

  await expect(page).toHaveURL(/events\.php\?event=\d+/);
  await expect(page.locator('.ev-hero-title')).toHaveText(/security open course event/i);
  const match = page.url().match(/event=(\d+)/);
  openCourseEventId = match ? Number(match[1]) : 0;
  expect(openCourseEventId).toBeGreaterThan(0);
});

test('student can open school-wide and enrolled course events but not another course event', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto('/events.php');
  await page.locator('#create-event').locator('summary').click();
  await page.locator('#create-event input[name="title"]').fill('Security Blocked Course Event');
  await page.locator('#create-event input[name="summary"]').fill('Should be IDOR-blocked');
  await page.locator('#create-event input[name="starts_at"]').fill(localDateTimePlusDays(5));
  await page.locator('#create-event select[name="scope"]').selectOption('course');
  await page.locator('#create-event select[name="course_id"]').selectOption(String(fixtures.courses.blockedCourseId));
  await page.locator('#create-event button[type="submit"]').click();
  await expect(page).toHaveURL(/events\.php\?event=\d+/);
  blockedCourseEventId = Number((page.url().match(/event=(\d+)/) || [])[1] || 0);
  expect(blockedCourseEventId).toBeGreaterThan(0);
  await signOut(page);

  await signIn(page, fixtures.users.student, fixtures.password);

  await page.goto(`/events.php?event=${schoolEventId}`);
  await expect(page.locator('.ev-hero-title')).toHaveText(/security school assembly/i);

  await page.goto(`/events.php?event=${openCourseEventId}`);
  await expect(page.locator('.ev-hero-title')).toHaveText(/security open course event/i);

  await page.goto(`/events.php?event=${blockedCourseEventId}`);
  await expect(page).toHaveURL(/events\.php(?!\?event=)/);
  await expect(page.getByText(/not available/i)).toBeVisible();
});

test('rejects forged create_event POST without CSRF token', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);

  await page.context().request.post('/events.php', {
    form: {
      action: 'create_event',
      title: 'Forged CSRF Event',
      summary: 'Should not be created',
      starts_at: localDateTimePlusDays(6),
      scope: 'school',
    },
  });

  expect(countFixtureRecord('event', 'Forged CSRF Event')).toBe(0);
});

test('student does not see create event panel', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto('/events.php');
  await expect(page.locator('#create-event')).toHaveCount(0);
});

test('dashboard shows upcoming events in Today / To do', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto('/dashboard.php');
  await expect(page.locator('#dashboard-upcoming-events')).toBeVisible();
  await expect(page.locator('#dashboard-upcoming-events')).toContainText(/security school assembly|security open course event/i);
});
