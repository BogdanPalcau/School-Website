# Admin test plan (`ADM-*`)

Global role: `users.role = 'admin'`.

Gate: `portal_require_admin()` on `public/admin.php`. Note `portal_is_admin()` is also true for owners — owner-only differences are called out in [owner.md](owner.md) and in negatives here.

`$isOwner = portal_is_owner()` is **false** for these cases.

---

### [ADM-01] Open admin console sections
- **Role(s):** Admin
- **Page(s)/entry point:** `public/admin.php` sections `dashboard`, `users`, `courses`, `enrollments`, `activities`, `integrity`, `security`
- **Preconditions:** Admin account
- **Steps:**
  1. [ ] Visit each section via admin nav
- **Expected result:** Sections load; security tools available to admin (except developer panel — see ADM-14)
- **Also verify (negative/permission case):** Teacher/student redirected by `portal_require_admin` with `unauthorised_admin_access` log
- **Existing automated coverage:** partial — non-admin blocked in `security-bulk-actions.spec.js`

### [ADM-02] Create user
- **Role(s):** Admin
- **Page(s)/entry point:** `admin.php` action `create_user`
- **Preconditions:** Admin session; unique username/email
- **Steps:**
  1. [ ] Create `student` user
  2. [ ] Create `teacher` user
  3. [ ] Attempt to create `admin` or `owner` (non-owner path)
- **Expected result:** Student/teacher created; admin cannot create `admin`/`owner` (`$isOwner` false limits roles to teacher/student)
- **Also verify (negative/permission case):** CSRF missing → rejected
- **Existing automated coverage:** partial — `security.spec.js`: "rejects forged admin POST requests without a CSRF token" (create_user)

### [ADM-03] Update user
- **Role(s):** Admin
- **Page(s)/entry point:** action `update_user`
- **Preconditions:** Target student/teacher; also prepare owner + peer admin targets
- **Steps:**
  1. [ ] Update name/email fields on a student
  2. [ ] Attempt update on an `owner` account
  3. [ ] Attempt update on another `admin` account
- **Expected result:** Student/teacher editable; owner not manageable; peer admin not manageable by non-owner (`$isOwner || !in_array(target role, admin/owner)`)
- **Also verify (negative/permission case):** UI hides manage controls for protected targets
- **Existing automated coverage:** none (UI matrix)

### [ADM-04] Change role (student ↔ teacher only)
- **Role(s):** Admin
- **Page(s)/entry point:** action `change_role`
- **Preconditions:** Student and teacher targets
- **Steps:**
  1. [ ] Change student → teacher
  2. [ ] Change teacher → student
  3. [ ] Attempt promote to `admin` or `owner`
  4. [ ] Attempt change_role on an owner account
- **Expected result:** Only `teacher`/`student` allowed for non-owner (`!$isOwner && !in_array(newRole, ['teacher','student'])` fails); owner target blocked
- **Also verify (negative/permission case):** Dropdown does not offer owner/admin for admin actor
- **Existing automated coverage:** none

### [ADM-05] Delete user
- **Role(s):** Admin
- **Page(s)/entry point:** action `delete_user`
- **Preconditions:** Disposable student/teacher; also owner + admin targets
- **Steps:**
  1. [ ] Delete disposable student
  2. [ ] Attempt delete owner
  3. [ ] Attempt delete peer admin
- **Expected result:** Student/teacher deletable; owner blocked; peer admin blocked unless `$isOwner`
- **Also verify (negative/permission case):** Cannot delete self if UI/server prevents it (confirm actual behaviour)
- **Existing automated coverage:** none for delete_user; related account-status in `security_account_status_auth_check.php`

### [ADM-06] Enrolments — save_enrollments
- **Role(s):** Admin
- **Page(s)/entry point:** `admin.php` section enrollments; action `save_enrollments`
- **Preconditions:** Course + students exist
- **Steps:**
  1. [ ] Enrol Student 1 on Course A
  2. [ ] Unenrol Student 1
  3. [ ] Re-enrol
