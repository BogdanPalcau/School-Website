const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { expect } = require('@playwright/test');

const rootDir = path.resolve(__dirname, '..', '..');
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const fixtureScript = path.join(rootDir, 'tests', 'fixtures', 'security-fixtures.php');
const phpSessionPath = path.join(rootDir, 'database');

function runFixture(command, ...args) {
  return execFileSync(phpBinary, ['-d', `session.save_path=${phpSessionPath}`, fixtureScript, command, ...args], {
    cwd: rootDir,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}

function setupSecurityFixtures() {
  return JSON.parse(runFixture('setup'));
}

function cleanupSecurityFixtures() {
  runFixture('cleanup');
}

function resetLoginAttempts() {
  runFixture('reset-login');
}

function countFixtureRecord(kind, value) {
  return Number(runFixture('count', kind, value));
}

function lookupFixture(kind, value) {
  return JSON.parse(runFixture('lookup', kind, value));
}

function seedPasswordResetToken(username, state = 'valid') {
  return JSON.parse(runFixture('seed-reset-token', username, state));
}

function checkDeveloperSecurityFlag() {
  return JSON.parse(runFixture('check-dev-security'));
}

async function signIn(page, username, password) {
  await page.goto('/login.php');
  await page.getByLabel(/username or email/i).fill(username);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/dashboard\.php/);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {ReturnType<typeof setupSecurityFixtures>} fixtures
 * @param {string} roleKey
 */
async function signInAs(page, fixtures, roleKey) {
  const username = fixtures.users[roleKey];
  if (!username) {
    throw new Error(`Unknown fixture user key: ${roleKey}`);
  }
  await signIn(page, username, fixtures.password);
}

async function signOut(page) {
  await page.goto('/logout.php');
  await expect(page).toHaveURL(/login\.php/);
}

async function csrfTokenFromPage(page) {
  const input = page.locator('input[name="_token"]').first();
  if (await input.count()) {
    const token = await input.getAttribute('value');
    expect(token, 'expected a CSRF token field on the page').toBeTruthy();
    return token;
  }

  const dataNode = page.locator('[data-csrf]').first();
  await expect(dataNode).toHaveCount(1);
  const token = await dataNode.getAttribute('data-csrf');
  expect(token, 'expected a data-csrf token on the page').toBeTruthy();
  return token;
}

module.exports = {
  checkDeveloperSecurityFlag,
  cleanupSecurityFixtures,
  countFixtureRecord,
  csrfTokenFromPage,
  lookupFixture,
  resetLoginAttempts,
  seedPasswordResetToken,
  setupSecurityFixtures,
  signIn,
  signInAs,
  signOut,
};
