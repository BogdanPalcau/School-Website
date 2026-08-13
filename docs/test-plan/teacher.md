# Teacher + Supervisor test plan (`TCH-*` / `SUP-*`)

Global role: `users.role = 'teacher'`.

Course assignment (separate): `course_teachers.assignment_role` ∈ `{teacher, supervisor}` via `portal_valid_assignment_role()`, `portal_course_assignment_role()`, `portal_is_course_teacher()`, `portal_is_course_supervisor()`.

**Important (code truth):** `portal_can_manage_course()` is true for **any** assignment row. Supervisor and teacher assignments share manage permissions. Differences are label/UI (`portal_course_assignment_role_label`) and the assignment_role value itself. Neither can run admin-only staff assignment (`assign_teacher` / `change_assignment_role` / `remove_teacher`).

---

## Teacher (`assignment_role = 'teacher'`)

### [TCH-01] Manage only assigned courses
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/course.php`, helpers `portal_can_manage_course` / `portal_can_access_course`
- **Preconditions:** Teacher A assigned to Course A only; Course B exists without assignment
- **Steps:**
  1. [ ] Open Course A — confirm manage UI (folders, create item, mark)
  2. [ ] Open Course B by slug/id
- **Expected result:** Course A manageable; Course B denied (not enrolled as student either)
- **Also verify (negative/permission case):** Cannot access `admin.php` (`portal_require_admin`)
- **Existing automated coverage:** `tests/security/security.spec.js`: "lets assigned teachers manage their course but blocks unassigned course access"

### [TCH-02] Courses list — staff view of assigned set
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/courses.php` (`$staffCourseView` true for `portal_is_teacher()`)
- **Preconditions:** Assigned Course A
- **Steps:**
  1. [ ] Open courses list; favorite toggle optional (`toggle_favorite_course`)
- **Expected result:** Staff-oriented listing for accessible/assigned modules (not student enrolment-only)
- **Also verify (negative/permission case):** Still cannot open unassigned course detail
- **Existing automated coverage:** none (list UI); manage boundary covered above

### [TCH-03] Create / edit / lock folders and items
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/course.php` actions `create_folder`, `update_folder_settings`, `delete_folder`, `reorder_folders`, `toggle_folder_lock`, `create_item`, `update_item_settings`, `delete_item`, `reorder_items`, `toggle_item_lock`, `move_item`, `toggle_download`
- **Preconditions:** `portal_can_manage_course(Course A)`
- **Steps:**
  1. [ ] Create folder; reorder; lock/unlock
  2. [ ] Create document item (PDF/Word/PPT/text/spreadsheet upload)
  3. [ ] Create external link (`check_external_link` helper path)
  4. [ ] Toggle item lock and download flag
- **Expected result:** Content tree updates; locks affect students; uploads accept allowed types
- **Also verify (negative/permission case):** Content mismatch upload rejected; SVG/executables rejected per upload rules
- **Existing automated coverage:** `security.spec.js` legitimate text upload + type mismatch; IDOR delete blocked; `security_material_lock_check.php`

### [TCH-04] Schedule, rooms, notices, course description, tabs
- **Role(s):** Teacher
- **Page(s)/entry point:** `course.php` actions `create_schedule_slot`, `update_schedule_slot`, `delete_schedule_slot`, `update_course_description`, `save_tab_settings`
- **Preconditions:** Manage Course A
- **Steps:**
  1. [ ] Add/update/delete a schedule slot (room/time)
  2. [ ] Update course description
  3. [ ] Toggle course tabs (`content` always on)
- **Expected result:** Calendar/timetable reflect slots; tab visibility updates for students
- **Also verify (negative/permission case):** Unassigned teacher cannot POST these for Course B
- **Existing automated coverage:** none

### [TCH-05] Create submission slot (assignment)
- **Role(s):** Teacher
- **Page(s)/entry point:** `course.php` `create_item` / `update_item_settings` with `type = submission` (`submission_deadline`, `submission_max_attempts`, `submission_weight`, `submission_ai_detection`)
- **Preconditions:** Folder exists
- **Steps:**
  1. [ ] Create submission item with future deadline
  2. [ ] Set max attempts and weight
  3. [ ] Edit deadline later
- **Expected result:** Slot visible to enrolled students; invalid deadline rejected on create/update
- **Also verify (negative/permission case):** Student cannot `create_item`
- **Existing automated coverage:** none (UI); upload validation CLI separate

### [TCH-06] Review submissions, grade, feedback, annotations
- **Role(s):** Teacher
- **Page(s)/entry point:** `course.php` actions `mark_submission`, `save_annotation`, `delete_annotation`, `delete_submission`; `preview.php` / `view.php?sub=`
- **Preconditions:** Student submitted work
- **Steps:**
  1. [ ] Open submission preview
  2. [ ] Add annotation; delete annotation
  3. [ ] `mark_submission` with score + feedback
  4. [ ] Confirm student sees grade (after refresh / notification)
- **Expected result:** Mark stored (`marked_by`, `grade_seen_at` reset); feedback visible to owner student only
- **Also verify (negative/permission case):** Cannot mark submissions on unassigned course; student cannot `mark_submission`
- **Existing automated coverage:** none (E2E marking); download IDOR covered

### [TCH-07] Integrity / originality signals (submissions)
- **Role(s):** Teacher
- **Page(s)/entry point:** `course.php` action `rerun_integrity`; UI via `portal_integrity_summary_cards` / `portal_render_submission_review` (`integrity.php`); upload path `submission_security.php`
- **Preconditions:** Submission with extractable text; teacher manage course
- **Steps:**
  1. [ ] Open submission review panel
  2. [ ] Confirm similarity / process / heuristic signals shown as **signals for review**
  3. [ ] Run `rerun_integrity`
- **Expected result:** Teacher sees detailed signals/notes; labels remain review-oriented (not automatic verdicts)
- **Also verify (negative/permission case):** Student sees high-level `portal_integrity_student_summary` only — not full teacher match cards
- **Existing automated coverage:** partial — integrity eval/benchmark CLIs; activity integrity UI in `activity.spec.js` (activity domain, not file submission)

### [TCH-08] Activity Builder — author question types
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/activity-builder.php` actions `save_settings`, `add_section`, `update_section`, `add_question`, `update_question`, `add_option`, `update_option`, `save_rubric`, …
- **Preconditions:** Manage course; activity created (`create_item` type `activity` or builder create/template)
- **Steps:**
  1. [ ] Add sections
  2. [ ] Author each type: single choice, multiple choice, true/false, short text, numeric (+tolerance), fill-in-the-blank, ordering, matching, long response (+rubric), rating scale
  3. [ ] Configure timing / attempt limits in `save_settings`
