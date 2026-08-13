Rangoon International Education Online — LMS

A lightweight Learning Management System built with PHP and SQLite for Rangoon International Education Online.

It's a single, central place for students, teachers, supervisors, and admins to handle everything from course materials and submissions to schedules, discussions, and academic integrity reviews, without needing a dozen different tools bolted together.

This project started as a full-stack web app built with a real focus on education, usability, role-based access control, and keeping course data handled securely.

---

WHAT IT CAN DO

Course management
- Create and manage courses
- Organise materials into course folders
- Upload learning resources: PDFs, Word docs, PowerPoint files, text files, spreadsheets
- Add external learning links
- Lock folders or items when you need to restrict access
- Manage course schedules, rooms, notices, and deadlines

Student portal
- View enrolled courses
- Access course materials
- Open supported documents straight in the browser, no downloading required
- Submit assignment files
- Keep track of deadlines and submission status
- Join course discussions
- Catch up on announcements and course updates
- Complete interactive Activities (practice, quizzes, challenges, assessments, surveys)

Teacher and supervisor tools
- Manage assigned courses
- Create assignments and submission slots
- Build and publish Activities with versioning, media, and question banks
- Review student submissions
- Add feedback and grades
- View originality and integrity information
- Review assessment integrity signals for formal assessments
- Annotate and comment directly on submitted work
- Manage course discussions and announcements

Admin and owner tools
- Manage users and roles
- Assign teachers and supervisors to courses
- Enrol students onto courses
- Configure site-wide settings
- Manage announcements
- Review system-level data and access control

Academic integrity and review
The system includes an academic review workflow built to support teacher judgement, not replace it. It can generate submission receipts, extract document text, calculate similarity-style indicators, surface writing/process signals, and optionally plug into external AI-detection services when configured.

Activities (interactive learning)
Teachers can place Activities inside course folders. Students open them from the course content list. Modes:

- Practice — formative practice with immediate feedback options and repeatable attempts
- Quiz — short checks for understanding
- Challenge — harder formative tasks, often with scoring and XP
- Assessment — formal assessed work with attempt limits, integrity signals, and delayed result release
- Survey — opinion / feedback collection (typically unscored)
- Flashcards — course decks with a flip study player (Know / Still learning); also listed on the global Flashcards hub (`flashcards.php`)

Teacher builder (`activity-builder.php`) lets staff author sections and questions, configure timing and attempt limits, attach media, import CSV question banks, publish a version, and review results. Students use the player (`activity.php`) to start or resume attempts, autosave answers, and submit. Correct answers, teacher notes, and explanations are not exposed while an assessment attempt is in progress.

Question types include single choice, multiple choice, true/false, short text, numeric (with tolerance), fill-in-the-blank, ordering, matching, long response (manual marking), and rating scale.

Media support covers images (png/jpg/jpeg/webp/gif), audio (mp3/m4a/wav/ogg), and video (mp4/webm). SVG and empty uploads are rejected; stored paths are constrained under `uploads/activities/` with traversal checks.

Assessment integrity signals (limitations)
For Assessment mode with integrity enabled, the player can record browser-side signals such as focus loss, paste/copy attempts, visibility changes, and similar events. These are signals for teacher review, not proof of misconduct. Labels stay neutral (for example “A few signals” / “Review recommended”). Clipboard text is never stored — only classifications and counts. A browser cannot reliably identify the exact website a paste came from. There is no webcam or screen recording. External originality checking needs a separately configured provider and is optional.

Gamification
Activities can award XP and badges on the server using unique reward keys so client-side forging or double-claiming is not possible. Practice repeats may grant reduced XP once per attempt where configured.

Gradebook integration
Activities can set `include_in_gradebook` and a grade weight so completed assessments contribute to course grade views.

Security
- Role-based access control
- Course-level access checks
- CSRF protection on sensitive actions
- Login throttling
- Self-service password reset via SMTP (when configured)
- Admin/owner student invites (email-bound, single-use, course-locked; copyable link always, email when SMTP is ready)
- Grade return emails and 24h assignment deadline reminders (when SMTP + `PORTAL_BASE_URL` are configured; run `php scripts/send_notification_emails.php` on a schedule)
- Safer upload validation
- Protected database and upload folders
- Rich-text sanitisation for discussions and announcements
- Blocks unsafe HTML, JavaScript links, and dangerous embedded content
- Activity answer-key isolation for in-progress assessments
- Playwright-based security tests and PHP activity system checks

