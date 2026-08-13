# Student test plan (`STU-*`)

Global role: `users.role = 'student'`. Access via enrolment (`portal_can_access_course`) — not `portal_can_manage_course`.

Use **Student 1** for happy paths and **Student 2 / outsider** for negatives.

---

### [STU-01] Login and session
- **Role(s):** Student
- **Page(s)/entry point:** `public/login.php` → `portal_require_login` destinations
- **Preconditions:** Valid student account; not banned (`account_status`)
- **Steps:**
  1. [ ] Open `login.php` while logged out
  2. [ ] Sign in with correct username/password
  3. [ ] Confirm redirect to intended page / `dashboard.php`
  4. [ ] Open `logout.php` and confirm session cleared
- **Expected result:** Authenticated session; `portal_is_logged_in()` true; student lands on student dashboard widgets (not staff queues)
- **Also verify (negative/permission case):** Banned student is logged out with flash (`portal_require_login` account_status refresh)
- **Existing automated coverage:** partial — `tests/security/security.spec.js`: "redirects unauthenticated users away from protected pages"; "clears failed login attempts after a successful sign-in"

### [STU-02] Forgot password — neutral success (no account enumeration)
- **Role(s):** Student (unauthenticated)
- **Page(s)/entry point:** `public/forgot-password.php` → `portal_password_reset_request`
- **Preconditions:** SMTP may be unset (`.env` without working `SMTP_*`)
- **Steps:**
  1. [ ] Submit a known student email
  2. [ ] Submit a nonsense email that is not in `users`
  3. [ ] Compare on-screen messages
- **Expected result:** Same neutral copy both times: “If that email is on file, we sent a reset link…” (`forgot-password.php`); does not reveal whether the account exists
- **Also verify (negative/permission case):** Logged-in user hitting forgot-password is redirected away
- **Existing automated coverage:** partial — `tests/security_password_reset_base_url_check.php` (link base URL only, not UX copy)

### [STU-03] Reset password consume
- **Role(s):** Student
- **Page(s)/entry point:** `public/reset-password.php` → `portal_password_reset_consume`
- **Preconditions:** Valid reset token (from DB / mail when SMTP configured)
- **Steps:**
  1. [ ] Open reset link with token
  2. [ ] Submit matching `new_password` / `confirm_password` that pass `portal_password_validate`
  3. [ ] Sign in with the new password
- **Expected result:** Password updated; old password fails; token not reusable
- **Also verify (negative/permission case):** Invalid/expired token rejected; weak password rejected by `portal_password_validate`
- **Existing automated coverage:** none (UI); base URL only in `security_password_reset_base_url_check.php`

### [STU-04] Dashboard — enrolled courses only
- **Role(s):** Student
- **Page(s)/entry point:** `public/dashboard.php` (`!$isStaff && !$isAdmin` branches)
- **Preconditions:** Enrolled in Course A only; Course B exists
- **Steps:**
  1. [ ] Open dashboard
  2. [ ] Review course list / deadlines / student priorities
- **Expected result:** Only enrolled courses and student-facing priorities; no staff mark queues / admin oversight blocks (`$isStaff` / `$isAdmin` sections hidden)
- **Also verify (negative/permission case):** Course B never appears; no activity-builder shortcuts
- **Existing automated coverage:** partial — `tests/security/events.spec.js`: "dashboard shows upcoming events in the side column"

### [STU-05] Courses list — student view
- **Role(s):** Student
- **Page(s)/entry point:** `public/courses.php` (`$staffCourseView` false)
- **Preconditions:** Enrolled Course A; not enrolled Course B
- **Steps:**
  1. [ ] Open `courses.php`
  2. [ ] Optionally `toggle_favorite_course` on an enrolled course
- **Expected result:** Enrolment-scoped list (not full site catalog like staff `$staffCourseView`)
- **Also verify (negative/permission case):** Cannot open Course B via crafted `course=` / id URL (`portal_can_access_course`)
- **Existing automated coverage:** `tests/security/security.spec.js`: "lets enrolled students open their course but blocks direct URL access to other courses"

### [STU-06] Open course content and locked materials
- **Role(s):** Student
- **Page(s)/entry point:** `public/course.php` content tab / folders
- **Preconditions:** Enrolled; folder/item with `locked` and unlocked materials present
- **Steps:**
  1. [ ] Open enrolled course
  2. [ ] Expand unlocked folder; open document / link / video items
  3. [ ] Attempt locked folder/item
