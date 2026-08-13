# Role-based test plan (Student / Teacher / Supervisor / Admin / Owner)

Manual + automated coverage map for verifying that each LMS role can do their real job end-to-end — not just that pages load.

Grounded in the codebase as of this plan (`bootstrap.php` role helpers, `public/*.php` actions, `tests/*`).

---

## Documents

| File | Contents |
|------|----------|
| [student.md](student.md) | `STU-*` student checklist |
| [teacher.md](teacher.md) | `TCH-*` teacher + `SUP-*` course-supervisor checklist |
| [admin.md](admin.md) | `ADM-*` admin checklist |
| [owner.md](owner.md) | `OWN-*` owner checklist (admin + owner-only) |
| [cross-role.md](cross-role.md) | `CROSS-*` multi-actor scenarios |

---

## Role model (confirmed in code)

| Layer | Values | Storage |
|-------|--------|---------|
| Global role | `owner`, `admin`, `teacher`, `student` | `users.role` CHECK in `bootstrap.php` |
| Course assignment | `teacher`, `supervisor` | `course_teachers.assignment_role` |

Notes that prevent bad fixtures:

- **Supervisor is not a global role.** Legacy `users.role = 'supervisor'` was migrated to global `teacher` + course `assignment_role = 'supervisor'`.
- `portal_is_admin()` is true for **both** `admin` and `owner`. There is no `portal_is_admin_or_owner()`.
- `portal_can_manage_course($courseId)` is true for admin/owner **or** any `course_teachers` row — supervisor and teacher assignment have the **same manage power**.
- Real supervisor vs teacher difference in production: **label / assignment_role value** (`portal_course_assignment_role_label`, staff UI on `course.php`). Permission parity is asserted in `scripts/role_access_check.php`.
- Staff assignment actions (`assign_teacher`, `change_assignment_role`, `remove_teacher`) are **admin/owner only** (`portal_is_admin()` gate in `course.php`).
- Owner-only: `delete_course`; demote/delete peer `admin`; create/promote to `admin`; developer security panel when `PORTAL_SHOW_DEVELOPER_SECURITY=1` (`portal_show_developer_security()` + `portal_is_owner()`).

---

## Test account matrix

Use dedicated accounts (do not reuse production users). Password conventions below match existing fixtures where noted.

| Persona | Global `users.role` | Course assignment | Purpose |
|---------|---------------------|-------------------|---------|
| Owner | `owner` | n/a (global manage) | `OWN-*`, owner-only admin gates |
| Admin | `admin` | n/a | `ADM-*`, negative owner gates |
| Teacher A | `teacher` | `teacher` on Course A | `TCH-*` |
| Teacher B (Supervisor) | `teacher` | `supervisor` on Course A | `SUP-*` (same manage, label checks) |
| Teacher C | `teacher` | none / or Course B only | unassigned negative cases |
| Student 1 | `student` | enrolled Course A | primary student flows |
| Student 2 | `student` | enrolled Course A | cross-student grade/submission IDOR |
| Student outsider | `student` | not enrolled Course A | enrollment IDOR |

### Existing automated fixtures

| Source | Accounts | Password | Notes |
|--------|----------|----------|-------|
| `tests/fixtures/security-fixtures.php` | `sec_admin`, `sec_teacher` (assignment `teacher`), `sec_student`, `sec_outsider` | `SecurityPass123!` | Courses `security-open-course`, `security-blocked-course`. **No owner. No supervisor assignment.** |
| `tests/fixtures/activity-fixtures.php` | extends security fixtures + activities/attempts | same | Published assessment + draft activity |
| `tests/activity_system_check.php` | temp `{slug}-admin\|teacher\|student` | `ActivityTestPass123!` | Ephemeral CLI users |
| `tests/events_system_check.php` | temp `{slug}-admin\|teacher\|student\|outsider` | `EventsTestPass123!` | Ephemeral CLI users |
| `scripts/role_access_check.php` | expects penguin demo accounts (e.g. `accteacher`, `acctsupervisor`, student) | demo seed | Useful smoke for supervisor parity after `scripts/seed_penguin_demo.php` |

### Fixture gaps (manual QA / proposed automation)

New accounts needed for full manual matrix (not created by `security-fixtures.php` today):

1. **Owner** account (e.g. `qa_owner`)
2. **Second student** on the open course (cross-student negatives)
3. **Teacher with `assignment_role = 'supervisor'`** on a managed course
4. Optional: second course + unassigned teacher for manage-boundary negatives (partially covered by `security-blocked-course` for students; teacher unassigned path exists in Playwright)