- **Expected result:** `enrollments` rows match; student access follows `portal_can_access_course`
- **Also verify (negative/permission case):** Teacher cannot use admin enrolments UI
- **Existing automated coverage:** none (E2E); access after enrolment covered indirectly by security fixtures

### [ADM-07] Course lifecycle — create / update / archive / restore / duplicate
- **Role(s):** Admin
- **Page(s)/entry point:** actions `create_course`, `update_course`, `archive_course`, `restore_course`, `duplicate_course`
- **Preconditions:** Admin session
- **Steps:**
  1. [ ] Create course
  2. [ ] Update metadata
  3. [ ] Archive then restore
  4. [ ] Duplicate course
- **Expected result:** Course rows/status update; duplicate creates new course
- **Also verify (negative/permission case):** `delete_course` control absent / POST no-ops without `$isOwner` (see ADM-08)
- **Existing automated coverage:** none

### [ADM-08] Negative — delete_course blocked for admin
- **Role(s):** Admin
- **Page(s)/entry point:** action `delete_course` (`if ($action === 'delete_course' && $isOwner)`)
- **Preconditions:** Empty/deletable course candidate; admin session
- **Steps:**
  1. [ ] Confirm delete UI hidden for admin
  2. [ ] If forged POST attempted, confirm course remains
- **Expected result:** Admin cannot permanently delete courses; owner-only
- **Also verify (negative/permission case):** Archive still available as non-destructive alternative
- **Existing automated coverage:** none (proposed `owner-gates.spec.js`)

### [ADM-09] Assign teachers / supervisors to courses
- **Role(s):** Admin
- **Page(s)/entry point:** `course.php` (admin gate) actions `assign_teacher`, `change_assignment_role`, `remove_teacher`; also admin courses UI if present
- **Preconditions:** Teacher accounts; Course A
- **Steps:**
  1. [ ] Assign teacher with `assignment_role = teacher`
  2. [ ] `change_assignment_role` to `supervisor`
  3. [ ] Assign second teacher; `remove_teacher`
- **Expected result:** `course_teachers` updated; labels via `portal_course_assignment_role_label`; invalid roles coerced via `portal_valid_assignment_role`
- **Also verify (negative/permission case):** Course teacher/supervisor cannot perform these actions
- **Existing automated coverage:** partial — fixtures insert assignment; rolecheck script; no admin UI Playwright

### [ADM-10] Security panel — review events
- **Role(s):** Admin
- **Page(s)/entry point:** actions `mark_security_event_reviewed`, `mark_security_low_info_reviewed`, `bulk_security_action`
- **Preconditions:** Security events exist (or generate via failed login)
- **Steps:**
  1. [ ] Mark single event reviewed
  2. [ ] Use bulk mark reviewed with selection
  3. [ ] Confirm IP column / filters if present
- **Expected result:** Events marked reviewed; bulk bar works
- **Also verify (negative/permission case):** Bulk without CSRF rejected; teacher cannot open security activity
- **Existing automated coverage:** `security-bulk-actions.spec.js`; `security_detection_check.php`

### [ADM-11] Security account actions
- **Role(s):** Admin
- **Page(s)/entry point:** action `security_account_action` (`ban` / `mute` / `restrict` / `activate` / `delete`) via `portal_set_user_account_status`
- **Preconditions:** Disposable student; peer admin; owner account
- **Steps:**
  1. [ ] Restrict/mute/ban a student; reactivate
  2. [ ] Attempt ban/mute on owner
  3. [ ] Attempt ban/mute on peer admin
- **Expected result:** Student status changes; owner untouchable; peer admin status changes blocked for non-owner (`bootstrap.php` account-status guards)
- **Also verify (negative/permission case):** Banned student cannot use portal (`portal_require_login`)
- **Existing automated coverage:** `tests/security_account_status_auth_check.php`

