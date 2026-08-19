<?php
declare(strict_types=1);

if (!function_exists('portal_course_ensure_schema')) {
    function portal_course_ensure_schema(): void
    {
// Safe migration: add allow_download if not yet present
try {
    portal_db()->exec("ALTER TABLE course_folder_items ADD COLUMN allow_download TINYINT(1) NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("CREATE TABLE IF NOT EXISTS announcement_reads (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        announcement_id INTEGER NOT NULL REFERENCES course_announcements(id) ON DELETE CASCADE,
        read_at         TEXT NOT NULL DEFAULT (datetime('now')),
        UNIQUE(user_id, announcement_id)
    )");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("ALTER TABLE course_folders ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("ALTER TABLE course_folder_items ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
} catch (\PDOException $e) {}
foreach ([
    "ALTER TABLE course_folder_items ADD COLUMN submission_deadline TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_folder_items ADD COLUMN submission_ai_detection INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_folder_items ADD COLUMN submission_max_attempts INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_folder_items ADD COLUMN submission_weight REAL NOT NULL DEFAULT 100",
] as $sql) {
    try { portal_db()->exec($sql); } catch (\PDOException $e) {}
}
foreach ([
    "ALTER TABLE course_submissions ADD COLUMN score INTEGER",
    "ALTER TABLE course_submissions ADD COLUMN feedback TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN marked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL",
    "ALTER TABLE course_submissions ADD COLUMN ai_status TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN ai_score REAL",
    "ALTER TABLE course_submissions ADD COLUMN ai_report TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN ai_checked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN receipt_number TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN file_sha256 TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN submission_text TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN text_word_count INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN similarity_status TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN similarity_score REAL",
    "ALTER TABLE course_submissions ADD COLUMN similarity_report TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN similarity_checked_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN process_edit_seconds INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN process_paste_events INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN process_pasted_chars INTEGER NOT NULL DEFAULT 0",
    "ALTER TABLE course_submissions ADD COLUMN eula_accepted_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN grade_seen_at TEXT NOT NULL DEFAULT ''",
    "ALTER TABLE course_submissions ADD COLUMN grades_released_at TEXT NOT NULL DEFAULT ''",
] as $sql) {
    try { portal_db()->exec($sql); } catch (\PDOException $e) {}
}
try {
    portal_db()->exec("
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
} catch (\PDOException $e) {}
try {
    portal_db()->exec("
        CREATE TABLE IF NOT EXISTS integrity_eula_acceptances (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            version     TEXT NOT NULL,
            accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
            UNIQUE(user_id, version)
        )
    ");
} catch (\PDOException $e) {}
try {
    portal_db()->exec("
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
    portal_db()->exec("CREATE INDEX IF NOT EXISTS idx_submission_annotations ON course_submission_annotations(submission_id)");
} catch (\PDOException $e) {}
    }
}