- **Expected result:** Questions persist; `validate` reports readiness; preview payload works for staff
- **Also verify (negative/permission case):** Student POST to builder fails; unassigned teacher denied (`portal_activity_require_manage`)
- **Existing automated coverage:** partial — `activity.spec.js` builder open + readiness guidance + CSRF; scoring types in `activity_system_check.php`

### [TCH-09] Activity Builder — media upload accept/reject
- **Role(s):** Teacher
- **Page(s)/entry point:** `activity-builder.php` action `upload_media`; `portal_activity_store_media` / `portal_activity_media_path_safe`
- **Preconditions:** Builder open on managed activity
- **Steps:**
  1. [ ] Upload accepted: png/jpg/jpeg/webp/gif, mp3/m4a/wav/ogg, mp4/webm
  2. [ ] Reject: svg, empty file, path-traversal filename
- **Expected result:** Accepted media stored under activities path; rejects fail closed
- **Also verify (negative/permission case):** `activity-media.php` does not serve draft media to students
- **Existing automated coverage:** `activity_system_check.php` Media section

### [TCH-10] Activity Builder — CSV bank import + publish versioning
- **Role(s):** Teacher
- **Page(s)/entry point:** actions `import_csv_preview`, `import_csv_apply`, `save_to_bank`, `add_from_bank`, `list_bank`, `publish`, `unpublish`, `list_versions`, `restore_version_as_draft`, `duplicate_activity`
- **Preconditions:** CSV fixture; existing published activity with in-progress student attempt
- **Steps:**
  1. [ ] Preview/apply CSV; invalid rows flagged
  2. [ ] Publish version
  3. [ ] Edit after publish (new draft)
  4. [ ] Confirm in-progress attempt stays on original version id
- **Expected result:** Publish freezes version; edits go to new draft; attempts bound to start version
- **Also verify (negative/permission case):** Unpublished draft hidden from students
- **Existing automated coverage:** `activity_system_check.php` CSV + Versioning; `activity.spec.js` draft invisible