### [ADM-12] Trusted proxies
- **Role(s):** Admin
- **Page(s)/entry point:** action `save_trusted_proxies`
- **Preconditions:** Admin security section
- **Steps:**
  1. [ ] Save a valid proxy list
  2. [ ] Attempt invalid input (confirm validation/error handling)
- **Expected result:** Setting persisted for request IP trust behaviour
- **Also verify (negative/permission case):** CSRF required
- **Existing automated coverage:** none (UI)

### [ADM-13] Integrity admin tools
- **Role(s):** Admin
- **Page(s)/entry point:** actions `lookup_submission_receipt`, `save_integrity_settings` (`integrity` section)
- **Preconditions:** Known submission receipt (`portal_integrity_receipt_number`); optional GPTZero keys
- **Steps:**
  1. [ ] Look up receipt number
  2. [ ] Save integrity settings (`external_ai_policy` ∈ `disabled|site_wide|per_module`)
- **Expected result:** Receipt resolves or rate-limits cleanly (`portal_receipt_lookup_*`); settings save
- **Also verify (negative/permission case):** Teacher cannot change site integrity settings
- **Existing automated coverage:** partial — receipt/upload in `submission_upload_security_check.php`

### [ADM-14] Negative — developer security panel hidden
- **Role(s):** Admin
- **Page(s)/entry point:** `admin.php` `$showDeveloperSecurity = portal_is_owner() && portal_show_developer_security()`
- **Preconditions:** Even if `PORTAL_SHOW_DEVELOPER_SECURITY=1`, actor is admin not owner
- **Steps:**
  1. [ ] Open security / developer diagnostics area
- **Expected result:** Developer diagnostics hidden / contact-developer message for non-owner; admin does not get owner developer tools
- **Also verify (negative/permission case):** Owner with env flag sees panel (OWN-03)
- **Existing automated coverage:** none

### [ADM-15] Site-wide announcements
- **Role(s):** Admin
- **Page(s)/entry point:** `public/communication.php` actions `post_site_announcement`, `delete_site_announcement`, `toggle_pin_site_announcement` (`$isAdmin`)
- **Preconditions:** Admin session
- **Steps:**
  1. [ ] Post site announcement; pin; delete
- **Expected result:** Site-wide bulletin visible to users; rich text sanitized
- **Also verify (negative/permission case):** Teacher cannot post site-wide (course announcements only via `course.php`)
- **Existing automated coverage:** partial rich-text CLI; no site-announcement Playwright

### [ADM-16] Courses list — full staff visibility
- **Role(s):** Admin
- **Page(s)/entry point:** `courses.php` (`$staffCourseView`); `grades.php` admin branch (`portal_is_admin()` → all active modules)
- **Preconditions:** Multiple courses, not all with admin “enrolment”
- **Steps:**
  1. [ ] Open courses and grades as admin
- **Expected result:** Broad visibility across modules (oversight), not enrolment-limited student view
- **Also verify (negative/permission case):** Still respects manage vs student submit distinctions inside a course
- **Existing automated coverage:** none

### [ADM-17] Events — school-wide
- **Role(s):** Admin
- **Page(s)/entry point:** `events.php` `create_event` school-wide scope
- **Preconditions:** Admin session
- **Steps:**
  1. [ ] Create school-wide event; confirm appears for students
- **Expected result:** School-wide event created and visible
- **Also verify (negative/permission case):** Teacher cannot create school-wide
- **Existing automated coverage:** `events.spec.js`: "admin can create a school-wide event…"

### [ADM-18] Settings as admin
- **Role(s):** Admin
- **Page(s)/entry point:** `settings.php` (`$canEditEmail` false)
- **Preconditions:** Admin session
- **Steps:**
  1. [ ] Confirm email field not editable; update name/password/notifications/customization
- **Expected result:** Personal prefs work; email locked like teacher; this is not where site integrity settings live
- **Also verify (negative/permission case):** No accidental owner-only developer toggles on settings page
- **Existing automated coverage:** none
