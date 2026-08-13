# Cross-role scenarios (`CROSS-*`)

Each case needs two or more personas in sequence. Prefer the [test account matrix](README.md#test-account-matrix).

---

### [CROSS-01] Activity publish → attempt → mark → release
- **Role(s):** Teacher (or Supervisor) → Student → Teacher
- **Page(s)/entry point:** `activity-builder.php` `publish`; `activity.php` `start`/`submit`; `activity-results.php` `mark_answer` / `complete_marking` / `release_results`; student `result`
- **Preconditions:** Teacher manages course; student enrolled; Assessment with long_response + delayed release
- **Steps:**
  1. [ ] Teacher publishes activity version
  2. [ ] Student starts, autosaves, submits (confirm no answer-key leak mid-attempt)
  3. [ ] Teacher marks long response; completes marking; releases results
  4. [ ] Student opens result / grades; XP unlocks after release if configured
- **Expected result:** End-to-end Assessment flow respects version binding, attempt limits, and delayed release
- **Also verify (negative/permission case):** Before release, student cannot see full scored result; other course’s students cannot open activity
- **Existing automated coverage:** partial pieces — `activity_system_check.php` + `activity.spec.js` (not full multi-actor UI chain)

### [CROSS-02] File assignment → submit → grade → feedback (integrity teacher-only)
- **Role(s):** Teacher → Student → Teacher → Student
- **Page(s)/entry point:** `course.php` `create_item` (submission); `submit_work`; `mark_submission`; `rerun_integrity`; student grades/course view
- **Preconditions:** Future deadline; Student 1 enrolled; Student 2 enrolled for isolation check
- **Steps:**
  1. [ ] Teacher creates submission slot with deadline
  2. [ ] Student 1 submits allowed file on time
  3. [ ] Teacher reviews integrity signals; grades + feedback + optional annotation
  4. [ ] Student 1 sees score/feedback; Student 2 does not see Student 1’s submission/grade
- **Expected result:** Happy path marking works; integrity detail is teacher-facing
- **Also verify (negative/permission case):** After deadline, `submit_work` blocked (no late accept — extend deadline instead); outsider download IDOR blocked
- **Existing automated coverage:** download IDOR + upload mismatch automated; marking E2E new

### [CROSS-03] Admin provisions course → teacher content → student visibility
- **Role(s):** Admin → Teacher → Student
- **Page(s)/entry point:** `admin.php` `create_course`, `save_enrollments`; `course.php` `assign_teacher`; teacher `create_folder`/`create_item`; student `course.php`
- **Preconditions:** Fresh course; teacher + student accounts
- **Steps:**
  1. [ ] Admin creates course; assigns teacher; enrols student
  2. [ ] Before enrolment (or before content), confirm student cannot see content
  3. [ ] Teacher adds folder + document
  4. [ ] Student opens course and views material
- **Expected result:** Access appears only after enrolment + content; teacher manage works after assignment
- **Also verify (negative/permission case):** Unassigned teacher still blocked; unenrolled outsider blocked
- **Existing automated coverage:** access boundaries in `security.spec.js`; full provisioning chain new

### [CROSS-04] Admin promotes student → teacher; access expands
- **Role(s):** Admin → (former) Student
- **Page(s)/entry point:** `admin.php` `change_role`; then `activity-builder.php` / manage course after `assign_teacher`
- **Preconditions:** Student account; admin; course ready for assignment
- **Steps:**
  1. [ ] As student, confirm builder/admin denied
  2. [ ] Admin `change_role` to `teacher`
  3. [ ] User re-login / session refresh (`portal_current_user_role` reloads from DB)
  4. [ ] Admin assigns them to a course; user opens builder
- **Expected result:** Capabilities expand to teacher; course manage only after assignment row exists
- **Also verify (negative/permission case):** Role change alone without `course_teachers` row does not grant `portal_can_manage_course`
- **Existing automated coverage:** none

### [CROSS-05] Login throttle consistent across roles
- **Role(s):** Student, Teacher, Admin, Owner (repeat)
- **Page(s)/entry point:** `public/login.php` login throttling helpers in `bootstrap.php`
- **Preconditions:** Clean `login_attempts` for test client IP (or accept shared IP lockout in local dev)
- **Steps:**
  1. [ ] For each role username, submit repeated bad passwords until lockout
  2. [ ] Confirm lockout message/behaviour matches
  3. [ ] After lockout window / clear, successful login clears attempts
- **Expected result:** Throttle is client/IP oriented and consistent regardless of target role
- **Also verify (negative/permission case):** Lockout not bypassed by switching usernames from same client if that is how throttle is implemented (confirm actual `login_attempts` behaviour)
- **Existing automated coverage:** `security.spec.js`: lockout + clear after success (single persona path)

### [CROSS-06] CSRF rejected across typical sensitive actions per role
- **Role(s):** Student, Teacher, Admin, Owner
- **Page(s)/entry point:** Forged POST missing/invalid `_token` (`portal_verify_csrf`)
- **Preconditions:** Valid session cookies for each role; `csrfTokenFromPage` pattern known from helpers
- **Steps:**
  1. [ ] Student: forge `submit_work` or `post_reply` without token
  2. [ ] Teacher: forge `mark_submission` or builder `save_settings` without token
  3. [ ] Admin: forge `create_user` / `bulk_security_action` without token
  4. [ ] Owner: forge `delete_course` without token
- **Expected result:** Each rejected (403/redirect/flash); no state change
- **Also verify (negative/permission case):** Same action with valid CSRF succeeds (sanity)
- **Existing automated coverage:** samples — admin create_user, course POST, builder, create_event, bulk_security_action; **per-role matrix incomplete** (student submit CSRF new)

### [CROSS-07] Supervisor participates in marking chain like teacher
- **Role(s):** Admin → Supervisor assignment → Student → Supervisor
- **Page(s)/entry point:** `change_assignment_role` / `assign_teacher` with `supervisor`; then CROSS-01 or CROSS-02 marking steps as supervisor
- **Preconditions:** Teacher account with supervisor assignment on course
- **Steps:**
  1. [ ] Admin sets assignment_role supervisor
  2. [ ] Student submits activity or file work
  3. [ ] Supervisor marks/releases / marks submission
- **Expected result:** Supervisor can complete the same review duties as teacher assignment (manage parity)
- **Also verify (negative/permission case):** Supervisor still cannot assign other staff or open admin security settings
- **Existing automated coverage:** manage parity CLI in `scripts/role_access_check.php`; full marking chain new

### [CROSS-08] Site announcement vs course announcement audiences
- **Role(s):** Admin → Student / Teacher
- **Page(s)/entry point:** `communication.php` `post_site_announcement`; `course.php` `post_announcement`
- **Preconditions:** Admin + course teacher + enrolled student
- **Steps:**
  1. [ ] Admin posts site announcement
  2. [ ] Teacher posts course announcement
  3. [ ] Student sees both appropriate surfaces; teacher cannot post site-wide
- **Expected result:** Correct audience separation; sanitised HTML
- **Also verify (negative/permission case):** Student cannot post either site or course announcements
- **Existing automated coverage:** rich-text CLI only