### [TCH-11] Activity Results — mark and release
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/activity-results.php` actions `mark_answer`, `apply_suggestions`, `complete_marking`, `release_results`, `invalidate_attempt`, `reopen_attempt`, `delete_attempt(s)`, `save_accommodation`, `export_csv`, `load_attempt`
- **Preconditions:** Submitted Assessment/Quiz with long_response; manage course
- **Steps:**
  1. [ ] Open results for own activity only
  2. [ ] Manually mark long response; complete marking
  3. [ ] `release_results` for Assessment delayed release
  4. [ ] Optionally invalidate/reopen
- **Expected result:** Analytics/marking workspace works; student sees released result; unassigned course activity inaccessible
- **Also verify (negative/permission case):** Cannot open another teacher’s course results without manage rights
- **Existing automated coverage:** partial — written marking in `activity_system_check.php`; grades/unread UI in `activity.spec.js`

### [TCH-12] Question bank
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/question-bank.php` (staff gate `portal_is_course_staff() || portal_is_admin()`); actions `add_to_activity`, `create_collection`, `add_to_collection`
- **Preconditions:** Teacher global role
- **Steps:**
  1. [ ] Open bank; create collection; add item to activity
- **Expected result:** Bank usable for staff
- **Also verify (negative/permission case):** Student redirected/denied
- **Existing automated coverage:** none (page E2E)

### [TCH-13] Discussions and announcements (own vs others)
- **Role(s):** Teacher
- **Page(s)/entry point:** `course.php` `create_topic`, `delete_topic`, `post_announcement`, `delete_announcement`, `post_reply`
- **Preconditions:** Manage course; second teacher also assigned (optional)
- **Steps:**
  1. [ ] Create topic; post announcement
  2. [ ] Delete **own** announcement (non-admin path requires `user_id` match)
  3. [ ] Attempt delete of another user’s announcement (as non-admin teacher)
- **Expected result:** Own announcement deletable; others’ announcements not deleted unless `portal_is_admin()`
- **Also verify (negative/permission case):** UI delete control only when admin or announcement owner (`course.php` template condition)
- **Existing automated coverage:** none

### [TCH-14] Groups
- **Role(s):** Teacher
- **Page(s)/entry point:** `create_group`, `delete_group` (manage); students `join_group` / `leave_group`
- **Preconditions:** Manage course
- **Steps:**
  1. [ ] Create/delete group
- **Expected result:** Groups available to students on course
- **Also verify (negative/permission case):** Cross-course leave IDOR blocked
- **Existing automated coverage:** `security.spec.js`: "blocks cross-course group leave IDOR"

### [TCH-15] Gradebook weight / include_in_gradebook
- **Role(s):** Teacher
- **Page(s)/entry point:** Activity settings (`include_in_gradebook`, weight); helpers `portal_course_gradebook_weight_total` / `portal_course_gradebook_weight_fits`; `public/grades.php` staff view
- **Preconditions:** Activity configured with gradebook inclusion
- **Steps:**
  1. [ ] Set weight that fits; confirm grades view
  2. [ ] Attempt overweight configuration if UI/API exposes check
- **Expected result:** Released activity marks appear in staff Grades for assigned courses (`portal_assigned_course_ids`)
- **Also verify (negative/permission case):** Teacher does not see admin “all active modules” unless `portal_is_admin()`
- **Existing automated coverage:** `activity.spec.js`: "released activity grades appear in the staff Grades view"

### [TCH-16] Events — course scope only
- **Role(s):** Teacher
- **Page(s)/entry point:** `public/events.php` `create_event`, `update_event`, `cancel_event`, `delete_event` via `portal_event_can_manage` / `portal_event_staff_can_compose`
- **Preconditions:** Assigned course
- **Steps:**
  1. [ ] Create course-scoped event
  2. [ ] Attempt school-wide scope
- **Expected result:** Course event OK; school-wide blocked for teacher
- **Also verify (negative/permission case):** Student cannot create
- **Existing automated coverage:** `events.spec.js` + `events_system_check.php`

### [TCH-17] Dashboard / grades staff queues
- **Role(s):** Teacher
- **Page(s)/entry point:** `dashboard.php` (`$isStaff`), `grades.php` (`$isStaff`)
- **Preconditions:** Pending marks / activities
- **Steps:**
  1. [ ] Confirm staff widgets / assigned-module queues
- **Expected result:** Staff to-do queues for assigned work; not full admin security panel
- **Also verify (negative/permission case):** No `admin.php?section=security` bulk tools
- **Existing automated coverage:** partial grades/unread in `activity.spec.js`

### [TCH-18] Settings — teacher email locked
- **Role(s):** Teacher
- **Page(s)/entry point:** `settings.php` (`$canEditEmail` false for teacher)
- **Preconditions:** Teacher session
- **Steps:**
  1. [ ] Open profile; attempt email change if field present
- **Expected result:** Email not editable (server forces existing email when `!$canEditEmail`); name/password/notifications/customization still work
- **Also verify (negative/permission case):** No owner-only admin settings here
- **Existing automated coverage:** none

