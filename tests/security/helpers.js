const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { expect } = require('@playwright/test');

const rootDir = path.resolve(__dirname, '..', '..');
const phpBinary = process.env.PHP_BINARY || 'C:\\xampp\\php\\php.exe';
const fixtureScript = path.join(rootDir, 'tests', 'fixtures', 'security-fixtures.php');

function runFixture(command, ...args) {
  return execFileSync(phpBinary, [fixtureScript, command, ...args], {
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

async function signIn(page, username, password) {
  await page.goto('/login.php');
  await page.getByLabel(/username or email/i).fill(username);
  await page.getByLabel(/password/i).fill(password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/dashboard\.php/);
}

async function signOut(page) {
  await page.goto('/logout.php');
  await expect(page).toHaveURL(/login\.php/);
}

async function csrfTokenFromPage(page) {
  const token = await page.locator('input[name="_token"]').first().getAttribute('value');
  expect(token, 'expected a CSRF token field on the page').toBeTruthy();
  return token;
}

module.exports = {
  cleanupSecurityFixtures,
  countFixtureRecord,
  csrfTokenFromPage,
  resetLoginAttempts,
  setupSecurityFixtures,
  signIn,
  signOut,
};