- **Expected result:** Unlocked content reachable; locks enforced for non-managers (`portal_can_manage_course` false)
- **Also verify (negative/permission case):** Locked item not openable via direct `view.php` / `download.php` / `lesson-viewer.php` URLs
- **Existing automated coverage:** `tests/security_material_lock_check.php`

### [STU-07] In-browser preview / view / lesson viewer
- **Role(s):** Student
- **Page(s)/entry point:** `public/view.php`, `public/preview.php`, `public/lesson-viewer.php`
- **Preconditions:** Enrolled; document item with preview support; `allow_download` off where testing view-only
- **Steps:**
  1. [ ] Open material via course → view / lesson-viewer
  2. [ ] Confirm content renders in browser
  3. [ ] If `allow_download` is 0, confirm download control is blocked
- **Expected result:** In-browser access works for enrolled student; download respects `allow_download` / manage gates in `download.php`
- **Also verify (negative/permission case):** Outsider cannot open same item URLs
- **Existing automated coverage:** partial — material lock CLI; no full preview E2E

### [STU-08] Download allowed materials
- **Role(s):** Student
- **Page(s)/entry point:** `public/download.php`
- **Preconditions:** Item with `allow_download = 1`; student enrolled; unlocked
- **Steps:**
  1. [ ] Download via course UI / `download.php` link
- **Expected result:** File downloads for enrolled student
- **Also verify (negative/permission case):** Cannot download another student’s submission (`download.php` submission owner/manage gate)
- **Existing automated coverage:** `tests/security/security.spec.js`: "blocks other users submission download IDOR"

### [STU-09] Submit assignment before deadline
- **Role(s):** Student
- **Page(s)/entry point:** `public/course.php` action `submit_work` → `portal_validate_submission_upload` (`submission_security.php`)
- **Preconditions:** Student role; enrolment; submission slot with future `submission_deadline`; attempts remaining
- **Steps:**
  1. [ ] Open submission item
  2. [ ] Upload allowed file type before deadline
  3. [ ] Confirm status / receipt UI
- **Expected result:** Submission stored; status visible to student; integrity pipeline may run (`integrity.php`) without exposing teacher-only detail cards
- **Also verify (negative/permission case):** Non-student / locked item / exhausted `submission_max_attempts` blocked
- **Existing automated coverage:** partial — `submission_upload_security_check.php`; Playwright upload type mismatch on teacher path

### [STU-10] Submit assignment after deadline (blocked)
- **Role(s):** Student
- **Page(s)/entry point:** `public/course.php` action `submit_work` (deadline check ~`submission_deadline`)
- **Preconditions:** Deadline in the past
- **Steps:**
  1. [ ] Attempt `submit_work` after deadline
- **Expected result:** Rejected with flash/message that deadline has passed (code does **not** accept late uploads; extension requires staff changing deadline)
- **Also verify (negative/permission case):** Teacher can still `mark_submission` / manage existing rows
- **Existing automated coverage:** none (UI/E2E)

### [STU-11] Course discussions — post and safe render
- **Role(s):** Student
- **Page(s)/entry point:** `public/course.php` actions `post_reply`, `delete_reply`; topics via staff `create_topic`
- **Preconditions:** Discussion topic exists; student enrolled
- **Steps:**
  1. [ ] Post reply containing plain text
  2. [ ] Post reply containing `<script>alert(1)</script>` / `javascript:` link
  3. [ ] Delete own reply if UI allows (`delete_reply` ownership rules)
- **Expected result:** Posts appear; HTML/script sanitized (rich-text helpers in `bootstrap.php`); no script execution
- **Also verify (negative/permission case):** Cannot delete another user’s reply unless permitted by code path
- **Existing automated coverage:** partial — `tests/security_rich_text_check.php`

### [STU-12] Announcements / notices
- **Role(s):** Student
- **Page(s)/entry point:** `public/course.php` announcements; action `mark_announcements_read`
- **Preconditions:** Staff posted announcement via `post_announcement`
- **Steps:**
  1. [ ] Open announcements section
  2. [ ] Mark read
- **Expected result:** Announcement visible; read state updates; sanitized body
- **Also verify (negative/permission case):** No `post_announcement` / `delete_announcement` controls for student
- **Existing automated coverage:** none (UI); smoke in `scripts/role_access_check.php` mark-read

### [STU-13] Activity — Practice mode
- **Role(s):** Student
- **Page(s)/entry point:** `public/activity.php` actions `start`, `resume`, `save_answer`, `submit`, `result`
- **Preconditions:** Published Practice activity in enrolled course folder; unlocked
- **Steps:**
  1. [ ] Start / resume attempt
  2. [ ] Autosave answers (`save_answer`)
  3. [ ] Submit; review feedback if configured
