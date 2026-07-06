<?php
declare(strict_types=1);

$_db_path = __DIR__ . '/database/portal.db';
$_db_dir  = dirname($_db_path);

if (!is_dir($_db_dir)) {
    mkdir($_db_dir, 0755, true);
}

$_pdo = new PDO('sqlite:' . $_db_path);
$_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$_pdo->exec('PRAGMA foreign_keys = ON');

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
        email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT    NOT NULL,
        name          TEXT    NOT NULL,
        year          TEXT    NOT NULL DEFAULT 'Year 11',
        programme     TEXT    NOT NULL DEFAULT 'General',
        initials      TEXT    NOT NULL DEFAULT 'ST',
        role          TEXT    NOT NULL DEFAULT 'student'
                              CHECK(role IN ('owner','admin','teacher','student')),
        created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS courses (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        slug          TEXT    NOT NULL UNIQUE,
        code          TEXT    NOT NULL,
        title         TEXT    NOT NULL,
        full_title    TEXT    NOT NULL,
        summary       TEXT    NOT NULL,
        year_group    TEXT    NOT NULL DEFAULT '25/26',
        term          TEXT    NOT NULL DEFAULT 'Full year',
        status        TEXT    NOT NULL DEFAULT 'open',
        status_label  TEXT    NOT NULL DEFAULT 'Open',
        accent        TEXT    NOT NULL DEFAULT '#c1202f',
        meeting       TEXT    NOT NULL DEFAULT 'TBA',
        room          TEXT    NOT NULL DEFAULT 'TBA',
        notice        TEXT    NOT NULL DEFAULT '',
        student_count INTEGER NOT NULL DEFAULT 0
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_staff (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        name      TEXT    NOT NULL,
        role      TEXT    NOT NULL DEFAULT 'Teacher',
        UNIQUE(course_id, name)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS enrollments (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        enrolled_at TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, course_id)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_teachers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        assigned_at TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(course_id, user_id)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_announcements (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS site_announcements (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL DEFAULT '',
        priority    TEXT    NOT NULL DEFAULT 'normal'
                            CHECK(priority IN ('normal','urgent')),
        pinned      INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");
$_pdo->exec("CREATE INDEX IF NOT EXISTS idx_site_announcements_pinned ON site_announcements(pinned, created_at)");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_folders (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        locked      INTEGER NOT NULL DEFAULT 0,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_folder_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_id   INTEGER NOT NULL REFERENCES course_folders(id) ON DELETE CASCADE,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        type        TEXT    NOT NULL DEFAULT 'document'
                            CHECK(type IN ('document','link','submission')),
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        url         TEXT    NOT NULL DEFAULT '',
        file_path   TEXT    NOT NULL DEFAULT '',
        file_name   TEXT    NOT NULL DEFAULT '',
        submission_deadline TEXT NOT NULL DEFAULT '',
        submission_ai_detection INTEGER NOT NULL DEFAULT 0,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_tab_settings (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        tab_key     TEXT    NOT NULL,
        enabled     INTEGER NOT NULL DEFAULT 1,
        UNIQUE(course_id, tab_key)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_submissions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id      INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
        course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        filename     TEXT    NOT NULL,
        filepath     TEXT    NOT NULL,
        filesize     INTEGER NOT NULL DEFAULT 0,
        submitted_at TEXT    NOT NULL DEFAULT (datetime('now')),
        score        INTEGER,
        feedback     TEXT    NOT NULL DEFAULT '',
        marked_at    TEXT    NOT NULL DEFAULT '',
        marked_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        ai_status    TEXT    NOT NULL DEFAULT '',
        ai_score     REAL,
        ai_report    TEXT    NOT NULL DEFAULT '',
        ai_checked_at TEXT   NOT NULL DEFAULT '',
        receipt_number TEXT NOT NULL DEFAULT '',
        file_sha256  TEXT NOT NULL DEFAULT '',
        submission_text TEXT NOT NULL DEFAULT '',
        text_word_count INTEGER NOT NULL DEFAULT 0,
        similarity_status TEXT NOT NULL DEFAULT '',
        similarity_score REAL,
        similarity_report TEXT NOT NULL DEFAULT '',
        similarity_checked_at TEXT NOT NULL DEFAULT '',
        process_edit_seconds INTEGER NOT NULL DEFAULT 0,
        process_paste_events INTEGER NOT NULL DEFAULT 0,
        process_pasted_chars INTEGER NOT NULL DEFAULT 0,
        eula_accepted_at TEXT NOT NULL DEFAULT '',
        UNIQUE(item_id, user_id)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_submission_versions (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER REFERENCES course_submissions(id) ON DELETE CASCADE,
        item_id      INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
        course_id    INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        filename     TEXT NOT NULL DEFAULT '',
        filesize     INTEGER NOT NULL DEFAULT 0,
        file_sha256  TEXT NOT NULL DEFAULT '',
        text_word_count INTEGER NOT NULL DEFAULT 0,
        receipt_number TEXT NOT NULL DEFAULT '',
        similarity_status TEXT NOT NULL DEFAULT '',
        similarity_score REAL,
        process_edit_seconds INTEGER NOT NULL DEFAULT 0,
        process_paste_events INTEGER NOT NULL DEFAULT 0,
        process_pasted_chars INTEGER NOT NULL DEFAULT 0,
        submitted_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS integrity_eula_acceptances (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        version     TEXT NOT NULL,
        accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, version)
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_submission_annotations (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        submission_id INTEGER NOT NULL REFERENCES course_submissions(id) ON DELETE CASCADE,
        course_id     INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        author_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        anchor_type   TEXT NOT NULL DEFAULT 'text',
        range_start   INTEGER,
        range_end     INTEGER,
        quote         TEXT NOT NULL DEFAULT '',
        pos_x         REAL,
        pos_y         REAL,
        comment       TEXT NOT NULL DEFAULT '',
        created_at    TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at    TEXT NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_schedule (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        day_of_week TEXT    NOT NULL,
        start_time  TEXT    NOT NULL DEFAULT '',
        end_time    TEXT    NOT NULL DEFAULT '',
        room        TEXT    NOT NULL DEFAULT '',
        notes       TEXT    NOT NULL DEFAULT '',
        sort_order  INTEGER NOT NULL DEFAULT 0
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_discussion_topics (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        body        TEXT    NOT NULL DEFAULT '',
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_discussion_replies (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        topic_id    INTEGER NOT NULL REFERENCES course_discussion_topics(id) ON DELETE CASCADE,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        body        TEXT    NOT NULL,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_groups (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
        title       TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT '',
        max_members INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )
");

$_pdo->exec("
    CREATE TABLE IF NOT EXISTS course_group_members (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id  INTEGER NOT NULL REFERENCES course_groups(id) ON DELETE CASCADE,
        user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        joined_at TEXT    NOT NULL DEFAULT (datetime('now')),
        UNIQUE(group_id, user_id)
    )
");

// ── Seed: Bogdan (owner) ─────────────────────────────────────────────────────
// No credentials are committed to source control. The initial owner password is
// read from the PORTAL_OWNER_PASSWORD environment variable; if that is not set a
// strong random password is generated and written once to
// database/INITIAL_OWNER_PASSWORD.txt for the administrator to retrieve and then
// delete. The owner should change this password on first login.
$_ownerPassword = trim((string) getenv('PORTAL_OWNER_PASSWORD'));
$_ownerGenerated = false;
if ($_ownerPassword === '') {
    $_ownerPassword  = bin2hex(random_bytes(9)); // 18-character random password
    $_ownerGenerated = true;
}
$_bogdanHash = password_hash($_ownerPassword, PASSWORD_DEFAULT);
$_pdo->prepare("
    INSERT OR IGNORE INTO users (username, email, password_hash, name, year, programme, initials, role)
    VALUES (?,?,?,?,?,?,?,?)
")->execute(['bogdan', 'bogdan@rieo.edu', $_bogdanHash, 'Bogdan', 'Year 11', 'STEM pathway', 'BG', 'owner']);

if ($_ownerGenerated) {
    @file_put_contents(
        $_db_dir . DIRECTORY_SEPARATOR . 'INITIAL_OWNER_PASSWORD.txt',
        "RIEO portal — initial owner credentials\n"
        . "Username: bogdan\n"
        . "Password: {$_ownerPassword}\n\n"
        . "Sign in, change this password immediately, then delete this file.\n"
    );
}

// ── Seed: 12 courses (25/26 only) ────────────────────────────────────────────
$_courses = [
    ['biology-2526',          'BIO-2526-01',  'Biology',                  '25/26 — Biology',
     'Cell biology, genetics, and ecosystems with practical investigations and write-ups.',
     '25/26', 'Full year', 'open', 'Open', '#2e9c5e',  'Mon and Wed | 09:00',        'Science 1', 'Practical report section due next Monday.'],

    ['chemistry-2526',        'CHM-2526-02',  'Chemistry',                '25/26 — Chemistry',
     'Atomic structure, bonding, and quantitative chemistry with regular lab sessions.',
     '25/26', 'Full year', 'open', 'Open', '#e07b14',  'Tue and Thu | 10:30',        'Science 3', 'Titration calculation sheet due Thursday.'],

    ['physics-2526',          'PHY-2526-03',  'Physics',                  '25/26 — Physics',
     'Mechanics, waves, and electricity with regular practical write-ups and retrieval tasks.',
     '25/26', 'Full year', 'open', 'Open', '#10b2a8',  'Tue and Thu | 09:00',        'Science 2', 'Refraction write-up due Thursday evening.'],

    ['extended-maths-2526',   'MTH-2526-04E', 'Extended Mathematics',     '25/26 — Extended Mathematics',
     'Extended IGCSE content covering algebra, functions, and structured problem-solving.',
     '25/26', 'Full year', 'open', 'Open', '#7a5cff',  'Mon, Wed and Fri | 11:15',   'Room 12',   'Past-paper set 4 opens this Friday.'],

    ['additional-maths-2526', 'MTH-2526-05A', 'Additional Mathematics',   '25/26 — Additional Mathematics',
     'Functions, calculus, and trigonometry extending well beyond the core IGCSE syllabus.',
     '25/26', 'Full year', 'open', 'Open', '#4f7bde',  'Tue and Thu | 13:00',        'Room 11',   'Differentiation practice quiz next Tuesday.'],

    ['esl-2526',              'ESL-2526-06',  'English as Second Language','25/26 — English as Second Language',
     'Reading comprehension, writing accuracy, and spoken expression tailored for ESL learners.',
     '25/26', 'Full year', 'open', 'Open', '#e59722',  'Mon and Fri | 08:45',        'Room 7',    'Directed writing draft due Friday.'],

    ['ict-2526',              'ICT-2526-07',  'ICT',                      '25/26 — ICT',
     'Spreadsheets, databases, presentations, and digital communication skills.',
     '25/26', 'Full year', 'open', 'Open', '#3b82c4',  'Wed | 13:00',                'Lab 3',     'Database task checkpoint this Wednesday.'],

    ['computer-science-2526', 'CS-2526-08',   'Computer Science',         '25/26 — Computer Science',
     'Programming, algorithms, and systems thinking with hands-on project work.',
     '25/26', 'Full year', 'open', 'Open', '#d74264',  'Mon and Thu | 09:00',        'Lab 4',     'Prototype review due Monday morning.'],

    ['business-2526',         'BUS-2526-09',  'Business',                 '25/26 — Business',
     'Business organisation, marketing, and finance with structured case-study analysis.',
     '25/26', 'Full year', 'open', 'Open', '#14b8a6',  'Tue | 11:15',                'Room 9',    'Case study response due next Tuesday.'],

    ['accounting-2526',       'ACC-2526-10',  'Accounting',               '25/26 — Accounting',
     'Double-entry bookkeeping, financial statements, and ratio analysis.',
     '25/26', 'Full year', 'open', 'Open', '#c1202f',  'Mon and Wed | 13:30',        'Room 10',   'Trial balance exercise due Wednesday.'],

    ['tourism-2526',          'TOU-2526-11',  'Tourism',                  '25/26 — Tourism',
     'Global tourism patterns, impact analysis, and hospitality business concepts.',
     '25/26', 'Full year', 'open', 'Open', '#d97706',  'Thu | 13:00',                'Room 6',    'Destination research presentation this Thursday.'],

    ['economics-2526',        'ECO-2526-12',  'Economics',                '25/26 — Economics',
     'Supply and demand, market structures, and macroeconomic principles with diagram work.',
     '25/26', 'Full year', 'open', 'Open', '#6366f1',  'Wed and Fri | 10:30',        'Room 8',    'Diagram analysis task due Friday.'],
];

$_stmtC = $_pdo->prepare("
    INSERT OR IGNORE INTO courses
        (slug,code,title,full_title,summary,year_group,term,status,status_label,accent,meeting,room,notice)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
");
foreach ($_courses as $_c) {
    $_stmtC->execute($_c);
}

// ── Seed: course staff ────────────────────────────────────────────────────────
$_staff = [
    ['biology-2526',          'Dr Y. Tan',     'Lead teacher'],
    ['biology-2526',          'Ms S. Ali',      'Lab technician'],
    ['chemistry-2526',        'Mr P. Osei',     'Teacher'],
    ['chemistry-2526',        'Ms D. Wong',     'Lab technician'],
    ['physics-2526',          'Dr A. Ndlovu',   'Teacher'],
    ['extended-maths-2526',   'Mrs K. Lewis',   'Teacher'],
    ['additional-maths-2526', 'Mr F. Reyes',    'Teacher'],
    ['esl-2526',              'Ms J. Clarke',   'Teacher'],
    ['ict-2526',              'Mr T. James',    'Teacher'],
    ['computer-science-2526', 'Mr D. Hart',     'Lead teacher'],
    ['computer-science-2526', 'Ms R. Khan',     'Support teacher'],
    ['business-2526',         'Ms N. Park',     'Teacher'],
    ['accounting-2526',       'Mr R. Wood',     'Teacher'],
    ['tourism-2526',          'Mrs L. Santos',  'Teacher'],
    ['economics-2526',        'Mr C. Bailey',   'Teacher'],
];

$_stmtS = $_pdo->prepare("
    INSERT OR IGNORE INTO course_staff (course_id, name, role)
    SELECT c.id, ?, ? FROM courses c WHERE c.slug = ?
");
foreach ($_staff as [$_slug, $_name, $_role]) {
    $_stmtS->execute([$_name, $_role, $_slug]);
}

// ── Seed: enroll bogdan in every course ──────────────────────────────────────
$_bogdanId = (int) $_pdo->query("SELECT id FROM users WHERE username = 'bogdan'")->fetchColumn();
if ($_bogdanId > 0) {
    $_pdo->prepare("
        INSERT OR IGNORE INTO enrollments (user_id, course_id)
        SELECT ?, id FROM courses
    ")->execute([$_bogdanId]);
}

unset($_pdo, $_stmtC, $_stmtS, $_db_path, $_db_dir, $_courses, $_staff,
      $_bogdanHash, $_bogdanId, $_slug, $_name, $_role, $_c);
