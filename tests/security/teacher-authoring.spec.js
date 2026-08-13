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

test('teacher can author folders, schedule, own announcements, and groups', async ({ page }) => {
  const courseUrl = `/course.php?course=${fixtures.courses.openSlug}`;
  const folderTitle = 'Authoring Locked Folder';
  const ownAnn = 'Teacher Own Announcement';
  const otherAnn = 'Other Staff Announcement';
  const groupTitle = 'Authoring Group A';
  const groupBTitle = 'Authoring Group B';

  // Supervisor posts an announcement that the plain teacher must not delete.
  await signInAs(page, fixtures, 'supervisorTeacher');
  await page.goto(`${courseUrl}&section=announcements`);
  let token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'post_announcement',
      title: otherAnn,
      body: '<p>From supervisor</p>',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('announcement', `${fixtures.courses.openSlug}|${otherAnn}`)).toBe(1);
  await signOut(page);

  await signInAs(page, fixtures, 'teacher');
  await page.goto(`${courseUrl}&section=content`);
  token = await csrfTokenFromPage(page);

  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_folder',
      title: folderTitle,
      description: 'Folder for lock tests',
      locked: '1',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('folder', folderTitle)).toBe(1);
  const folderId = lookupFixture('folder-id', `${fixtures.courses.openSlug}|${folderTitle}`).id;
  expect(folderId).toBeTruthy();
  expect(countFixtureRecord('folder-locked', String(folderId))).toBe(1);

  await page.goto(`${courseUrl}&section=content`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'toggle_folder_lock',
      folder_id: String(folderId),
    },
  });
  expect(countFixtureRecord('folder-locked', String(folderId))).toBe(0);

  await page.goto(`${courseUrl}&section=calendar`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_schedule_slot',
      day_of_week: 'Wednesday',
      start_time: '11:00',
      end_time: '12:00',
      room: 'Lab 3',
      notes: 'Authoring schedule',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('schedule', `${fixtures.courses.openSlug}|Wednesday|11:00`)).toBe(1);

  await page.goto(`${courseUrl}&section=announcements`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'post_announcement',
      title: ownAnn,
      body: '<p>From assigned teacher</p>',
    },
    maxRedirects: 0,
  });
  const ownAnnId = lookupFixture('announcement-id', `${fixtures.courses.openSlug}|${ownAnn}`).id;
  const otherAnnId = lookupFixture('announcement-id', `${fixtures.courses.openSlug}|${otherAnn}`).id;
  expect(ownAnnId).toBeTruthy();
  expect(otherAnnId).toBeTruthy();

  await page.goto(`${courseUrl}&section=announcements`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'delete_announcement',
      announcement_id: String(otherAnnId),
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('announcement', `${fixtures.courses.openSlug}|${otherAnn}`)).toBe(1);

  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'delete_announcement',
      announcement_id: String(ownAnnId),
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('announcement', `${fixtures.courses.openSlug}|${ownAnn}`)).toBe(0);

  await page.goto(`${courseUrl}&section=groups`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_group',
      title: groupTitle,
      description: 'Cap 2',
      max_members: '2',
    },
    maxRedirects: 0,
  });
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'create_group',
      title: groupBTitle,
      description: 'Second group',
      max_members: '0',
    },
    maxRedirects: 0,
  });
  expect(countFixtureRecord('group', `${fixtures.courses.openSlug}|${groupTitle}`)).toBe(1);
  expect(countFixtureRecord('group', `${fixtures.courses.openSlug}|${groupBTitle}`)).toBe(1);
  await signOut(page);

  // Student self-serve join / move (leave then join) — no staff assign API.
  const groupAId = lookupFixture('group-id', `${fixtures.courses.openSlug}|${groupTitle}`).id;
  const groupBId = lookupFixture('group-id', `${fixtures.courses.openSlug}|${groupBTitle}`).id;

  await signInAs(page, fixtures, 'studentTwo');
  await page.goto(`${courseUrl}&section=groups`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'join_group',
      group_id: String(groupAId),
    },
    maxRedirects: 0,
  });
  await page.goto(`${courseUrl}&section=groups`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'leave_group',
      group_id: String(groupAId),
    },
    maxRedirects: 0,
  });
  await page.goto(`${courseUrl}&section=groups`);
  token = await csrfTokenFromPage(page);
  await page.context().request.post(courseUrl, {
    form: {
      _token: token,
      action: 'join_group',
      group_id: String(groupBId),
    },
    maxRedirects: 0,
  });
  await expect(page.getByText(groupBTitle)).toBeVisible();
});