- **Expected result:** Attempt completes; practice integrity events ignored (see CLI); repeatable if limits allow
- **Also verify (negative/permission case):** Draft / unpublished activity invisible (`activity.php` manage gate)
- **Existing automated coverage:** partial — `activity_system_check.php` Attempts/Practice save; `activity.spec.js` draft hidden

### [STU-14] Activity — Quiz / Challenge modes
- **Role(s):** Student
- **Page(s)/entry point:** `public/activity.php`
- **Preconditions:** Published Quiz and Challenge activities
- **Steps:**
  1. [ ] Complete one Quiz attempt end-to-end
  2. [ ] Complete one Challenge attempt end-to-end
- **Expected result:** Scoring/XP behaviour matches mode settings; timer expiry auto-submits if configured (`sync_timer` / expiry path)
- **Also verify (negative/permission case):** Cannot open another course’s activity by id
- **Existing automated coverage:** partial — scoring/timer in `activity_system_check.php`; limited UI mode matrix

### [STU-15] Activity — Assessment mode (no mid-attempt key leak; limits; delayed release)
- **Role(s):** Student
- **Page(s)/entry point:** `public/activity.php` (`start`/`save_answer`/`submit`/`result`/`leave_assessment`/`integrity_event`)
- **Preconditions:** Published Assessment with `max_attempts`, integrity enabled, delayed release
- **Steps:**
  1. [ ] Start attempt; inspect network/player payload for answer keys
  2. [ ] Submit once; attempt second start if `max_attempts = 1`
  3. [ ] Open `result` before teacher release
  4. [ ] After staff `release_results`, open result again
- **Expected result:** No `is_correct` / `teacher_notes` / explanations / accepted answers mid-attempt; second start blocked; results withheld until release; XP pending review until release
- **Also verify (negative/permission case):** Cannot open `activity-results.php` / `activity-builder.php`
- **Existing automated coverage:** `activity_system_check.php` Answer leakage / Attempts / Gamification; `activity.spec.js`: "student assessment player payload does not leak answer keys while in progress"

### [STU-16] Activity — Survey mode
- **Role(s):** Student
- **Page(s)/entry point:** `public/activity.php`
- **Preconditions:** Published Survey activity
- **Steps:**
  1. [ ] Start, answer rating/scale or choice items, submit
- **Expected result:** Submission accepted; typically unscored per mode configuration
- **Also verify (negative/permission case):** No teacher analytics UI for student
- **Existing automated coverage:** none (dedicated Survey UI)

### [STU-17] Activity integrity signals — student-facing neutrality
- **Role(s):** Student
- **Page(s)/entry point:** `public/activity.php` action `integrity_event`
- **Preconditions:** Assessment with integrity enabled
- **Steps:**
  1. [ ] Trigger focus/visibility/paste signals during attempt
  2. [ ] Complete attempt
- **Expected result:** Events recorded without storing clipboard text; student UI stays neutral (no guilt labels)
- **Also verify (negative/permission case):** Student does not see teacher integrity timeline / staff labels
- **Existing automated coverage:** `activity_system_check.php` Integrity section; `activity.spec.js` teacher timeline (not student)

### [STU-18] XP / badges — no double-claim
- **Role(s):** Student
- **Page(s)/entry point:** Activity submit / result; gamification helpers in `activity.php`
- **Preconditions:** Activity with XP/badge rewards
- **Steps:**
  1. [ ] Complete and claim rewards once
  2. [ ] Refresh / resubmit / replay claim path
- **Expected result:** Unique reward keys prevent double XP/badge (`award_xp` idempotent)
- **Also verify (negative/permission case):** Client-forged claim without server key fails
- **Existing automated coverage:** `activity_system_check.php` Gamification; `activity.spec.js`: "student lobby shows the configured XP reward and working leaderboard"

### [STU-19] Flashcards — course + hub
- **Role(s):** Student
- **Page(s)/entry point:** Course activity item; `public/flashcards.php` (`portal_flashcard_decks_for_user`)
- **Preconditions:** Published flashcard deck on enrolled course
- **Steps:**
  1. [ ] Open deck from course content
  2. [ ] Open same deck from `flashcards.php`
  3. [ ] Flip cards; mark Know / Still learning
- **Expected result:** Deck playable from both entry points; progress updates
- **Also verify (negative/permission case):** No Edit link unless `$deck['can_manage']`; outsider sees no deck
- **Existing automated coverage:** partial — `activity_system_check.php` Flashcards section (helpers)