---

TECH STACK

Backend: PHP 8+
Database: SQLite via PDO
Frontend: HTML, CSS, JavaScript
Local dev: XAMPP / Apache
Email: PHPMailer (Composer)
Testing: Playwright + PHP CLI checks
Document previews (optional): LibreOffice

Required PHP extensions
- pdo_sqlite
- fileinfo
- dom
- json
- mbstring

Composer dependencies (password reset / outbound mail)
```
composer install
```
Copy `.env.example` to `.env` and set `SMTP_*`, `PORTAL_BASE_URL`, and `PORTAL_APP_SECRET`. Without SMTP, the forgot-password form still shows a neutral success message and does not reveal whether an account exists. Grade/deadline/invite emails are skipped until SMTP and `PORTAL_BASE_URL` are set; schedule `php scripts/send_notification_emails.php --hours=24` hourly or daily for due-soon reminders. Admins can still create student invites and copy the link from Admin → Student Invites without SMTP.

---

DATABASE / MIGRATION NOTES

Activity tables are created and upgraded through `portal_activity_run_migrations()` in `activity.php`, which runs as part of normal bootstrap/migration flow. New installs and existing SQLite databases pick up:

- `course_activities`, `activity_versions`, `activity_sections`, `activity_questions`, `activity_question_options`
- `activity_attempts`, `activity_answers`, `activity_integrity_events`
- `activity_media`, `activity_audit_events`, `question_bank_items`
- `gamification_events`, `gamification_badges`, `user_gamification_badges`

`course_folder_items.type` is extended to allow `activity`. Publishing freezes a version; later edits go to a new draft, and existing attempts stay bound to the version they started on.

---

TESTING

PHP activity system checks (permissions, scoring, answer leakage, attempts, integrity, XP, media path safety, CSV, versioning):

```
npm run test:activity
```

or:

```
C:\xampp\php\php.exe tests/activity_system_check.php
```

Playwright security / activity access tests (requires Chromium via `npm run playwright:install` and a reachable PHP built-in server — Playwright starts one on `127.0.0.1:8011` by default):

```
npm run test:security
```

Run both:

```
npm run test:all
```

---

PROJECT STRUCTURE

School-Website/
├── public/                 Public web pages
│   ├── index.php
│   ├── login.php
│   ├── forgot-password.php
│   ├── reset-password.php
│   ├── courses.php
│   ├── course.php
│   ├── activity.php            Student activity player
│   ├── activity-builder.php    Teacher activity builder
│   ├── activity-results.php    Results / analytics
│   ├── activity-media.php      Activity media serving
│   ├── question-bank.php
│   ├── admin.php
│   ├── download.php
│   ├── preview.php
│   ├── view.php
│   └── assets/
│       ├── activity-builder.js
│       ├── activity-player.js
│       └── activity-results.js
│
├── database/                Local SQLite database storage
│   └── portal.db            Generated locally, don't commit this
│
├── uploads/                  Uploaded course files, submissions, activity media
│
├── tests/
│   ├── activity_system_check.php
│   ├── fixtures/
│   │   ├── security-fixtures.php
│   │   └── activity-fixtures.php
│   └── security/             Playwright security + activity tests
│       ├── helpers.js
│       ├── security.spec.js
│       └── activity.spec.js
│
├── bootstrap.php             Core helpers, auth, database, security utilities
├── mailer.php                PHPMailer SMTP helper
├── notification_mail.php     Grade + deadline reminder emails
├── invite.php                Student invite helpers (admin/owner)
├── calendar_ics.php          iCalendar (.ics) export helpers
├── scripts/send_notification_emails.php  CLI deadline mailer (cron/Task Scheduler)
├── activity.php              Activities domain helpers (scoring, attempts, integrity, media)
├── composer.json             PHP dependencies (PHPMailer)
├── db_init.php               Database setup and seed data
├── course_catalog.php        Course data/helpers
├── integrity.php             Academic integrity and review helpers
├── submission_security.php   Submission upload validation helpers
├── package.json               Test scripts and Playwright dependency
└── README.md
