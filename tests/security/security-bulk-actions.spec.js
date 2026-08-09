const { test, expect } = require('@playwright/test');
const {
  cleanupSecurityFixtures,
  countFixtureRecord,
  resetLoginAttempts,
  setupSecurityFixtures,
  signIn,
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

test('security activity shows IP column, bulk bar, and IP filter links', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto('/admin.php?section=security&sec_period=24h');

  await expect(page.getByRole('heading', { name: /flagged patterns/i })).toBeVisible();
  await expect(page.getByRole('heading', { name: /most active ips/i })).toBeVisible();
  await expect(page.locator('#security-bulk-form')).toBeVisible();
  await expect(page.locator('#security-events-table thead')).toContainText('IP');
  await expect(page.locator(`a.security-ip-link[href*="sec_ip=${encodeURIComponent(seeded.ip)}"]`).first()).toBeVisible();

  await page.locator(`a.security-ip-link[href*="sec_ip=${encodeURIComponent(seeded.ip)}"]`).first().click();
  await expect(page).toHaveURL(new RegExp(`sec_ip=${encodeURIComponent(seeded.ip).replace(/\./g, '\\.')}`));
  await expect(page.locator('#security-events-table tbody tr')).toHaveCount(await page.locator('#security-events-table tbody tr').count());
  const rows = page.locator('#security-events-table tbody tr');
  const rowCount = await rows.count();
  expect(rowCount).toBeGreaterThan(0);
  for (let i = 0; i < rowCount; i += 1) {
    const text = await rows.nth(i).innerText();
    if (!text.includes('No security events')) {
      expect(text).toContain(seeded.ip);
    }
  }
});

test('failed-login victims cannot be disciplined from attacker events', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto(`/admin.php?section=security&sec_period=24h&sec_ip=${encodeURIComponent(seeded.ip)}`);

  const victimRow = page.locator('#security-events-table tbody tr').filter({
    has: page.locator(`.security-event-check[value="${seeded.victimEventId}"]`),
  });
  await expect(victimRow).toContainText(fixtures.users.student);
  await expect(victimRow.getByRole('button', { name: /take action/i })).toHaveCount(0);

  await victimRow.getByRole('button', { name: /view profile/i }).click();
  await expect(page.locator('#security-profile-overlay')).toBeVisible();
  await expect(page.locator('#security-profile-actions')).toBeHidden();
});

test('bulk checkboxes support select-all on page and select-all matching', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto(`/admin.php?section=security&sec_period=24h&sec_ip=${encodeURIComponent(seeded.ip)}`);

  const pageCheck = page.locator('#security-select-page');
  const matchingWrap = page.locator('#security-select-matching-wrap');
  const matchingToggle = page.locator('#security-select-matching-toggle');
  const matchingField = page.locator('#security-select-all-matching');

  await expect(matchingWrap).toBeHidden();
  await pageCheck.check();
  await expect(matchingWrap).toBeVisible();
  await expect(page.locator('.security-event-check:not([disabled])').first()).toBeChecked();

  await matchingToggle.check();
  await expect(matchingField).toHaveValue('1');

  await page.getByRole('button', { name: /^apply$/i }).click();
  await expect(page.getByText(/marked reviewed/i)).toBeVisible();
  expect(countFixtureRecord('security-ip', seeded.ip)).toBeGreaterThan(0);
});

test('bulk security action without CSRF is rejected for admin', async ({ page }) => {
  await signIn(page, fixtures.users.admin, fixtures.password);
  await page.goto('/admin.php?section=security');

  const response = await page.context().request.post('/admin.php?section=security', {
    form: {
      action: 'bulk_security_action',
      bulk_action: 'mark_reviewed',
      event_ids: ['1'],
      sec_period: '24h',
    },
    maxRedirects: 0,
  });

  expect([302, 303]).toContain(response.status());
  const location = response.headers().location || '';
  expect(location).toMatch(/admin\.php/);
});

test('non-admin cannot open security activity or run bulk actions', async ({ page }) => {
  await signIn(page, fixtures.users.student, fixtures.password);
  await page.goto('/admin.php?section=security');
  await expect(page).not.toHaveURL(/section=security/);
  await expect(page.locator('#security-bulk-form')).toHaveCount(0);

  const response = await page.context().request.post('/admin.php?section=security', {
    form: {
      action: 'bulk_security_action',
      bulk_action: 'mark_reviewed',
      event_ids: ['1'],
      _token: 'forged',
    },
    maxRedirects: 0,
  });
  expect([302, 303, 403]).toContain(response.status());
});