### [STU-20] Grades — own courses only
- **Role(s):** Student
- **Page(s)/entry point:** `public/grades.php` (student branch; `!$isStaff`)
- **Preconditions:** Marked submission and/or released activity grade for Student 1; Student 2 has different scores
- **Steps:**
  1. [ ] Open `grades.php` as Student 1
  2. [ ] Confirm only own marks / enrolled courses
- **Expected result:** Own grades and feedback only; no staff “all modules” view
- **Also verify (negative/permission case):** Cannot view Student 2’s grades by URL manipulation
- **Existing automated coverage:** partial — staff grades in `activity.spec.js`; not cross-student student view

### [STU-21] Timetable read-only
- **Role(s):** Student
- **Page(s)/entry point:** `public/timetable.php`
- **Preconditions:** Schedule slots on enrolled course (`create_schedule_slot` by staff)
- **Steps:**
  1. [ ] Open timetable
- **Expected result:** Read-only schedule for enrolments; no create/update/delete slot forms
- **Also verify (negative/permission case):** No POST schedule actions available to student
- **Existing automated coverage:** none

### [STU-22] Notifications — receive and mark read
- **Role(s):** Student
- **Page(s)/entry point:** `public/notifications.php`, `public/communication.php` actions `mark_notification_read`, `mark_all_notifications_read`
- **Preconditions:** Notification exists (grade / announcement / event with prefs enabled)
- **Steps:**
  1. [ ] Open notifications / communication inbox
  2. [ ] Mark one read; mark all read
- **Expected result:** Unread counts clear; items remain accessible as read
- **Also verify (negative/permission case):** No `post_site_announcement` admin controls
- **Existing automated coverage:** partial — unread grade badge in `activity.spec.js`

### [STU-23] Settings — student-permitted fields
- **Role(s):** Student
- **Page(s)/entry point:** `public/settings.php` (`$isStudent`, `$canEditEmail = $isStudent || $isOwner`)
- **Preconditions:** Logged in as student
- **Steps:**
  1. [ ] `update_profile` (name + email with password confirm when email changes)
  2. [ ] `change_password`
  3. [ ] `update_notifications`
  4. [ ] `update_customization` / `reset_customization`
- **Expected result:** Profile/email editable for student; notification and appearance prefs save
- **Also verify (negative/permission case):** No admin.php / owner developer controls; settings is not site-wide admin config
- **Existing automated coverage:** none

### [STU-24] Events — visibility only
- **Role(s):** Student
- **Page(s)/entry point:** `public/events.php`
- **Preconditions:** School-wide event + enrolled course event + other-course event exist
- **Steps:**
  1. [ ] Open Events list/detail
- **Expected result:** Sees school-wide + enrolled course events; no create panel (`portal_event_staff_can_compose` false)
- **Also verify (negative/permission case):** Cannot open other-course event; CSRF `create_event` irrelevant (no UI) but POST should fail auth
- **Existing automated coverage:** `tests/security/events.spec.js`: student visibility; "student does not see create event panel"

### [STU-25] Lesson Q&A as learner
- **Role(s):** Student
- **Page(s)/entry point:** `public/lesson-viewer.php` actions `ask_question`, `delete_question`, `save_progress`
- **Preconditions:** Lesson material; `$canAsk = !$canManage`
- **Steps:**
  1. [ ] Ask a question
  2. [ ] Save progress
  3. [ ] Delete own unanswered question if allowed
- **Expected result:** Question posted; progress saved; cannot `answer_question` / `publish_answer` / `save_lesson_notes`
- **Also verify (negative/permission case):** Staff-only actions rejected
- **Existing automated coverage:** partial — `tests/lesson_qa_ajax_check.php`

### [STU-26] Negative — privileged pages blocked
- **Role(s):** Student
- **Page(s)/entry point:** `public/admin.php`, `activity-builder.php`, `activity-results.php`, `question-bank.php`
- **Preconditions:** Student session
- **Steps:**
  1. [ ] GET each privileged URL (with course/activity ids where required)
- **Expected result:** Redirect / access denied; `portal_require_admin` / `portal_can_manage_course` / staff gate
- **Also verify (negative/permission case):** No integrity teacher cards (`portal_integrity_summary_cards` / review with `$isTeacher`); no other students’ submissions
- **Existing automated coverage:** `activity.spec.js`: "student cannot open activity builder…"; `security-bulk-actions.spec.js`: "non-admin cannot open security activity…"; enrollment IDOR in `security.spec.js`