### [TCH-19] Negative — drafts, admin, foreign courses
- **Role(s):** Teacher
- **Page(s)/entry point:** `admin.php`, other teachers’ draft activities, security audit sections
- **Preconditions:** Teacher A; draft on Course B managed by someone else / unassigned
- **Steps:**
  1. [ ] GET `admin.php`
  2. [ ] Attempt builder/results on unmanaged activity ids
- **Expected result:** Admin denied; unmanaged drafts inaccessible; no full admin security/audit tools
- **Also verify (negative/permission case):** CSRF without token rejected on course/builder POSTs
- **Existing automated coverage:** `security.spec.js` CSRF course + admin; `activity.spec.js` builder CSRF; draft student tests; teacher manage boundary

### [TCH-20] Lesson viewer staff tools
- **Role(s):** Teacher
- **Page(s)/entry point:** `lesson-viewer.php` `answer_question`, `publish_answer`, `toggle_pin`, `save_lesson_notes`, `delete_question`
- **Preconditions:** Student asked a question; teacher manages course
- **Steps:**
  1. [ ] Answer and publish; pin; save lesson notes
- **Expected result:** Q&A workflow completes; student notified if prefs on
- **Also verify (negative/permission case):** Unassigned teacher cannot manage Q&A on that course
- **Existing automated coverage:** partial — `lesson_qa_ajax_check.php`

---

## Supervisor course-assignment variant (`SUP-*`)

Use a **teacher** account with `course_teachers.assignment_role = 'supervisor'` on Course A (set via admin `assign_teacher` / `change_assignment_role`).

### [SUP-01] Assignment role helpers and label
- **Role(s):** Supervisor (course assignment)
- **Page(s)/entry point:** `portal_is_course_supervisor`, `portal_is_course_teacher`, `portal_course_assignment_role_label`; staff list on `course.php`
- **Preconditions:** User global role `teacher`; assignment `supervisor` on Course A
- **Steps:**
  1. [ ] Confirm UI label “Course Supervisor” on course staff panel
  2. [ ] Confirm `portal_is_course_supervisor(Course A)` true and `portal_is_course_teacher(Course A)` false (conceptually / via rolecheck script)
- **Expected result:** Label and assignment_role distinguish supervisor; deprecated `portal_is_supervisor()` remains false globally
- **Also verify (negative/permission case):** No fifth global role in admin create-user dropdown
- **Existing automated coverage:** `scripts/role_access_check.php` supervisor section

### [SUP-02] Manage parity with teacher assignment
- **Role(s):** Supervisor
- **Page(s)/entry point:** Same manage actions as `TCH-03`–`TCH-11` gated by `portal_can_manage_course`
- **Preconditions:** Supervisor assigned Course A; compare with Teacher A on same course (or sequential)
- **Steps:**
  1. [ ] Create folder / item / activity draft
  2. [ ] Open `activity-builder.php` and `activity-results.php`
  3. [ ] Mark a submission (`mark_submission`)
  4. [ ] Post announcement
- **Expected result:** All succeed equally to a `assignment_role = teacher` account on that course
- **Also verify (negative/permission case):** Still cannot manage unassigned Course B; still cannot open `admin.php`
- **Existing automated coverage:** `scripts/role_access_check.php` (folder/activity create); Playwright has no dedicated supervisor persona

### [SUP-03] Cannot assign course staff
- **Role(s):** Supervisor
- **Page(s)/entry point:** `course.php` actions `assign_teacher`, `change_assignment_role`, `remove_teacher` (`portal_is_admin()` gate)
- **Preconditions:** Supervisor session on Course A
- **Steps:**
  1. [ ] Confirm staff-assignment UI absent or POST rejected
- **Expected result:** Only admin/owner can change `course_teachers` rows
- **Also verify (negative/permission case):** Plain course teacher also blocked from these actions
- **Existing automated coverage:** `scripts/role_access_check.php` asserts teacher cannot use admin-only staff assignment

### [SUP-04] Events / grades / integrity same as teacher
- **Role(s):** Supervisor
- **Page(s)/entry point:** `events.php`, `grades.php`, integrity review on submissions
- **Preconditions:** Supervisor manage Course A
- **Steps:**
  1. [ ] Create course event; open grades for assigned course; open integrity review
- **Expected result:** Same capabilities as assigned teacher; still no school-wide event create; no admin integrity settings (`save_integrity_settings` is admin.php)
- **Also verify (negative/permission case):** Cannot `save_integrity_settings` / `lookup_submission_receipt` on admin panel
- **Existing automated coverage:** none specific to supervisor (teacher events covered)