Proposed stub (not implemented in this pass): `tests/fixtures/role-qa-fixtures.php` + `tests/security/role-duties.spec.js` — see [Proposed automation stubs](#proposed-automation-stubs).

---

## How to run existing automation

From repo root (requires XAMPP PHP path as in `package.json`):

```bash
npm run test:activity
# → C:\xampp\php\php.exe tests/activity_system_check.php

npm run test:events
# → C:\xampp\php\php.exe tests/events_system_check.php

npm run playwright:install   # once
npm run test:security        # Playwright; starts PHP server on 127.0.0.1:8011 by default

npm run test:all             # activity + events + security
```

Useful extra CLI checks (not in `test:all`):

```text
C:\xampp\php\php.exe tests/security_account_status_auth_check.php
C:\xampp\php\php.exe tests/security_material_lock_check.php
C:\xampp\php\php.exe tests/security_password_reset_base_url_check.php
C:\xampp\php\php.exe tests/security_rich_text_check.php
C:\xampp\php\php.exe tests/submission_upload_security_check.php
C:\xampp\php\php.exe scripts/role_access_check.php
```

Playwright helpers: `tests/security/helpers.js` — `signIn`, `signOut`, `setupSecurityFixtures`, `csrfTokenFromPage`.

---

## Gap analysis — existing vs new

### Already covered (reference these; do not recreate)

| Area | Existing coverage |
|------|-------------------|
| Unauth redirect / enrollment IDOR / teacher manage boundary | `tests/security/security.spec.js`: unauthenticated redirect; enrolled vs blocked course; assigned teacher manage vs unassigned |
| Login lockout + clear after success | `security.spec.js`: locks out repeated failed logins; clears failed attempts after success |
| CSRF (admin create_user, course POST, builder, events, bulk security) | `security.spec.js`, `activity.spec.js` builder CSRF, `events.spec.js` create_event CSRF, `security-bulk-actions.spec.js` |
| Cross-course folder/item/group IDOR; submission download IDOR | `security.spec.js` |
| Upload type mismatch vs legitimate teacher upload | `security.spec.js` |
| Student blocked from builder; draft hidden; answer-key leak | `activity.spec.js` + `activity_system_check.php` Answer leakage |
| Activity scoring, attempts, integrity events (no clipboard text), XP idempotency, media path/SVG/empty, CSV, versioning, flashcards helpers | `tests/activity_system_check.php` sections |
| Activity results / grades UI signals | `activity.spec.js` integrity failures UI; released grades; unread badge; XP lobby |
| Events create/visibility/CSRF | `events.spec.js` + `events_system_check.php` |
| Admin security bulk UI; non-admin blocked | `security-bulk-actions.spec.js` |
| Owner-only account status vs peer admin | `tests/security_account_status_auth_check.php` |
| Material locks | `tests/security_material_lock_check.php` |
| Password-reset base URL | `tests/security_password_reset_base_url_check.php` |
| Rich-text / review XSS | `security_rich_text_check.php`, `security_review_xss_check.php` |
| Submission upload allowlist / receipts | `submission_upload_security_check.php` |
| Supervisor vs teacher manage parity (CLI) | `scripts/role_access_check.php` |

### Genuinely new (manual plan focus)

| Gap | Why new |
|-----|---------|
| Full student duty path (dashboard → materials → discuss → grades → settings → notifications) | Playwright covers access/security slices, not job completeness |
| File assignment submit → mark → feedback UX (on-time); deadline **block** after due | Upload security tested; marking/feedback E2E not |
| Activity modes Practice / Quiz / Challenge / Survey E2E in UI | CLI scoring exists; UI mode matrix mostly Assessment-focused |
| Teacher content authoring (folders, locks, links, schedule, groups, announcements own-delete) | Partial upload + IDOR only |
| Activity Builder full question-type authoring + publish freeze UX | CLI + builder open/CSRF; not full type matrix in browser |
| Manual mark long-response + delayed Assessment release UX | CLI marking assist; limited UI |
| Admin user/course/enrolment CRUD matrix + role promotion limits | CSRF create_user only |
| Owner `delete_course` + developer panel visibility | Account-status PHP check only |
| Settings `$canEditEmail` student/owner vs teacher/admin | none |
| Forgot/reset password UX (neutral success copy) | base URL CLI only |
| Cross-role enrolment → content visibility sequence | none as E2E |
| CSRF across **each role’s typical sensitive action set** | samples exist; not per-role matrix |
| Supervisor **label** + equal manage (manual UI) | CLI in `role_access_check.php` |

---

## Proposed automation stubs (not implemented)

List only — implement when requested:

1. `tests/fixtures/role-qa-fixtures.php` — owner, supervisor assignment, second student
2. `tests/security/role-duties.spec.js` — thin E2E happy paths using helpers (`signIn`, CSRF)
3. `tests/security/owner-gates.spec.js` — admin blocked from `delete_course` / demote owner; owner can delete empty course
4. `tests/security/settings-email.spec.js` — `$canEditEmail` for student/owner vs teacher/admin

Pattern to copy: `tests/security/helpers.js` + fixture JSON output like `security-fixtures.php`.

---

## Manual QA workflow

1. Seed or create the [test account matrix](#test-account-matrix).
2. Walk role files top-to-bottom; tick `- [ ]` checkboxes.
3. Run `CROSS-*` after individual roles pass.
4. Re-run `npm run test:all` after any app change that touches auth, activities, events, or admin.
5. For supervisor, prefer a **teacher** account with `assignment_role = 'supervisor'` — never invent a fifth global role.
