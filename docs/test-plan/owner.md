# Owner test plan (`OWN-*`)

Global role: `users.role = 'owner'`.

Owner passes `portal_is_admin()` and `portal_require_admin()`, so **all applicable `ADM-*` cases apply**. This file covers owner-only deltas and trust-boundary checks.

`$isOwner = portal_is_owner()` is **true**.

---

### [OWN-01] Admin capability parity
- **Role(s):** Owner
- **Page(s)/entry point:** `public/admin.php` (all sections used in `ADM-01`–`ADM-13`, `ADM-15`–`ADM-17`)
- **Preconditions:** Owner account; sample users/courses
- **Steps:**
  1. [ ] Spot-check: create_user, save_enrollments, create_course, security review, integrity settings, school-wide event
- **Expected result:** Same privileged operations as admin succeed
- **Also verify (negative/permission case):** Teacher still blocked from admin.php
- **Existing automated coverage:** partial via admin Playwright using `sec_admin` (not owner); treat as manual for owner persona

### [OWN-02] delete_course (owner-only)
- **Role(s):** Owner
- **Page(s)/entry point:** `admin.php` action `delete_course` (`$isOwner` gate); UI delete control shown when `$isOwner`
- **Preconditions:** Course eligible for delete per app rules (follow on-screen constraints / empty course if required)
- **Steps:**
  1. [ ] Delete course as owner
  2. [ ] Confirm course gone from lists and direct URLs fail closed
- **Expected result:** Course deleted for owner; admin cannot do this (ADM-08)
- **Also verify (negative/permission case):** Forged admin POST does not delete
- **Existing automated coverage:** none (proposed `tests/security/owner-gates.spec.js`)

### [OWN-03] Developer security panel
- **Role(s):** Owner
- **Page(s)/entry point:** `admin.php` `$showDeveloperSecurity = portal_is_owner() && portal_show_developer_security()`
- **Preconditions:** `.env` / environment `PORTAL_SHOW_DEVELOPER_SECURITY=1`
- **Steps:**
  1. [ ] Open admin security / developer diagnostics as owner
  2. [ ] Repeat with env flag off
  3. [ ] Repeat as admin with flag on
- **Expected result:** Panel visible only when **both** owner and flag; otherwise hidden / “contact developer” style message
- **Also verify (negative/permission case):** Admin never sees full developer panel even with flag
- **Existing automated coverage:** none

### [OWN-04] Create / promote admins
- **Role(s):** Owner
- **Page(s)/entry point:** `create_user`, `change_role`, `update_user` with `$isOwner` role allow-list
- **Preconditions:** Owner session
- **Steps:**
  1. [ ] Create user with role `admin`
  2. [ ] Promote teacher → admin via `change_role`
  3. [ ] Confirm non-owner admin cannot do the same
- **Expected result:** Owner may create/promote admins; still cannot set a second `owner` if code disallows (`change_role` owner allow-list is `admin|teacher|student`)
- **Also verify (negative/permission case):** Attempting `owner` as newRole fails validation for owner actor too (per `admin.php` checks)
- **Existing automated coverage:** none

### [OWN-05] Manage / delete peer admins; owners protected
- **Role(s):** Owner
- **Page(s)/entry point:** `update_user`, `delete_user`, `change_role`, `security_account_action`
- **Preconditions:** Disposable admin; second owner if present; self
- **Steps:**
  1. [ ] Update/delete/demote disposable admin
  2. [ ] Attempt demote/delete another owner (if any)
  3. [ ] Confirm UI does not expose demote/delete against owner rows
  4. [ ] Confirm account-status actions cannot target owners (`portal_set_user_account_status`)
- **Expected result:** Peer admins manageable by owner; **owner accounts cannot be demoted/deleted/status-changed** by anyone (including other admins); UI should not offer those controls on owner rows
- **Also verify (negative/permission case):** Admin actor fails the same operations (ADM-03–05, ADM-11)
- **Existing automated coverage:** `security_account_status_auth_check.php` (status); delete/demote UI manual

### [OWN-06] Settings — owner email edit
- **Role(s):** Owner
- **Page(s)/entry point:** `settings.php` (`$isOwner`, `$canEditEmail = $isStudent || $isOwner`)
- **Preconditions:** Owner session
- **Steps:**
  1. [ ] Change email with current password confirmation
  2. [ ] `update_profile` / `change_password` / customization
- **Expected result:** Owner can edit email (unlike teacher/admin); personal customization works
- **Also verify (negative/permission case):** Settings page is personal — site integrity/trusted proxies remain on `admin.php`
- **Existing automated coverage:** none

### [OWN-07] Course staff assignment as owner
- **Role(s):** Owner
- **Page(s)/entry point:** `course.php` `assign_teacher`, `change_assignment_role`, `remove_teacher` (`portal_is_admin()` true for owner)
- **Preconditions:** Owner session; teacher users
- **Steps:**
  1. [ ] Assign teacher and supervisor roles on a course
- **Expected result:** Same admin staff-assignment powers
- **Also verify (negative/permission case):** Assigned teachers still cannot assign staff
- **Existing automated coverage:** none specific to owner

### [OWN-08] Trust boundary — owner session after admin attack attempts
- **Role(s):** Owner (+ Admin actor)
- **Page(s)/entry point:** Cross-check `admin.php` guards while owner account exists
- **Preconditions:** Owner + admin test accounts
- **Steps:**
  1. [ ] As admin, attempt `delete_user` / `change_role` / `security_account_action` against owner
  2. [ ] As owner, sign in and confirm account intact
- **Expected result:** Owner account unchanged; admin attempts fail closed
- **Also verify (negative/permission case):** Owner can still access developer panel when enabled
- **Existing automated coverage:** partial account-status CLI; full UI matrix new
