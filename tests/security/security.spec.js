const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  countFixtureRecord,
  csrfTokenFromPage,
  resetLoginAttempts,
  setupSecurityFixtures,
  signIn,
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

test('redirects unauthenticated users away from protected pages', async ({ page }) => {
  await page.goto('/courses.php');
  await expect(page).toHaveURL(/login\.php/);

  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  await expect(page).toHaveURL(/login\.php/);
});

test('locks out repeated failed login attempts from the same client', async ({ page }) => {
  await page.goto('/login.php');

  for (let attempt = 0; attempt < 8; attempt += 1) {
    await page.getByLabel(/username or email/i).fill(fixtures.users.student);
    await page.getByLabel(/password/i).fill(`wrong-password-${attempt}`);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page.getByText(/does not look right/i)).toBeVisible();
  }

  await page.getByLabel(/username or email/i).fill(fixtures.users.student);
  await page.getByLabel(/password/i).fill(fixtures.password);
  await page.getByRole('button', { name: /sign in/i }).click();

  await expect(page.getByText(/too many failed sign-in attempts/i)).toBeVisible();
  await expect(page).toHaveURL(/login\.php/);
});

test('lets enrolled students open their course but blocks direct URL access to other courses', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  await expect(page.getByRole('heading', { name: /security test - open course/i })).toBeVisible();

  await page.goto(`/course.php?course=${fixtures.courses.blockedSlug}`);
  await expect(page).toHaveURL(/courses\.php/);
  await expect(page.getByRole('heading', { name: /security test - blocked course/i })).toHaveCount(0);
});

test('lets assigned teachers manage their course but blocks unassigned course access', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);

  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  await expect(page.getByText('New folder', { exact: true })).toBeVisible();

  await page.goto(`/course.php?course=${fixtures.courses.blockedSlug}`);
  await expect(page).toHaveURL(/courses\.php/);
  await expect(page.getByRole('heading', { name: /security test - blocked course/i })).toHaveCount(0);
});

test('rejects forged admin POST requests without a CSRF token', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);

  const response = await page.context().request.post('/admin.php', {
    form: {
      action: 'create_user',
      username: 'csrf_created_user',
      email: 'csrf_created_user@example.test',
      name: 'CSRF Created User',
      year: 'Year 11',
      programme: 'Security Test',
      password: fixtures.password,
      role: 'student',
    },
  });

  expect(response.status()).toBeLessThan(400);
  expect(countFixtureRecord('user', 'csrf_created_user')).toBe(0);
});

test('rejects forged course POST requests without a CSRF token', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    form: {
      action: 'create_folder',
      title: 'Forged CSRF Folder',
      description: 'This should not be created.',
    },
  });

  expect(countFixtureRecord('folder', 'Forged CSRF Folder')).toBe(0);
});

test('blocks cross-course folder deletion IDOR and preserves target files', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  const token = await csrfTokenFromPage(page);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    form: {
      _token: token,
      action: 'delete_folder',
      folder_id: String(fixtures.idorTargets.blockedFolderId),
    },
  });

  expect(countFixtureRecord('folder-id', String(fixtures.idorTargets.blockedFolderId))).toBe(1);
  expect(countFixtureRecord('item-id', String(fixtures.idorTargets.blockedItemId))).toBe(1);
  expect(countFixtureRecord('file', fixtures.idorTargets.blockedMaterialPath)).toBe(1);
  expect(countFixtureRecord('file', fixtures.idorTargets.blockedSubmissionPath)).toBe(1);
});

test('blocks cross-course item deletion IDOR and preserves submission files', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  const token = await csrfTokenFromPage(page);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    form: {
      _token: token,
      action: 'delete_item',
      item_id: String(fixtures.idorTargets.blockedItemId),
    },
  });

  expect(countFixtureRecord('item-id', String(fixtures.idorTargets.blockedItemId))).toBe(1);
  expect(countFixtureRecord('submission-for-item', String(fixtures.idorTargets.blockedItemId))).toBe(1);
  expect(countFixtureRecord('file', fixtures.idorTargets.blockedMaterialPath)).toBe(1);
  expect(countFixtureRecord('file', fixtures.idorTargets.blockedSubmissionPath)).toBe(1);
});

test('blocks cross-course group leave IDOR', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto(`/course.php?course=${fixtures.courses.openSlug}&section=groups`);
  const token = await csrfTokenFromPage(page);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    form: {
      _token: token,
      action: 'leave_group',
      group_id: String(fixtures.idorTargets.blockedGroupId),
    },
  });

  expect(countFixtureRecord('group-member', String(fixtures.idorTargets.blockedGroupId))).toBe(1);
});

test('blocks other users submission download IDOR', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);

  const response = await page
    .context()
    .request.get(`/download.php?sub=${fixtures.idorTargets.blockedSubmissionId}`, {
      maxRedirects: 0,
    });

  expect(response.status()).toBe(403);
});

test('does not expose sensitive runtime paths over HTTP', async ({ request }) => {
  const probes = [
    '/database/portal.db',
    '/database/INITIAL_OWNER_PASSWORD.txt',
    '/uploads/',
    '/bootstrap.php',
    '/db_init.php',
    '/course_catalog.php',
    '/layout.php',
    '/.git/config',
    '/.env',
  ];

  for (const path of probes) {
    const response = await request.get(path);
    expect(response.status(), `${path} should not be directly readable`).not.toBe(200);
    const body = await response.text();
    expect(body, `${path} should not leak SQLite file contents`).not.toContain('SQLite format 3');
    expect(body, `${path} should not leak PHP source`).not.toContain('<?php');
  }
});

test('rejects uploaded files whose content does not match the claimed extension', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  const token = await csrfTokenFromPage(page);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    multipart: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'document',
      title: 'Disguised PHP Payload',
      description: 'Fake PDF attack payload.',
      file: {
        name: 'payload.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.from('<?php echo "owned"; ?>'),
      },
    },
  });

  expect(countFixtureRecord('item', 'Disguised PHP Payload')).toBe(0);
});

test('accepts a legitimate text material upload from an assigned teacher', async ({ page }) => {
  await signIn(page, fixtures.users.teacher, fixtures.password);
  await page.goto(`/course.php?course=${fixtures.courses.openSlug}`);
  const token = await csrfTokenFromPage(page);

  await page.context().request.post(`/course.php?course=${fixtures.courses.openSlug}`, {
    multipart: {
      _token: token,
      action: 'create_item',
      folder_id: String(fixtures.folderId),
      type: 'document',
      title: 'Legitimate Text Material',
      description: 'Control upload that should be accepted.',
      allow_download: '1',
      file: {
        name: 'legitimate.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('This is a normal class material upload.'),
      },
    },
  });

  expect(countFixtureRecord('item', 'Legitimate Text Material')).toBe(1);
});

test('clears failed login attempts after a successful sign-in', async ({ page }) => {
  await page.goto('/login.php');
  await page.getByLabel(/username or email/i).fill(fixtures.users.outsider);
  await page.getByLabel(/password/i).fill('wrong-password');
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page.getByText(/does not look right/i)).toBeVisible();

  await page.getByLabel(/username or email/i).fill(fixtures.users.outsider);
  await page.getByLabel(/password/i).fill(fixtures.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/courses\.php/);

  await signOut(page);

  await page.goto('/login.php');
  await page.getByLabel(/username or email/i).fill(fixtures.users.outsider);
  await page.getByLabel(/password/i).fill(fixtures.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/courses\.php/);
});
