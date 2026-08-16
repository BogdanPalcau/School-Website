<?php
declare(strict_types=1);

/**
 * Activities / Quizzes / Assessments — schema, scoring, attempts, integrity, gamification.
 */

// ── Secret / time helpers ─────────────────────────────────────────────────────

if (!function_exists('portal_activity_secret')) {
    function portal_activity_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $env = getenv('PORTAL_ACTIVITY_SECRET');
        if (is_string($env) && trim($env) !== '') {
            $secret = trim($env);
            return $secret;
        }

        $path = function_exists('portal_db_path') ? portal_db_path() : (__DIR__ . '/database/portal.db');
        $secret = hash('sha256', 'portal-activity|' . $path);
        return $secret;
    }
}

if (!function_exists('portal_activity_hash_token')) {
    function portal_activity_hash_token(string $token): string
    {
        return hash_hmac('sha256', $token, portal_activity_secret());
    }
}

if (!function_exists('portal_activity_now')) {
    /** Portal-local datetime string (Europe/London via bootstrap timezone). */
    function portal_activity_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('portal_activity_now_utc')) {
    /** UTC datetime matching SQLite datetime('now') comparisons. */
    function portal_activity_now_utc(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('portal_activity_json_encode')) {
    function portal_activity_json_encode(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? 'null' : $json;
    }
}

if (!function_exists('portal_activity_json_decode')) {
    function portal_activity_json_decode(?string $raw, mixed $default = null): mixed
    {
        if ($raw === null || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }
}

// ── Modes / question types ────────────────────────────────────────────────────

if (!function_exists('portal_activity_modes')) {
    /** @return list<string> */
    function portal_activity_modes(): array
    {
        return ['practice', 'quiz', 'challenge', 'assessment', 'survey', 'flashcard'];
    }
}

if (!function_exists('portal_activity_mode_label')) {
    function portal_activity_mode_label(string $mode): string
    {
        return match ($mode) {
            'practice'   => 'Practice',
            'quiz'       => 'Quiz',
            'challenge'  => 'Challenge',
            'assessment' => 'Assessment',
            'survey'     => 'Survey',
            'flashcard'  => 'Flashcards',
            default      => ucfirst($mode),
        };
    }
}

if (!function_exists('portal_activity_question_types')) {
    /** @return list<string> */
    function portal_activity_question_types(): array
    {
        return [
            'single_choice',
            'multiple_choice',
            'true_false',
            'short_text',
            'numeric',
            'long_response',
            'fill_blank',
            'ordering',
            'matching',
            'rating_scale',
            'flashcard',
        ];
    }
}

if (!function_exists('portal_activity_question_type_label')) {
    function portal_activity_question_type_label(string $type): string
    {
        return match ($type) {
            'single_choice'   => 'Single choice',
            'multiple_choice' => 'Multiple choice',
            'true_false'      => 'True/false',
            'short_text'      => 'Short text',
            'numeric'         => 'Numeric',
            'long_response'   => 'Long response',
            'fill_blank'      => 'Fill in blank',
            'ordering'        => 'Ordering',
            'matching'        => 'Matching',
            'rating_scale'    => 'Rating scale',
            'flashcard'       => 'Flashcard',
            default           => $type,
        };
    }
}

// ── Migrations ────────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_run_migrations')) {
    function portal_activity_run_migrations(): void
    {
        $db = portal_db();

        $db->exec("
            CREATE TABLE IF NOT EXISTS course_activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_item_id INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
                course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                mode TEXT NOT NULL DEFAULT 'quiz'
                    CHECK(mode IN ('practice','quiz','challenge','assessment','survey','flashcard')),
                status TEXT NOT NULL DEFAULT 'draft'
                    CHECK(status IN ('draft','published','archived')),
                title TEXT NOT NULL,
                short_description TEXT NOT NULL DEFAULT '',
                instructions_html TEXT NOT NULL DEFAULT '',
                version INTEGER NOT NULL DEFAULT 1,
                opens_at TEXT NOT NULL DEFAULT '',
                closes_at TEXT NOT NULL DEFAULT '',
                due_at TEXT NOT NULL DEFAULT '',
                estimated_minutes INTEGER NOT NULL DEFAULT 0,
                time_limit_seconds INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 0,
                pass_mark REAL,
                feedback_policy TEXT NOT NULL DEFAULT 'after_submission'
                    CHECK(feedback_policy IN ('after_each','after_submission','after_close','when_released','never')),
                navigation_policy TEXT NOT NULL DEFAULT 'free'
                    CHECK(navigation_policy IN ('free','sequential','no_return')),
                shuffle_questions INTEGER NOT NULL DEFAULT 0,
                shuffle_options INTEGER NOT NULL DEFAULT 0,
                questions_per_attempt INTEGER NOT NULL DEFAULT 0,
                paste_policy TEXT NOT NULL DEFAULT 'allow'
                    CHECK(paste_policy IN ('allow','allow_log','block_log')),
                copy_policy TEXT NOT NULL DEFAULT 'allow'
                    CHECK(copy_policy IN ('allow','log','block_log')),
                integrity_enabled INTEGER NOT NULL DEFAULT 0,
                focus_monitoring INTEGER NOT NULL DEFAULT 0,
                fullscreen_policy TEXT NOT NULL DEFAULT 'off'
                    CHECK(fullscreen_policy IN ('off','optional','required')),
                include_in_gradebook INTEGER NOT NULL DEFAULT 0,
                grade_weight REAL NOT NULL DEFAULT 100,
                xp_enabled INTEGER NOT NULL DEFAULT 0,
                xp_amount INTEGER NOT NULL DEFAULT 0,
                leaderboard_enabled INTEGER NOT NULL DEFAULT 0,
                results_released INTEGER NOT NULL DEFAULT 0,
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                published_at TEXT NOT NULL DEFAULT '',
                is_pre_enroll INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL REFERENCES course_activities(id) ON DELETE CASCADE,
                version_number INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'draft'
                    CHECK(status IN ('draft','published','superseded')),
                settings_snapshot_json TEXT NOT NULL DEFAULT '{}',
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                published_at TEXT NOT NULL DEFAULT '',
                change_summary TEXT NOT NULL DEFAULT '',
                UNIQUE(activity_id, version_number)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_sections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_version_id INTEGER NOT NULL REFERENCES activity_versions(id) ON DELETE CASCADE,
                title TEXT NOT NULL DEFAULT '',
                instructions_html TEXT NOT NULL DEFAULT '',
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_questions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_version_id INTEGER NOT NULL REFERENCES activity_versions(id) ON DELETE CASCADE,
                section_id INTEGER REFERENCES activity_sections(id) ON DELETE SET NULL,
                question_type TEXT NOT NULL
                    CHECK(question_type IN (
                        'single_choice','multiple_choice','true_false','short_text','numeric',
                        'long_response','fill_blank','ordering','matching','rating_scale','flashcard'
                    )),
                prompt_html TEXT NOT NULL DEFAULT '',
                explanation_html TEXT NOT NULL DEFAULT '',
                hint_html TEXT NOT NULL DEFAULT '',
                teacher_notes TEXT NOT NULL DEFAULT '',
                points REAL NOT NULL DEFAULT 1,
                difficulty TEXT NOT NULL DEFAULT 'medium',
                tags TEXT NOT NULL DEFAULT '',
                required INTEGER NOT NULL DEFAULT 1,
                manual_marking INTEGER NOT NULL DEFAULT 0,
                settings_json TEXT NOT NULL DEFAULT '{}',
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_question_options (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id INTEGER NOT NULL REFERENCES activity_questions(id) ON DELETE CASCADE,
                option_text_html TEXT NOT NULL DEFAULT '',
                media_id INTEGER,
                is_correct INTEGER NOT NULL DEFAULT 0,
                credit REAL NOT NULL DEFAULT 0,
                feedback_html TEXT NOT NULL DEFAULT '',
                match_key TEXT NOT NULL DEFAULT '',
                pinned_position INTEGER,
                sort_order INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_rubrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                question_id INTEGER NOT NULL REFERENCES activity_questions(id) ON DELETE CASCADE,
                title TEXT NOT NULL DEFAULT '',
                student_visible_before INTEGER NOT NULL DEFAULT 0,
                student_visible_after INTEGER NOT NULL DEFAULT 1,
                created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_rubric_criteria (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                rubric_id INTEGER NOT NULL REFERENCES activity_rubrics(id) ON DELETE CASCADE,
                title TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                maximum_points REAL NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_rubric_levels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                criterion_id INTEGER NOT NULL REFERENCES activity_rubric_criteria(id) ON DELETE CASCADE,
                title TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                points REAL NOT NULL DEFAULT 0,
                sort_order INTEGER NOT NULL DEFAULT 0
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                activity_id INTEGER REFERENCES course_activities(id) ON DELETE SET NULL,
                activity_version_id INTEGER REFERENCES activity_versions(id) ON DELETE SET NULL,
                question_id INTEGER REFERENCES activity_questions(id) ON DELETE SET NULL,
                media_role TEXT NOT NULL DEFAULT 'attachment',
                media_type TEXT NOT NULL DEFAULT 'image'
                    CHECK(media_type IN ('image','audio','video')),
                original_filename TEXT NOT NULL DEFAULT '',
                storage_path TEXT NOT NULL DEFAULT '',
                mime_type TEXT NOT NULL DEFAULT '',
                filesize INTEGER NOT NULL DEFAULT 0,
                sha256 TEXT NOT NULL DEFAULT '',
                alt_text TEXT NOT NULL DEFAULT '',
                caption TEXT NOT NULL DEFAULT '',
                transcript TEXT NOT NULL DEFAULT '',
                uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL REFERENCES course_activities(id) ON DELETE CASCADE,
                activity_version_id INTEGER NOT NULL REFERENCES activity_versions(id) ON DELETE CASCADE,
                course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                attempt_number INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'in_progress'
                    CHECK(status IN (
                        'in_progress','submitted','auto_submitted',
                        'awaiting_manual_marking','marked','released','invalidated'
                    )),
                started_at TEXT NOT NULL DEFAULT (datetime('now')),
                expires_at TEXT NOT NULL DEFAULT '',
                submitted_at TEXT NOT NULL DEFAULT '',
                score REAL,
                maximum_score REAL,
                percentage REAL,
                question_order_json TEXT NOT NULL DEFAULT '[]',
                option_order_json TEXT NOT NULL DEFAULT '{}',
                session_token_hash TEXT NOT NULL DEFAULT '',
                autosave_revision INTEGER NOT NULL DEFAULT 0,
                integrity_summary_json TEXT NOT NULL DEFAULT '{}',
                overall_feedback_html TEXT NOT NULL DEFAULT '',
                integrity_ack TEXT NOT NULL DEFAULT '',
                end_reason TEXT NOT NULL DEFAULT '',
                resume_allowed INTEGER NOT NULL DEFAULT 0,
                reopened_at TEXT NOT NULL DEFAULT '',
                reopened_by INTEGER,
                reopen_count INTEGER NOT NULL DEFAULT 0,
                reopen_note TEXT NOT NULL DEFAULT '',
                grade_seen_at TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(activity_id, user_id, attempt_number)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id INTEGER NOT NULL REFERENCES activity_attempts(id) ON DELETE CASCADE,
                question_id INTEGER NOT NULL REFERENCES activity_questions(id) ON DELETE CASCADE,
                answer_json TEXT NOT NULL DEFAULT 'null',
                autosave_revision INTEGER NOT NULL DEFAULT 0,
                auto_score REAL,
                manual_score REAL,
                final_score REAL,
                feedback_html TEXT NOT NULL DEFAULT '',
                marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                marked_at TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(attempt_id, question_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_integrity_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                attempt_id INTEGER NOT NULL REFERENCES activity_attempts(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                question_id INTEGER,
                event_type TEXT NOT NULL,
                source_classification TEXT NOT NULL DEFAULT 'source_not_available',
                event_metadata_json TEXT NOT NULL DEFAULT '{}',
                client_elapsed_ms INTEGER NOT NULL DEFAULT 0,
                occurred_at TEXT NOT NULL DEFAULT '',
                received_at TEXT NOT NULL DEFAULT (datetime('now')),
                idempotency_key TEXT NOT NULL DEFAULT '',
                UNIQUE(attempt_id, idempotency_key)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_accommodations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL REFERENCES course_activities(id) ON DELETE CASCADE,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                extra_time_percent REAL NOT NULL DEFAULT 0,
                extra_minutes INTEGER NOT NULL DEFAULT 0,
                max_attempts_override INTEGER,
                allow_paste INTEGER NOT NULL DEFAULT 0,
                fullscreen_exempt INTEGER NOT NULL DEFAULT 0,
                navigation_override TEXT NOT NULL DEFAULT '',
                closes_at_override TEXT NOT NULL DEFAULT '',
                notes TEXT NOT NULL DEFAULT '',
                updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(activity_id, user_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS gamification_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                course_id INTEGER NOT NULL DEFAULT 0,
                activity_id INTEGER,
                attempt_id INTEGER,
                event_type TEXT NOT NULL,
                xp_amount INTEGER NOT NULL DEFAULT 0,
                unique_reward_key TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(unique_reward_key)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS gamification_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                badge_key TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                icon TEXT NOT NULL DEFAULT '',
                criteria_json TEXT NOT NULL DEFAULT '{}',
                enabled INTEGER NOT NULL DEFAULT 1
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_gamification_badges (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                badge_id INTEGER NOT NULL REFERENCES gamification_badges(id) ON DELETE CASCADE,
                course_id INTEGER NOT NULL DEFAULT 0,
                awarded_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, badge_id, course_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS question_bank_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                visibility TEXT NOT NULL DEFAULT 'private'
                    CHECK(visibility IN ('private','course','school')),
                source_course_id INTEGER,
                question_type TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                question_snapshot_json TEXT NOT NULL DEFAULT '{}',
                difficulty TEXT NOT NULL DEFAULT 'medium',
                tags TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS question_bank_collections (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                visibility TEXT NOT NULL DEFAULT 'private'
                    CHECK(visibility IN ('private','course','school')),
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS question_bank_collection_items (
                collection_id INTEGER NOT NULL REFERENCES question_bank_collections(id) ON DELETE CASCADE,
                question_bank_item_id INTEGER NOT NULL REFERENCES question_bank_items(id) ON DELETE CASCADE,
                PRIMARY KEY (collection_id, question_bank_item_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                template_key TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                mode TEXT NOT NULL DEFAULT 'quiz',
                definition_json TEXT NOT NULL DEFAULT '{}',
                enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS activity_audit_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                activity_id INTEGER NOT NULL REFERENCES course_activities(id) ON DELETE CASCADE,
                activity_version_id INTEGER,
                course_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER,
                action TEXT NOT NULL,
                details_json TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_course_activities_course ON course_activities(course_id)',
            'CREATE INDEX IF NOT EXISTS idx_course_activities_item ON course_activities(course_item_id)',
            'CREATE INDEX IF NOT EXISTS idx_course_activities_status ON course_activities(status)',
            'CREATE INDEX IF NOT EXISTS idx_activity_versions_activity ON activity_versions(activity_id, status)',
            'CREATE INDEX IF NOT EXISTS idx_activity_sections_version ON activity_sections(activity_version_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_questions_version ON activity_questions(activity_version_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_questions_section ON activity_questions(section_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_options_question ON activity_question_options(question_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_media_activity ON activity_media(activity_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_media_course ON activity_media(course_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_attempts_activity ON activity_attempts(activity_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_attempts_user ON activity_attempts(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_attempts_status ON activity_attempts(status)',
            'CREATE INDEX IF NOT EXISTS idx_activity_attempts_submitted ON activity_attempts(submitted_at)',
            'CREATE INDEX IF NOT EXISTS idx_activity_attempts_version ON activity_attempts(activity_version_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_answers_attempt ON activity_answers(attempt_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_integrity_attempt ON activity_integrity_events(attempt_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_integrity_received ON activity_integrity_events(received_at)',
            'CREATE INDEX IF NOT EXISTS idx_activity_accommodations_activity ON activity_accommodations(activity_id)',
            'CREATE INDEX IF NOT EXISTS idx_gamification_events_user ON gamification_events(user_id)',
            'CREATE INDEX IF NOT EXISTS idx_gamification_events_course ON gamification_events(course_id)',
            'CREATE INDEX IF NOT EXISTS idx_activity_audit_activity ON activity_audit_events(activity_id)',
            'CREATE INDEX IF NOT EXISTS idx_question_bank_owner ON question_bank_items(owner_id)',
        ];
        foreach ($indexes as $sql) {
            $db->exec($sql);
        }

        portal_activity_migrate_attempt_lifecycle_columns($db);
        portal_activity_migrate_folder_item_type($db);
        portal_activity_migrate_flashcard_checks($db);
        $activityCols = array_column($db->query('PRAGMA table_info(course_activities)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('is_pre_enroll', $activityCols, true)) {
            $db->exec('ALTER TABLE course_activities ADD COLUMN is_pre_enroll INTEGER NOT NULL DEFAULT 0');
        }
        portal_activity_seed_badges($db);
        portal_activity_seed_templates($db);
    }
}

if (!function_exists('portal_activity_migrate_attempt_lifecycle_columns')) {
    function portal_activity_migrate_attempt_lifecycle_columns(PDO $db): void
    {
        $columns = array_column(
            $db->query('PRAGMA table_info(activity_attempts)')->fetchAll(PDO::FETCH_ASSOC),
            'name'
        );
        $additions = [
            'end_reason' => "TEXT NOT NULL DEFAULT ''",
            'resume_allowed' => 'INTEGER NOT NULL DEFAULT 0',
            'reopened_at' => "TEXT NOT NULL DEFAULT ''",
            'reopened_by' => 'INTEGER',
            'reopen_count' => 'INTEGER NOT NULL DEFAULT 0',
            'reopen_note' => "TEXT NOT NULL DEFAULT ''",
            'grade_seen_at' => "TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($additions as $name => $definition) {
            if (!in_array($name, $columns, true)) {
                $db->exec('ALTER TABLE activity_attempts ADD COLUMN ' . $name . ' ' . $definition);
                if ($name === 'grade_seen_at') {
                    // Existing released grades predate unread tracking and
                    // should not suddenly appear as a large unread backlog.
                    $db->exec(
                        "UPDATE activity_attempts
                         SET grade_seen_at = COALESCE(NULLIF(updated_at, ''), datetime('now'))
                         WHERE status = 'released'"
                    );
                }
            }
        }
    }
}

if (!function_exists('portal_activity_migrate_folder_item_type')) {
    function portal_activity_migrate_folder_item_type(PDO $db): void
    {
        $itemsTableSql = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='course_folder_items'"
        )->fetchColumn() ?: '');

        if ($itemsTableSql === '' || strpos($itemsTableSql, "'activity'") !== false) {
            return;
        }

        $db->exec('BEGIN');
        try {
            $db->exec('PRAGMA foreign_keys = OFF');

            $createSql = preg_replace(
                '/CHECK\s*\(\s*type\s+IN\s*\([^)]*\)\s*\)/i',
                "CHECK(type IN ('document','link','submission','video','activity'))",
                $itemsTableSql
            );
            if (!is_string($createSql) || $createSql === $itemsTableSql) {
                throw new RuntimeException('Unable to widen course_folder_items type CHECK.');
            }

            $createSql = preg_replace(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?course_folder_items["`]?/i',
                'CREATE TABLE _course_folder_items_activity_new',
                $createSql,
                1
            );
            if (!is_string($createSql)) {
                throw new RuntimeException('Unable to rename course_folder_items for rebuild.');
            }

            $db->exec($createSql);

            $existingCols = array_column($db->query('PRAGMA table_info(course_folder_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $newCols = array_column($db->query('PRAGMA table_info(_course_folder_items_activity_new)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $copyCols = array_values(array_intersect($newCols, $existingCols));
            if ($copyCols === []) {
                throw new RuntimeException('No overlapping columns for course_folder_items rebuild.');
            }

            $colList = implode(', ', array_map(static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"', $copyCols));
            $db->exec(
                "INSERT INTO _course_folder_items_activity_new ($colList)
                 SELECT $colList FROM course_folder_items"
            );

            $indexRows = $db->query(
                "SELECT name, sql FROM sqlite_master
                 WHERE type='index' AND tbl_name='course_folder_items' AND sql IS NOT NULL"
            )->fetchAll(PDO::FETCH_ASSOC);

            $db->exec('DROP TABLE course_folder_items');
            $db->exec('ALTER TABLE _course_folder_items_activity_new RENAME TO course_folder_items');

            foreach ($indexRows as $idx) {
                $idxSql = (string) ($idx['sql'] ?? '');
                if ($idxSql !== '') {
                    $db->exec($idxSql);
                }
            }

            $db->exec('PRAGMA foreign_keys = ON');
            $fk = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($fk !== []) {
                throw new RuntimeException('Foreign key check failed after course_folder_items rebuild.');
            }

            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            try {
                $db->exec('PRAGMA foreign_keys = ON');
            } catch (Throwable $ignored) {
            }
            portal_log_security_event('activity_migration_failed', 'high', $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('portal_activity_migrate_flashcard_checks')) {
    /**
     * Widen mode / question_type CHECK constraints to include flashcard.
     * Existing DBs keep their CREATE TABLE SQL until rebuilt.
     */
    function portal_activity_migrate_flashcard_checks(PDO $db): void
    {
        $activitiesSql = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='course_activities'"
        )->fetchColumn() ?: '');
        if ($activitiesSql !== '' && strpos($activitiesSql, "'flashcard'") === false) {
            portal_activity_rebuild_table_check(
                $db,
                'course_activities',
                '/CHECK\s*\(\s*mode\s+IN\s*\([^)]*\)\s*\)/i',
                "CHECK(mode IN ('practice','quiz','challenge','assessment','survey','flashcard'))"
            );
        }

        $questionsSql = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='activity_questions'"
        )->fetchColumn() ?: '');
        if ($questionsSql !== '' && strpos($questionsSql, "'flashcard'") === false) {
            portal_activity_rebuild_table_check(
                $db,
                'activity_questions',
                '/CHECK\s*\(\s*question_type\s+IN\s*\([^)]*\)\s*\)/i',
                "CHECK(question_type IN ("
                . "'single_choice','multiple_choice','true_false','short_text','numeric',"
                . "'long_response','fill_blank','ordering','matching','rating_scale','flashcard'"
                . '))'
            );
        }
    }
}

if (!function_exists('portal_activity_rebuild_table_check')) {
    function portal_activity_rebuild_table_check(PDO $db, string $table, string $checkPattern, string $newCheck): void
    {
        $tableSql = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name=" . $db->quote($table)
        )->fetchColumn() ?: '');
        if ($tableSql === '') {
            return;
        }

        $tmp = '_' . $table . '_flashcard_new';
        $db->exec('BEGIN');
        try {
            $db->exec('PRAGMA foreign_keys = OFF');

            $createSql = preg_replace($checkPattern, $newCheck, $tableSql);
            if (!is_string($createSql) || $createSql === $tableSql) {
                throw new RuntimeException('Unable to widen CHECK on ' . $table);
            }

            $quoted = preg_quote($table, '/');
            $createSql = preg_replace(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["`]?' . $quoted . '["`]?/i',
                'CREATE TABLE ' . $tmp,
                $createSql,
                1
            );
            if (!is_string($createSql)) {
                throw new RuntimeException('Unable to rename ' . $table . ' for rebuild.');
            }

            $db->exec($createSql);

            $existingCols = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $newCols = array_column($db->query('PRAGMA table_info(' . $tmp . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $copyCols = array_values(array_intersect($newCols, $existingCols));
            if ($copyCols === []) {
                throw new RuntimeException('No overlapping columns for ' . $table . ' rebuild.');
            }

            $colList = implode(', ', array_map(
                static fn(string $c): string => '"' . str_replace('"', '""', $c) . '"',
                $copyCols
            ));
            $db->exec("INSERT INTO {$tmp} ({$colList}) SELECT {$colList} FROM {$table}");

            $indexRows = $db->query(
                "SELECT name, sql FROM sqlite_master
                 WHERE type='index' AND tbl_name=" . $db->quote($table) . ' AND sql IS NOT NULL'
            )->fetchAll(PDO::FETCH_ASSOC);

            $db->exec('DROP TABLE ' . $table);
            $db->exec('ALTER TABLE ' . $tmp . ' RENAME TO ' . $table);

            foreach ($indexRows as $idx) {
                $idxSql = (string) ($idx['sql'] ?? '');
                if ($idxSql !== '') {
                    $db->exec($idxSql);
                }
            }

            $db->exec('PRAGMA foreign_keys = ON');
            $fk = $db->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
            if ($fk !== []) {
                throw new RuntimeException('Foreign key check failed after ' . $table . ' rebuild.');
            }

            $db->exec('COMMIT');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->exec('ROLLBACK');
            }
            try {
                $db->exec('PRAGMA foreign_keys = ON');
            } catch (Throwable $ignored) {
            }
            portal_log_security_event('activity_migration_failed', 'high', $e->getMessage());
            throw $e;
        }
    }
}

if (!function_exists('portal_activity_seed_badges')) {
    function portal_activity_seed_badges(PDO $db): void
    {
        $count = (int) $db->query('SELECT COUNT(*) FROM gamification_badges')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $badges = [
            ['first_steps', 'First Steps', 'Complete your first activity.', 'steps', '{"event":"first_completion"}'],
            ['perfect_score', 'Perfect Score', 'Score 100% on a scored activity.', 'star', '{"percentage":100}'],
            ['on_a_roll', 'On a Roll', 'Complete three activities in a row.', 'flame', '{"streak":3}'],
            ['comeback', 'Comeback', 'Improve on a previous attempt.', 'refresh', '{"improved":true}'],
            ['course_explorer', 'Course Explorer', 'Try activities across the course.', 'map', '{"distinct_activities":3}'],
            ['practice_progress', 'Practice Progress', 'Finish five practice sessions.', 'practice', '{"practice_completions":5}'],
            ['quick_thinker', 'Quick Thinker', 'Submit a timed activity with time remaining.', 'bolt', '{"early_finish":true}'],
            ['topic_master', 'Topic Master', 'Master a topic through practice.', 'badge', '{"topic_mastery":true}'],
        ];

        $stmt = $db->prepare(
            'INSERT INTO gamification_badges (badge_key, title, description, icon, criteria_json, enabled)
             VALUES (?,?,?,?,?,1)'
        );
        foreach ($badges as $badge) {
            $stmt->execute($badge);
        }
    }
}

if (!function_exists('portal_activity_seed_templates')) {
    function portal_activity_seed_templates(PDO $db): void
    {
        $count = (int) $db->query('SELECT COUNT(*) FROM activity_templates')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $templates = [
            [
                'five_question_quiz',
                'Five-question quick quiz',
                'A short formative quiz with five single-choice questions.',
                'quiz',
                [
                    'settings' => [
                        'max_attempts' => 2,
                        'feedback_policy' => 'after_submission',
                        'xp_enabled' => 1,
                        'xp_amount' => 25,
                    ],
                    'questions' => array_map(
                        static fn(int $i): array => [
                            'question_type' => 'single_choice',
                            'prompt_html' => '<p>Question ' . $i . '</p>',
                            'points' => 1,
                            'options' => [
                                ['option_text_html' => '', 'is_correct' => 1, 'credit' => 1],
                                ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                                ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                                ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                            ],
                        ],
                        range(1, 5)
                    ),
                ],
            ],
            [
                'timed_assessment',
                'Timed assessment',
                'Formal assessment with a server-side timer and limited attempts.',
                'assessment',
                [
                    'settings' => [
                        'time_limit_seconds' => 3600,
                        'max_attempts' => 1,
                        'integrity_enabled' => 1,
                        'focus_monitoring' => 1,
                        'paste_policy' => 'allow_log',
                        'include_in_gradebook' => 1,
                        'feedback_policy' => 'when_released',
                    ],
                    'questions' => [],
                ],
            ],
            [
                'practice_instant',
                'Practice with instant feedback',
                'Unlimited practice with feedback after each question.',
                'practice',
                [
                    'settings' => [
                        'max_attempts' => 0,
                        'feedback_policy' => 'after_each',
                        'xp_enabled' => 1,
                        'xp_amount' => 10,
                    ],
                    'questions' => [],
                ],
            ],
            [
                'exit_ticket',
                'Exit ticket',
                'A short end-of-lesson check with a few questions.',
                'quiz',
                [
                    'settings' => [
                        'max_attempts' => 1,
                        'estimated_minutes' => 5,
                        'feedback_policy' => 'after_submission',
                    ],
                    'questions' => [
                        [
                            'question_type' => 'short_text',
                            'prompt_html' => '<p>What is one thing you learned today?</p>',
                            'points' => 1,
                            'manual_marking' => 0,
                        ],
                        [
                            'question_type' => 'rating_scale',
                            'prompt_html' => '<p>How confident do you feel about today\'s topic?</p>',
                            'points' => 0,
                            'settings' => ['min' => 1, 'max' => 5],
                        ],
                    ],
                ],
            ],
            [
                'student_survey',
                'Student survey',
                'Anonymous-style survey / poll with rating and open responses.',
                'survey',
                [
                    'settings' => [
                        'max_attempts' => 1,
                        'xp_enabled' => 0,
                        'include_in_gradebook' => 0,
                        'feedback_policy' => 'never',
                    ],
                    'questions' => [
                        [
                            'question_type' => 'rating_scale',
                            'prompt_html' => '<p>How useful was this lesson?</p>',
                            'points' => 0,
                            'settings' => ['min' => 1, 'max' => 5],
                        ],
                        [
                            'question_type' => 'long_response',
                            'prompt_html' => '<p>Any comments?</p>',
                            'points' => 0,
                            'manual_marking' => 0,
                        ],
                    ],
                ],
            ],
            [
                'revision_challenge',
                'Revision challenge',
                'Game-like challenge mode for revision with streaks and XP.',
                'challenge',
                [
                    'settings' => [
                        'time_limit_seconds' => 900,
                        'max_attempts' => 3,
                        'xp_enabled' => 1,
                        'xp_amount' => 40,
                        'feedback_policy' => 'after_submission',
                    ],
                    'questions' => [],
                ],
            ],
        ];

        $stmt = $db->prepare(
            'INSERT INTO activity_templates (template_key, title, description, mode, definition_json, enabled)
             VALUES (?,?,?,?,?,1)'
        );
        foreach ($templates as $t) {
            $stmt->execute([
                $t[0],
                $t[1],
                $t[2],
                $t[3],
                portal_activity_json_encode($t[4]),
            ]);
        }
    }
}

// ── Lookup helpers ────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_find')) {
    function portal_activity_find(int $activityId): ?array
    {
        if ($activityId <= 0) {
            return null;
        }
        $stmt = portal_db()->prepare('SELECT * FROM course_activities WHERE id = ?');
        $stmt->execute([$activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('portal_activity_find_by_item')) {
    function portal_activity_find_by_item(int $itemId): ?array
    {
        if ($itemId <= 0) {
            return null;
        }
        $stmt = portal_db()->prepare('SELECT * FROM course_activities WHERE course_item_id = ?');
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('portal_flashcard_decks_for_user')) {
    /**
     * Published flashcard activities across courses the user can access.
     *
     * @return list<array<string, mixed>>
     */
    function portal_flashcard_decks_for_user(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        if (!function_exists('portal_user_course_catalog')) {
            $catalogFile = __DIR__ . '/course_catalog.php';
            if (is_file($catalogFile)) {
                require_once $catalogFile;
            }
        }
        if (!function_exists('portal_user_course_catalog')) {
            return [];
        }

        $catalog = portal_user_course_catalog($userId);
        if ($catalog === []) {
            return [];
        }

        $courseIds = [];
        $courseTitles = [];
        foreach ($catalog as $course) {
            $cid = (int) ($course['id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $courseIds[] = $cid;
            $courseTitles[$cid] = (string) ($course['title'] ?? $course['name'] ?? ('Course ' . $cid));
        }
        if ($courseIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $db = portal_db();
        $stmt = $db->prepare(
            "SELECT a.id, a.title, a.course_id, a.course_item_id, a.short_description, a.updated_at, a.published_at,
                    (
                        SELECT COUNT(*)
                        FROM activity_questions q
                        INNER JOIN activity_versions v ON v.id = q.activity_version_id
                        WHERE v.activity_id = a.id AND v.status = 'published' AND q.question_type = 'flashcard'
                    ) AS card_count
             FROM course_activities a
             WHERE a.mode = 'flashcard'
               AND a.status = 'published'
               AND COALESCE(a.is_pre_enroll, 0) = 0
               AND a.course_id IN ($placeholders)
             ORDER BY a.title COLLATE NOCASE ASC"
        );
        $stmt->execute($courseIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $decks = [];
        foreach ($rows as $row) {
            $cid = (int) $row['course_id'];
            $decks[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'course_id' => $cid,
                'course_item_id' => (int) $row['course_item_id'],
                'course_title' => $courseTitles[$cid] ?? ('Course ' . $cid),
                'short_description' => (string) ($row['short_description'] ?? ''),
                'card_count' => (int) ($row['card_count'] ?? 0),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'published_at' => (string) ($row['published_at'] ?? ''),
                'can_manage' => portal_can_manage_course($cid),
            ];
        }

        return $decks;
    }
}

if (!function_exists('portal_activity_assert_belongs')) {
    function portal_activity_assert_belongs(array $activity, int $courseId): bool
    {
        return (int) ($activity['course_id'] ?? 0) === $courseId;
    }
}

if (!function_exists('portal_activity_require_course_access')) {
    function portal_activity_require_course_access(array $activity): void
    {
        $courseId = (int) ($activity['course_id'] ?? 0);
        if ($courseId <= 0 || !portal_can_access_course($courseId)) {
            if (function_exists('portal_is_fetch_request') && portal_is_fetch_request()) {
                portal_activity_json_error('You do not have access to this activity.', 403);
            }
            portal_store_intended_path();
            portal_redirect('login.php');
        }
    }
}

if (!function_exists('portal_activity_require_manage')) {
    function portal_activity_require_manage(array $activity): void
    {
        $courseId = (int) ($activity['course_id'] ?? 0);
        if ($courseId <= 0 || !portal_can_manage_course($courseId)) {
            portal_log_security_event(
                'activity_manage_denied',
                'medium',
                'activity_id=' . (int) ($activity['id'] ?? 0)
            );
            if (function_exists('portal_is_fetch_request') && portal_is_fetch_request()) {
                portal_activity_json_error('You cannot manage this activity.', 403);
            }
            portal_redirect('dashboard.php');
        }
    }
}

if (!function_exists('portal_activity_draft_version_id')) {
    function portal_activity_draft_version_id(int $activityId): ?int
    {
        $stmt = portal_db()->prepare(
            "SELECT id FROM activity_versions
             WHERE activity_id = ? AND status = 'draft'
             ORDER BY version_number DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$activityId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}

if (!function_exists('portal_activity_published_version_id')) {
    function portal_activity_published_version_id(int $activityId): ?int
    {
        $stmt = portal_db()->prepare(
            "SELECT id FROM activity_versions
             WHERE activity_id = ? AND status = 'published'
             ORDER BY version_number DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$activityId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}

if (!function_exists('portal_activity_feedback_visible')) {
    function portal_activity_feedback_visible(array $activity, ?array $attempt = null): bool
    {
        $policy = (string) ($activity['feedback_policy'] ?? 'after_submission');
        if ($policy === 'never') {
            return false;
        }
        if ($policy === 'when_released') {
            return !empty($activity['results_released'])
                || ($attempt !== null && ($attempt['status'] ?? '') === 'released');
        }
        if ($policy === 'after_close') {
            $closes = trim((string) ($activity['closes_at'] ?? ''));
            if ($closes === '') {
                return false;
            }
            $ts = portal_db_timestamp($closes) ?? strtotime($closes);
            return $ts !== false && $ts !== null && time() >= (int) $ts;
        }
        if ($attempt === null) {
            return false;
        }
        $status = (string) ($attempt['status'] ?? '');
        return in_array($status, ['submitted', 'auto_submitted', 'awaiting_manual_marking', 'marked', 'released'], true)
            || $policy === 'after_each';
    }
}

if (!function_exists('portal_activity_strip_inline_images')) {
    function portal_activity_strip_inline_images(string $html): string
    {
        if ($html === '') {
            return '';
        }
        return (string) preg_replace('#<img\b[^>]*>#i', '', $html);
    }
}

if (!function_exists('portal_activity_load_version_tree')) {
    /**
     * @return array{sections: list<array>, questions: list<array>, options_by_question: array<int, list<array>>}
     */
    function portal_activity_load_version_tree(int $versionId, bool $includeCorrectAnswers): array
    {
        $db = portal_db();

        $secStmt = $db->prepare(
            'SELECT * FROM activity_sections WHERE activity_version_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $secStmt->execute([$versionId]);
        $sections = $secStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $qStmt = $db->prepare(
            'SELECT * FROM activity_questions WHERE activity_version_id = ? ORDER BY sort_order ASC, id ASC'
        );
        $qStmt->execute([$versionId]);
        $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($questions as &$q) {
            $q['prompt_html'] = portal_activity_strip_inline_images((string) ($q['prompt_html'] ?? ''));
            $q['explanation_html'] = portal_activity_strip_inline_images((string) ($q['explanation_html'] ?? ''));
        }
        unset($q);

        $optionsByQuestion = [];
        if ($questions !== []) {
            $ids = array_map(static fn(array $q): int => (int) $q['id'], $questions);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $oStmt = $db->prepare(
                "SELECT * FROM activity_question_options
                 WHERE question_id IN ($placeholders)
                 ORDER BY sort_order ASC, id ASC"
            );
            $oStmt->execute($ids);
            foreach ($oStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                $qid = (int) $opt['question_id'];
                if (!$includeCorrectAnswers) {
                    unset($opt['is_correct'], $opt['credit'], $opt['feedback_html'], $opt['match_key']);
                }
                $optionsByQuestion[$qid][] = $opt;
            }
        }

        if (!$includeCorrectAnswers) {
            foreach ($questions as &$q) {
                unset($q['teacher_notes']);
                $settings = portal_activity_json_decode((string) ($q['settings_json'] ?? '{}'), []);
                if (is_array($settings)) {
                    // Matching: give students the prompts + answer pool, but not the key.
                    if (($q['question_type'] ?? '') === 'matching' && is_array($settings['pairs'] ?? null)) {
                        $lefts = [];
                        $rights = [];
                        foreach ($settings['pairs'] as $pair) {
                            if (!is_array($pair)) {
                                continue;
                            }
                            $left = trim((string) ($pair['left'] ?? $pair[0] ?? ''));
                            $right = trim((string) ($pair['right'] ?? $pair[1] ?? ''));
                            if ($left !== '' && $right !== '') {
                                $lefts[] = $left;
                                $rights[] = $right;
                            }
                        }
                        $settings['match_lefts'] = $lefts;
                        $settings['match_choices'] = $rights;
                    }
                    foreach ([
                        'accepted_answers', 'correct_value', 'correct_order', 'pairs', 'blanks', 'answer_key',
                        'expected_answer', 'keywords',
                    ] as $key) {
                        unset($settings[$key]);
                    }
                    $q['settings_json'] = portal_activity_json_encode($settings);
                }
                $q['explanation_html'] = '';
            }
            unset($q);
        }

        $mediaByQuestion = [];
        if ($questions !== []) {
            $mediaByQuestion = portal_activity_media_for_questions(
                array_map(static fn(array $q): int => (int) $q['id'], $questions)
            );
            foreach ($questions as &$q) {
                $q['media'] = $mediaByQuestion[(int) $q['id']] ?? [];
            }
            unset($q);
        }

        return [
            'sections' => $sections,
            'questions' => $questions,
            'options_by_question' => $optionsByQuestion,
            'media_by_question' => $mediaByQuestion,
        ];
    }
}

// ── Audit / create / mutate ───────────────────────────────────────────────────

if (!function_exists('portal_activity_audit')) {
    function portal_activity_audit(int $activityId, string $action, array $details = []): void
    {
        $user = portal_current_user();
        $activity = portal_activity_find($activityId);
        $safe = $details;
        unset($safe['token'], $safe['session_token'], $safe['answer'], $safe['answers'], $safe['clipboard']);

        portal_db()->prepare(
            'INSERT INTO activity_audit_events
                (activity_id, activity_version_id, course_id, user_id, action, details_json)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $activityId,
            $details['activity_version_id'] ?? portal_activity_draft_version_id($activityId),
            (int) ($activity['course_id'] ?? 0),
            (int) ($user['id'] ?? 0) ?: null,
            substr($action, 0, 80),
            portal_activity_json_encode($safe),
        ]);
    }
}

if (!function_exists('portal_activity_create')) {
    function portal_activity_create(int $courseId, int $folderId, string $title, string $mode, int $userId): array
    {
        if (!portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'You cannot manage this course.'];
        }

        $title = trim($title);
        if ($title === '') {
            return ['ok' => false, 'error' => 'Title is required.'];
        }
        if (!in_array($mode, portal_activity_modes(), true)) {
            return ['ok' => false, 'error' => 'Invalid activity mode.'];
        }

        $db = portal_db();
        $folderChk = $db->prepare('SELECT id FROM course_folders WHERE id = ? AND course_id = ?');
        $folderChk->execute([$folderId, $courseId]);
        if (!$folderChk->fetchColumn()) {
            return ['ok' => false, 'error' => 'Folder not found.'];
        }

        $maxOrd = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM course_folder_items WHERE folder_id = ?');
        $maxOrd->execute([$folderId]);
        $sortOrder = (int) $maxOrd->fetchColumn() + 1;

        $integrityDefault = $mode === 'assessment' ? 1 : 0;
        $feedbackDefault = match ($mode) {
            'practice', 'flashcard' => 'after_each',
            'assessment' => 'when_released',
            'survey' => 'never',
            default => 'after_submission',
        };
        $includeGradebook = 0;

        $db->beginTransaction();
        try {
            $db->prepare(
                "INSERT INTO course_folder_items
                    (folder_id, course_id, type, title, description, sort_order)
                 VALUES (?,?, 'activity', ?, '', ?)"
            )->execute([$folderId, $courseId, $title, $sortOrder]);
            $itemId = (int) $db->lastInsertId();

            $db->prepare(
                "INSERT INTO course_activities
                    (course_item_id, course_id, mode, status, title, feedback_policy,
                     integrity_enabled, focus_monitoring, include_in_gradebook,
                     created_by, updated_by)
                 VALUES (?,?,?,'draft',?,?,?,?,?,?,?)"
            )->execute([
                $itemId,
                $courseId,
                $mode,
                $title,
                $feedbackDefault,
                $integrityDefault,
                $integrityDefault,
                $includeGradebook,
                $userId,
                $userId,
            ]);
            $activityId = (int) $db->lastInsertId();

            $snapshot = [
                'mode' => $mode,
                'title' => $title,
                'feedback_policy' => $feedbackDefault,
            ];
            $db->prepare(
                "INSERT INTO activity_versions
                    (activity_id, version_number, status, settings_snapshot_json, created_by)
                 VALUES (?, 1, 'draft', ?, ?)"
            )->execute([$activityId, portal_activity_json_encode($snapshot), $userId]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            portal_log_security_event('activity_create_failed', 'medium', $e->getMessage());
            return ['ok' => false, 'error' => 'The activity could not be created.'];
        }

        portal_activity_audit($activityId, 'created', ['mode' => $mode, 'title' => $title]);

        $activity = portal_activity_find($activityId);
        return [
            'ok' => true,
            'activity' => $activity,
            'activity_id' => $activityId,
            'item_id' => $itemId,
            'draft_version_id' => portal_activity_draft_version_id($activityId),
        ];
    }
}

if (!function_exists('portal_pre_enroll_hidden_folder_id')) {
    function portal_pre_enroll_hidden_folder_id(int $courseId): int
    {
        if ($courseId <= 0) {
            return 0;
        }
        $db = portal_db();
        $stmt = $db->prepare(
            'SELECT id FROM course_folders WHERE course_id = ? AND is_pre_enroll = 1 LIMIT 1'
        );
        $stmt->execute([$courseId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        $db->prepare(
            "INSERT INTO course_folders (course_id, title, description, locked, is_pre_enroll, sort_order)
             VALUES (?, 'Pre-enrolment quiz', 'Hidden knowledge check shown the first time a student opens this module.', 0, 1, 9999)"
        )->execute([$courseId]);

        return (int) $db->lastInsertId();
    }
}

if (!function_exists('portal_pre_enroll_activity')) {
    /** @param array<string, mixed> $course */
    function portal_pre_enroll_activity(array $course): ?array
    {
        $id = (int) ($course['pre_enroll_quiz_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $activity = portal_activity_find($id);
        if (!$activity || (int) ($activity['course_id'] ?? 0) !== (int) ($course['id'] ?? 0)) {
            return null;
        }

        return $activity;
    }
}

if (!function_exists('portal_pre_enroll_student_completed')) {
    function portal_pre_enroll_student_completed(int $activityId, int $userId): bool
    {
        if ($activityId <= 0 || $userId <= 0) {
            return false;
        }
        $stmt = portal_db()->prepare(
            "SELECT 1 FROM activity_attempts
             WHERE activity_id = ? AND user_id = ?
               AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')
             LIMIT 1"
        );
        $stmt->execute([$activityId, $userId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('portal_pre_enroll_is_active')) {
    /** @param array<string, mixed>|null $activity */
    function portal_pre_enroll_is_active(?array $activity): bool
    {
        if (!$activity || (int) ($activity['is_pre_enroll'] ?? 0) !== 1) {
            return false;
        }
        if (($activity['status'] ?? '') !== 'published') {
            return false;
        }
        $versionId = portal_activity_published_version_id((int) $activity['id']);
        if ($versionId === null) {
            return false;
        }
        $countStmt = portal_db()->prepare(
            'SELECT COUNT(*) FROM activity_questions WHERE activity_version_id = ?'
        );
        $countStmt->execute([$versionId]);

        return (int) $countStmt->fetchColumn() > 0;
    }
}

if (!function_exists('portal_pre_enroll_blocks_student')) {
    /** @param array<string, mixed> $course */
    function portal_pre_enroll_blocks_student(array $course, int $userId): bool
    {
        $activity = portal_pre_enroll_activity($course);
        if (!portal_pre_enroll_is_active($activity)) {
            return false;
        }

        return !portal_pre_enroll_student_completed((int) $activity['id'], $userId);
    }
}

if (!function_exists('portal_pre_enroll_create_or_open')) {
    /** @return array{ok:bool, activity_id?:int, error?:string} */
    function portal_pre_enroll_create_or_open(int $courseId, int $userId): array
    {
        if (!portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'You cannot manage this course.'];
        }

        $db = portal_db();
        $existing = $db->prepare(
            'SELECT id FROM course_activities WHERE course_id = ? AND is_pre_enroll = 1 ORDER BY id DESC LIMIT 1'
        );
        $existing->execute([$courseId]);
        $activityId = (int) ($existing->fetchColumn() ?: 0);

        if ($activityId <= 0) {
            $folderId = portal_pre_enroll_hidden_folder_id($courseId);
            if ($folderId <= 0) {
                return ['ok' => false, 'error' => 'Could not create the quiz folder.'];
            }
            $created = portal_activity_create($courseId, $folderId, 'Pre-enrolment quiz', 'quiz', $userId);
            if (empty($created['ok'])) {
                return ['ok' => false, 'error' => (string) ($created['error'] ?? 'Could not create the quiz.')];
            }
            $activityId = (int) ($created['activity_id'] ?? 0);
            $db->prepare(
                "UPDATE course_activities
                 SET is_pre_enroll = 1,
                     include_in_gradebook = 0,
                     short_description = ?
                 WHERE id = ?"
            )->execute([
                'A short knowledge check before students start this module. It is not part of the course grade unless you add it to the gradebook.',
                $activityId,
            ]);
        }

        if ($activityId <= 0) {
            return ['ok' => false, 'error' => 'Could not create the quiz.'];
        }

        $db->prepare('UPDATE courses SET pre_enroll_quiz_id = ? WHERE id = ?')->execute([$activityId, $courseId]);

        return ['ok' => true, 'activity_id' => $activityId];
    }
}

if (!function_exists('portal_pre_enroll_disable')) {
    function portal_pre_enroll_disable(int $courseId): bool
    {
        if (!portal_can_manage_course($courseId)) {
            return false;
        }
        $db = portal_db();
        $db->prepare('UPDATE courses SET pre_enroll_quiz_id = 0 WHERE id = ?')->execute([$courseId]);
        $db->prepare(
            "UPDATE course_activities
             SET is_pre_enroll = 0, status = CASE WHEN status = 'published' THEN 'archived' ELSE status END
             WHERE course_id = ? AND is_pre_enroll = 1"
        )->execute([$courseId]);

        return true;
    }
}

if (!function_exists('portal_activity_settings_fields')) {
    /** @return list<string> */
    function portal_activity_settings_fields(): array
    {
        return [
            'title', 'short_description', 'instructions_html', 'mode',
            'opens_at', 'closes_at', 'due_at', 'estimated_minutes', 'time_limit_seconds',
            'max_attempts', 'pass_mark', 'feedback_policy', 'navigation_policy',
            'shuffle_questions', 'shuffle_options', 'questions_per_attempt',
            'paste_policy', 'copy_policy', 'integrity_enabled', 'focus_monitoring',
            'fullscreen_policy', 'include_in_gradebook', 'grade_weight',
            'xp_enabled', 'xp_amount', 'leaderboard_enabled', 'results_released',
        ];
    }
}

if (!function_exists('portal_activity_save_settings')) {
    function portal_activity_save_settings(int $activityId, array $fields, int $expectedRevision): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.', 'revision' => 0];
        }
        portal_activity_require_manage($activity);

        $current = (int) ($activity['version'] ?? 1);
        if ($expectedRevision !== $current) {
            return [
                'ok' => false,
                'error' => 'A newer version was saved by another user.',
                'revision' => $current,
                'conflict' => true,
            ];
        }

        $allowed = portal_activity_settings_fields();
        $updates = [];
        $params = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $value = $fields[$key];
            if (in_array($key, ['title', 'short_description', 'opens_at', 'closes_at', 'due_at', 'feedback_policy', 'navigation_policy', 'paste_policy', 'copy_policy', 'fullscreen_policy', 'mode'], true)) {
                $value = trim((string) $value);
            }
            if ($key === 'instructions_html') {
                $value = portal_sanitize_rich_text((string) $value);
            }
            if ($key === 'mode' && !in_array($value, portal_activity_modes(), true)) {
                return ['ok' => false, 'error' => 'Invalid mode.', 'revision' => $current];
            }
            if ($key === 'title' && $value === '') {
                return ['ok' => false, 'error' => 'Title is required.', 'revision' => $current];
            }
            if (in_array($key, ['estimated_minutes', 'time_limit_seconds', 'max_attempts', 'questions_per_attempt', 'shuffle_questions', 'shuffle_options', 'integrity_enabled', 'focus_monitoring', 'include_in_gradebook', 'xp_enabled', 'xp_amount', 'leaderboard_enabled', 'results_released'], true)) {
                $value = (int) $value;
            }
            if (in_array($key, ['pass_mark', 'grade_weight'], true)) {
                $value = $value === null || $value === '' ? null : (float) $value;
            }
            if (in_array($key, ['estimated_minutes', 'time_limit_seconds', 'max_attempts', 'questions_per_attempt', 'xp_amount'], true) && $value < 0) {
                return ['ok' => false, 'error' => ucfirst(str_replace('_', ' ', $key)) . ' cannot be negative.', 'revision' => $current];
            }
            if ($key === 'xp_amount' && $value > 1000) {
                return ['ok' => false, 'error' => 'XP reward cannot exceed 1000.', 'revision' => $current];
            }
            if (in_array($key, ['pass_mark', 'grade_weight'], true) && $value !== null && ($value < 0 || $value > 100)) {
                return ['ok' => false, 'error' => ucfirst(str_replace('_', ' ', $key)) . ' must be between 0 and 100.', 'revision' => $current];
            }
            $allowedValues = [
                'feedback_policy' => ['after_each', 'after_submission', 'after_close', 'when_released', 'never'],
                'navigation_policy' => ['free', 'sequential', 'no_return'],
                'paste_policy' => ['allow', 'allow_log', 'block_log'],
                'copy_policy' => ['allow', 'log', 'block_log'],
                'fullscreen_policy' => ['off', 'optional', 'required'],
            ];
            if (isset($allowedValues[$key]) && !in_array($value, $allowedValues[$key], true)) {
                return ['ok' => false, 'error' => 'Invalid ' . str_replace('_', ' ', $key) . '.', 'revision' => $current];
            }
            $updates[] = "$key = ?";
            $params[] = $value;
        }

        // Course gradebook weights (activities + submission slots) must not exceed 100%.
        $nextInclude = array_key_exists('include_in_gradebook', $fields)
            ? (int) $fields['include_in_gradebook']
            : (int) ($activity['include_in_gradebook'] ?? 0);
        $nextWeight = array_key_exists('grade_weight', $fields)
            ? (float) ($fields['grade_weight'] ?? 0)
            : (float) ($activity['grade_weight'] ?? 100);
        if ($nextInclude === 1) {
            $fit = portal_course_gradebook_weight_fits(
                (int) ($activity['course_id'] ?? 0),
                $nextWeight,
                ['exclude_activity_id' => $activityId]
            );
            if (empty($fit['ok'])) {
                return [
                    'ok' => false,
                    'error' => (string) ($fit['error'] ?? 'Gradebook weights cannot exceed 100%.'),
                    'revision' => $current,
                ];
            }
        }

        if ($updates === []) {
            return ['ok' => true, 'revision' => $current, 'activity' => $activity];
        }

        $newRevision = $current + 1;
        $updates[] = 'version = ?';
        $params[] = $newRevision;
        $updates[] = "updated_at = datetime('now')";
        $user = portal_current_user();
        $updates[] = 'updated_by = ?';
        $params[] = (int) ($user['id'] ?? 0) ?: null;
        $params[] = $activityId;
        $params[] = $current;

        $sql = 'UPDATE course_activities SET ' . implode(', ', $updates)
            . ' WHERE id = ? AND version = ?';
        $stmt = portal_db()->prepare($sql);
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) {
            $fresh = portal_activity_find($activityId);
            return [
                'ok' => false,
                'error' => 'A newer version was saved by another user.',
                'revision' => (int) ($fresh['version'] ?? $current),
                'conflict' => true,
            ];
        }

        if (isset($fields['title'])) {
            portal_db()->prepare(
                'UPDATE course_folder_items SET title = ? WHERE id = ?'
            )->execute([trim((string) $fields['title']), (int) $activity['course_item_id']]);
        }

        $draftId = portal_activity_draft_version_id($activityId);
        if ($draftId !== null) {
            $fresh = portal_activity_find($activityId);
            portal_db()->prepare(
                'UPDATE activity_versions SET settings_snapshot_json = ? WHERE id = ?'
            )->execute([portal_activity_json_encode($fresh), $draftId]);
        }

        portal_activity_audit($activityId, 'settings_saved', ['revision' => $newRevision]);

        return [
            'ok' => true,
            'revision' => $newRevision,
            'activity' => portal_activity_find($activityId),
        ];
    }
}

if (!function_exists('portal_activity_validate_for_publish')) {
    function portal_activity_validate_for_publish(int $activityId): array
    {
        $errors = [];
        $warnings = [];
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['errors' => ['Activity not found.'], 'warnings' => []];
        }

        $draftId = portal_activity_draft_version_id($activityId);
        if ($draftId === null) {
            return ['errors' => ['No draft version to publish.'], 'warnings' => []];
        }

        $tree = portal_activity_load_version_tree($draftId, true);
        if ($tree['questions'] === []) {
            $errors[] = ($activity['mode'] ?? '') === 'flashcard'
                ? 'Add at least one flashcard before publishing.'
                : 'Add at least one question before publishing.';
        }

        foreach ($tree['questions'] as $q) {
            $qid = (int) $q['id'];
            $type = (string) $q['question_type'];
            $prompt = trim(strip_tags((string) $q['prompt_html']));
            if ($prompt === '') {
                $errors[] = $type === 'flashcard'
                    ? 'A flashcard is missing its front side.'
                    : 'A question is missing a prompt.';
            }
            $options = $tree['options_by_question'][$qid] ?? [];
            $settings = portal_activity_json_decode((string) ($q['settings_json'] ?? '{}'), []) ?: [];

            if ($type === 'flashcard') {
                $back = trim(strip_tags((string) ($settings['back'] ?? $q['explanation_html'] ?? '')));
                if ($back === '') {
                    $errors[] = 'A flashcard is missing its back side.';
                }
                continue;
            }

            if (in_array($type, ['single_choice', 'multiple_choice', 'true_false'], true)) {
                if (count($options) < 2) {
                    $errors[] = 'Choice questions need at least two options.';
                }
                $hasCorrect = false;
                $blankOptions = 0;
                foreach ($options as $opt) {
                    if (trim(strip_tags((string) ($opt['option_text_html'] ?? ''))) === '') {
                        $blankOptions++;
                    }
                    if (!empty($opt['is_correct']) || (float) ($opt['credit'] ?? 0) > 0) {
                        $hasCorrect = true;
                    }
                }
                if ($blankOptions > 0 && in_array($type, ['single_choice', 'multiple_choice'], true)) {
                    $errors[] = 'Fill in every answer option before publishing.';
                }
                if (!$hasCorrect && $activity['mode'] !== 'survey') {
                    $errors[] = 'This question needs a correct answer.';
                }
            }

            if ($type === 'short_text') {
                $accepted = $settings['accepted_answers'] ?? [];
                if ((!is_array($accepted) || $accepted === []) && empty($q['manual_marking']) && $activity['mode'] !== 'survey') {
                    $errors[] = 'Short text questions need accepted answers or manual marking.';
                }
            }

            if ($type === 'numeric' && !isset($settings['correct_value']) && empty($q['manual_marking']) && $activity['mode'] !== 'survey') {
                $errors[] = 'Numeric questions need a correct value or manual marking.';
            }

            if ($type === 'long_response' && empty($q['manual_marking']) && $activity['mode'] !== 'survey') {
                $errors[] = 'Written responses must be teacher-marked (they have no automatic correct answer).';
            }

            if ($type === 'ordering') {
                if (count($options) < 2) {
                    $errors[] = 'Ordering questions need at least two items.';
                }
                foreach ($options as $opt) {
                    if (trim(strip_tags((string) ($opt['option_text_html'] ?? ''))) === '') {
                        $errors[] = 'Fill in every ordering item before publishing.';
                        break;
                    }
                }
            }

            if ($type === 'matching') {
                $pairs = is_array($settings['pairs'] ?? null) ? $settings['pairs'] : [];
                $complete = 0;
                foreach ($pairs as $pair) {
                    if (!is_array($pair)) {
                        continue;
                    }
                    $left = trim((string) ($pair['left'] ?? $pair[0] ?? ''));
                    $right = trim((string) ($pair['right'] ?? $pair[1] ?? ''));
                    if ($left !== '' && $right !== '') {
                        $complete++;
                    }
                }
                if ($complete < 2) {
                    $errors[] = 'Matching questions need at least two complete pairs.';
                }
            }
        }

        if ((string) ($activity['title'] ?? '') === '') {
            $errors[] = 'Title is required.';
        }

        if ($activity['mode'] === 'assessment' && (int) ($activity['max_attempts'] ?? 0) === 0) {
            $warnings[] = 'Assessment has unlimited attempts.';
        }

        if ($activity['mode'] === 'assessment' && empty($activity['integrity_enabled'])) {
            $warnings[] = 'Integrity monitoring is off for this assessment.';
        }

        $opensAt = portal_db_timestamp((string) ($activity['opens_at'] ?? ''));
        $closesAt = portal_db_timestamp((string) ($activity['closes_at'] ?? ''));
        $dueAt = portal_db_timestamp((string) ($activity['due_at'] ?? ''));
        if ($opensAt && $closesAt && $closesAt <= $opensAt) {
            $errors[] = 'The closing time must be after the opening time.';
        }
        if ($opensAt && $dueAt && $dueAt < $opensAt) {
            $errors[] = 'The due time cannot be before the activity opens.';
        }
        if ($closesAt && $dueAt && $dueAt > $closesAt) {
            $warnings[] = 'The due time is after the activity closes.';
        }

        if (!empty($activity['xp_enabled']) && (int) ($activity['xp_amount'] ?? 0) <= 0) {
            $warnings[] = 'XP rewards are enabled but the reward amount is zero.';
        }
        if (!empty($activity['leaderboard_enabled']) && empty($activity['xp_enabled'])) {
            $warnings[] = 'The leaderboard is enabled, but this activity does not award XP.';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }
}

if (!function_exists('portal_activity_copy_version_tree')) {
    function portal_activity_copy_version_tree(int $sourceVersionId, int $targetVersionId): void
    {
        $db = portal_db();
        $tree = portal_activity_load_version_tree($sourceVersionId, true);
        $sectionMap = [];

        foreach ($tree['sections'] as $section) {
            $db->prepare(
                'INSERT INTO activity_sections
                    (activity_version_id, title, instructions_html, sort_order)
                 VALUES (?,?,?,?)'
            )->execute([
                $targetVersionId,
                $section['title'],
                $section['instructions_html'],
                (int) $section['sort_order'],
            ]);
            $sectionMap[(int) $section['id']] = (int) $db->lastInsertId();
        }

        foreach ($tree['questions'] as $q) {
            $oldQid = (int) $q['id'];
            $newSection = null;
            if (!empty($q['section_id']) && isset($sectionMap[(int) $q['section_id']])) {
                $newSection = $sectionMap[(int) $q['section_id']];
            }
            $db->prepare(
                'INSERT INTO activity_questions
                    (activity_version_id, section_id, question_type, prompt_html, explanation_html,
                     hint_html, teacher_notes, points, difficulty, tags, required, manual_marking,
                     settings_json, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $targetVersionId,
                $newSection,
                $q['question_type'],
                $q['prompt_html'],
                $q['explanation_html'],
                $q['hint_html'],
                $q['teacher_notes'],
                $q['points'],
                $q['difficulty'],
                $q['tags'],
                (int) $q['required'],
                (int) $q['manual_marking'],
                $q['settings_json'],
                (int) $q['sort_order'],
            ]);
            $newQid = (int) $db->lastInsertId();

            foreach ($tree['options_by_question'][$oldQid] ?? [] as $opt) {
                $db->prepare(
                    'INSERT INTO activity_question_options
                        (question_id, option_text_html, media_id, is_correct, credit,
                         feedback_html, match_key, pinned_position, sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $newQid,
                    $opt['option_text_html'],
                    $opt['media_id'] ?? null,
                    (int) ($opt['is_correct'] ?? 0),
                    (float) ($opt['credit'] ?? 0),
                    $opt['feedback_html'] ?? '',
                    $opt['match_key'] ?? '',
                    $opt['pinned_position'] ?? null,
                    (int) ($opt['sort_order'] ?? 0),
                ]);
            }

            $rubStmt = $db->prepare('SELECT * FROM activity_rubrics WHERE question_id = ?');
            $rubStmt->execute([$oldQid]);
            foreach ($rubStmt->fetchAll(PDO::FETCH_ASSOC) as $rubric) {
                $db->prepare(
                    'INSERT INTO activity_rubrics
                        (question_id, title, student_visible_before, student_visible_after, created_by)
                     VALUES (?,?,?,?,?)'
                )->execute([
                    $newQid,
                    $rubric['title'],
                    (int) $rubric['student_visible_before'],
                    (int) $rubric['student_visible_after'],
                    $rubric['created_by'],
                ]);
                $newRubricId = (int) $db->lastInsertId();
                $critStmt = $db->prepare('SELECT * FROM activity_rubric_criteria WHERE rubric_id = ? ORDER BY sort_order, id');
                $critStmt->execute([(int) $rubric['id']]);
                foreach ($critStmt->fetchAll(PDO::FETCH_ASSOC) as $crit) {
                    $db->prepare(
                        'INSERT INTO activity_rubric_criteria
                            (rubric_id, title, description, maximum_points, sort_order)
                         VALUES (?,?,?,?,?)'
                    )->execute([
                        $newRubricId,
                        $crit['title'],
                        $crit['description'],
                        $crit['maximum_points'],
                        (int) $crit['sort_order'],
                    ]);
                    $newCritId = (int) $db->lastInsertId();
                    $lvlStmt = $db->prepare('SELECT * FROM activity_rubric_levels WHERE criterion_id = ? ORDER BY sort_order, id');
                    $lvlStmt->execute([(int) $crit['id']]);
                    foreach ($lvlStmt->fetchAll(PDO::FETCH_ASSOC) as $lvl) {
                        $db->prepare(
                            'INSERT INTO activity_rubric_levels
                                (criterion_id, title, description, points, sort_order)
                             VALUES (?,?,?,?,?)'
                        )->execute([
                            $newCritId,
                            $lvl['title'],
                            $lvl['description'],
                            $lvl['points'],
                            (int) $lvl['sort_order'],
                        ]);
                    }
                }
            }
        }
    }
}

if (!function_exists('portal_activity_publish')) {
    function portal_activity_publish(int $activityId): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);

        $validation = portal_activity_validate_for_publish($activityId);
        if ($validation['errors'] !== []) {
            return [
                'ok' => false,
                'error' => 'The activity could not be published because issues remain.',
                'validation' => $validation,
            ];
        }

        $draftId = portal_activity_draft_version_id($activityId);
        if ($draftId === null) {
            return ['ok' => false, 'error' => 'No draft version found.'];
        }

        $db = portal_db();
        $user = portal_current_user();
        $userId = (int) ($user['id'] ?? 0);

        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE activity_versions SET status = 'superseded'
                 WHERE activity_id = ? AND status = 'published'"
            )->execute([$activityId]);

            $verStmt = $db->prepare('SELECT version_number FROM activity_versions WHERE id = ?');
            $verStmt->execute([$draftId]);
            $verNum = (int) $verStmt->fetchColumn();

            $db->prepare(
                "UPDATE activity_versions
                 SET status = 'published', published_at = datetime('now'),
                     settings_snapshot_json = ?, change_summary = 'Published'
                 WHERE id = ?"
            )->execute([portal_activity_json_encode($activity), $draftId]);

            $db->prepare(
                "UPDATE course_activities
                 SET status = 'published', published_at = datetime('now'),
                     updated_at = datetime('now'), updated_by = ?, version = version + 1
                 WHERE id = ?"
            )->execute([$userId ?: null, $activityId]);

            $newVerNum = $verNum + 1;
            $db->prepare(
                "INSERT INTO activity_versions
                    (activity_id, version_number, status, settings_snapshot_json, created_by, change_summary)
                 VALUES (?, ?, 'draft', ?, ?, 'Post-publish draft')"
            )->execute([
                $activityId,
                $newVerNum,
                portal_activity_json_encode(portal_activity_find($activityId)),
                $userId ?: null,
            ]);
            $newDraftId = (int) $db->lastInsertId();
            portal_activity_copy_version_tree($draftId, $newDraftId);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            portal_log_security_event('activity_publish_failed', 'medium', $e->getMessage());
            return ['ok' => false, 'error' => 'The activity could not be published.'];
        }

        portal_activity_audit($activityId, 'published', ['activity_version_id' => $draftId]);

        return [
            'ok' => true,
            'activity' => portal_activity_find($activityId),
            'published_version_id' => $draftId,
            'draft_version_id' => portal_activity_draft_version_id($activityId),
            'validation' => $validation,
        ];
    }
}

if (!function_exists('portal_activity_unpublish')) {
    function portal_activity_unpublish(int $activityId): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);

        portal_db()->prepare(
            "UPDATE course_activities
             SET status = 'draft', updated_at = datetime('now'), version = version + 1
             WHERE id = ?"
        )->execute([$activityId]);

        portal_activity_audit($activityId, 'unpublished');

        return ['ok' => true, 'activity' => portal_activity_find($activityId)];
    }
}

if (!function_exists('portal_activity_duplicate')) {
    function portal_activity_duplicate(int $activityId, ?int $targetCourseId = null): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);

        $sourceCourseId = (int) $activity['course_id'];
        $courseId = $targetCourseId ?? $sourceCourseId;
        if (!portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'You cannot copy to that course.'];
        }

        $db = portal_db();
        $itemStmt = $db->prepare('SELECT folder_id FROM course_folder_items WHERE id = ?');
        $itemStmt->execute([(int) $activity['course_item_id']]);
        $folderId = (int) $itemStmt->fetchColumn();

        if ($courseId !== $sourceCourseId) {
            $folderStmt = $db->prepare(
                'SELECT id FROM course_folders WHERE course_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1'
            );
            $folderStmt->execute([$courseId]);
            $folderId = (int) $folderStmt->fetchColumn();
            if ($folderId <= 0) {
                $db->prepare(
                    "INSERT INTO course_folders (course_id, title, description) VALUES (?, 'Materials', '')"
                )->execute([$courseId]);
                $folderId = (int) $db->lastInsertId();
            }
        }

        $user = portal_current_user();
        $userId = (int) ($user['id'] ?? 0);
        $title = rtrim((string) $activity['title']) . ' (copy)';

        $sourceVersionId = portal_activity_published_version_id($activityId)
            ?? portal_activity_draft_version_id($activityId);
        if ($sourceVersionId === null) {
            return ['ok' => false, 'error' => 'Nothing to copy.'];
        }

        $created = portal_activity_create($courseId, $folderId, $title, (string) $activity['mode'], $userId);
        if (empty($created['ok'])) {
            return $created;
        }

        $newId = (int) $created['activity_id'];
        $newDraftId = (int) $created['draft_version_id'];

        $copyFields = portal_activity_settings_fields();
        $payload = [];
        foreach ($copyFields as $field) {
            if ($field === 'title') {
                continue;
            }
            if (array_key_exists($field, $activity)) {
                $payload[$field] = $activity[$field];
            }
        }
        $fresh = portal_activity_find($newId);
        portal_activity_save_settings($newId, $payload, (int) ($fresh['version'] ?? 1));

        // Replace empty draft tree with copied content.
        $db->prepare('DELETE FROM activity_questions WHERE activity_version_id = ?')->execute([$newDraftId]);
        $db->prepare('DELETE FROM activity_sections WHERE activity_version_id = ?')->execute([$newDraftId]);
        portal_activity_copy_version_tree($sourceVersionId, $newDraftId);

        portal_activity_audit($newId, 'duplicated', ['source_activity_id' => $activityId]);

        return [
            'ok' => true,
            'activity' => portal_activity_find($newId),
            'activity_id' => $newId,
            'item_id' => (int) ($created['item_id'] ?? 0),
        ];
    }
}

// ── Question / section CRUD ───────────────────────────────────────────────────

if (!function_exists('portal_activity_require_draft_version')) {
    function portal_activity_require_draft_version(int $activityId): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);
        $draftId = portal_activity_draft_version_id($activityId);
        if ($draftId === null) {
            $user = portal_current_user();
            $max = portal_db()->prepare(
                'SELECT COALESCE(MAX(version_number), 0) FROM activity_versions WHERE activity_id = ?'
            );
            $max->execute([$activityId]);
            $next = (int) $max->fetchColumn() + 1;
            portal_db()->prepare(
                "INSERT INTO activity_versions
                    (activity_id, version_number, status, settings_snapshot_json, created_by)
                 VALUES (?, ?, 'draft', ?, ?)"
            )->execute([
                $activityId,
                $next,
                portal_activity_json_encode($activity),
                (int) ($user['id'] ?? 0) ?: null,
            ]);
            $draftId = (int) portal_db()->lastInsertId();
            $pub = portal_activity_published_version_id($activityId);
            if ($pub !== null) {
                portal_activity_copy_version_tree($pub, $draftId);
            }
        }

        return ['ok' => true, 'activity' => $activity, 'draft_version_id' => $draftId];
    }
}

if (!function_exists('portal_activity_add_section')) {
    function portal_activity_add_section(int $activityId, string $title = '', string $instructionsHtml = ''): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $max = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM activity_sections WHERE activity_version_id = ?');
        $max->execute([$draftId]);
        $order = (int) $max->fetchColumn() + 1;
        $db->prepare(
            'INSERT INTO activity_sections (activity_version_id, title, instructions_html, sort_order)
             VALUES (?,?,?,?)'
        )->execute([$draftId, trim($title), portal_sanitize_rich_text($instructionsHtml), $order]);
        $id = (int) $db->lastInsertId();
        portal_activity_audit($activityId, 'section_added', ['section_id' => $id]);
        return ['ok' => true, 'section_id' => $id];
    }
}

if (!function_exists('portal_activity_update_section')) {
    function portal_activity_update_section(int $activityId, int $sectionId, array $fields): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $chk = $db->prepare('SELECT id FROM activity_sections WHERE id = ? AND activity_version_id = ?');
        $chk->execute([$sectionId, $draftId]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'Section not found.'];
        }
        $sets = [];
        $params = [];
        if (array_key_exists('title', $fields)) {
            $sets[] = 'title = ?';
            $params[] = trim((string) $fields['title']);
        }
        if (array_key_exists('instructions_html', $fields)) {
            $sets[] = 'instructions_html = ?';
            $params[] = portal_sanitize_rich_text((string) $fields['instructions_html']);
        }
        if (array_key_exists('sort_order', $fields)) {
            $sets[] = 'sort_order = ?';
            $params[] = (int) $fields['sort_order'];
        }
        if ($sets === []) {
            return ['ok' => true, 'section_id' => $sectionId];
        }
        $sets[] = "updated_at = datetime('now')";
        $params[] = $sectionId;
        $db->prepare('UPDATE activity_sections SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        return ['ok' => true, 'section_id' => $sectionId];
    }
}

if (!function_exists('portal_activity_delete_section')) {
    function portal_activity_delete_section(int $activityId, int $sectionId): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $db->prepare(
            'UPDATE activity_questions SET section_id = NULL
             WHERE section_id = ? AND activity_version_id = ?'
        )->execute([$sectionId, $draftId]);
        $db->prepare(
            'DELETE FROM activity_sections WHERE id = ? AND activity_version_id = ?'
        )->execute([$sectionId, $draftId]);
        portal_activity_audit($activityId, 'section_deleted', ['section_id' => $sectionId]);
        return ['ok' => true];
    }
}

if (!function_exists('portal_activity_add_question')) {
    function portal_activity_add_question(
        int $activityId,
        string $questionType,
        string $promptHtml = '',
        ?int $sectionId = null,
        array $extra = []
    ): array {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        if (!in_array($questionType, portal_activity_question_types(), true)) {
            return ['ok' => false, 'error' => 'Invalid question type.'];
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $max = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM activity_questions WHERE activity_version_id = ?');
        $max->execute([$draftId]);
        $order = (int) $max->fetchColumn() + 1;

        $manual = !empty($extra['manual_marking']) ? 1 : 0;
        // Written responses are always teacher-marked — no auto “correct answer”.
        if ($questionType === 'long_response') {
            $manual = 1;
        }
        // Survey rating scales are ungraded by default.
        if ($questionType === 'rating_scale' && !array_key_exists('manual_marking', $extra)) {
            $manual = 0;
        }
        $points = array_key_exists('points', $extra) ? (float) $extra['points'] : 1.0;
        if ($questionType === 'rating_scale' && !array_key_exists('points', $extra)) {
            $points = 0.0;
        }
        if ($questionType === 'flashcard' && !array_key_exists('points', $extra)) {
            $points = 1.0;
        }
        $settings = $extra['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }
        if ($questionType === 'flashcard' && !isset($settings['back'])) {
            $settings['back'] = (string) ($extra['back'] ?? '');
        }

        $db->prepare(
            'INSERT INTO activity_questions
                (activity_version_id, section_id, question_type, prompt_html, explanation_html,
                 hint_html, teacher_notes, points, difficulty, tags, required, manual_marking,
                 settings_json, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $draftId,
            $sectionId,
            $questionType,
            portal_sanitize_rich_text($promptHtml),
            portal_sanitize_rich_text((string) ($extra['explanation_html'] ?? '')),
            portal_sanitize_rich_text((string) ($extra['hint_html'] ?? '')),
            substr((string) ($extra['teacher_notes'] ?? ''), 0, 5000),
            $points,
            (string) ($extra['difficulty'] ?? 'medium'),
            (string) ($extra['tags'] ?? ''),
            array_key_exists('required', $extra) ? (int) !empty($extra['required']) : 1,
            $manual,
            portal_activity_json_encode($settings),
            $order,
        ]);
        $qid = (int) $db->lastInsertId();

        $skipDefaultOptions = !empty($extra['skip_default_options']);
        $providedOptions = $extra['options'] ?? null;
        $hasProvidedOptions = is_array($providedOptions) && $providedOptions !== [];

        if ($questionType === 'true_false' && !$skipDefaultOptions) {
            $db->prepare(
                'INSERT INTO activity_question_options
                    (question_id, option_text_html, is_correct, credit, sort_order)
                 VALUES (?,?,?,?,?), (?,?,?,?,?)'
            )->execute([
                $qid, 'True', 1, 1, 0,
                $qid, 'False', 0, 0, 1,
            ]);
        } elseif (in_array($questionType, ['single_choice', 'multiple_choice'], true)) {
            // Keep empty option slots so the builder can show placeholders instead of filler text.
            $seed = $hasProvidedOptions ? $providedOptions : (
                $skipDefaultOptions ? [] : [
                    ['option_text_html' => '', 'is_correct' => 1, 'credit' => 1],
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                ]
            );
            foreach ($seed as $i => $opt) {
                if (is_array($opt)) {
                    portal_activity_add_option($activityId, $qid, $opt, (int) $i);
                }
            }
        } elseif ($questionType === 'ordering') {
            $seed = $hasProvidedOptions ? $providedOptions : (
                $skipDefaultOptions ? [] : [
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                    ['option_text_html' => '', 'is_correct' => 0, 'credit' => 0],
                ]
            );
            $orderIds = [];
            foreach ($seed as $i => $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $added = portal_activity_add_option($activityId, $qid, $opt, (int) $i);
                if (!empty($added['option_id'])) {
                    $orderIds[] = (int) $added['option_id'];
                }
            }
            if ($orderIds !== []) {
                $settings['correct_order'] = $orderIds;
                $db->prepare('UPDATE activity_questions SET settings_json = ? WHERE id = ?')
                    ->execute([portal_activity_json_encode($settings), $qid]);
            }
        } elseif ($questionType === 'short_text' && empty($settings['accepted_answers'])) {
            $settings['accepted_answers'] = [];
            $db->prepare('UPDATE activity_questions SET settings_json = ? WHERE id = ?')
                ->execute([portal_activity_json_encode($settings), $qid]);
        } elseif ($questionType === 'numeric' && !isset($settings['correct_value'])) {
            $settings['correct_value'] = 0;
            $settings['absolute_tolerance'] = 0;
            $db->prepare('UPDATE activity_questions SET settings_json = ? WHERE id = ?')
                ->execute([portal_activity_json_encode($settings), $qid]);
        } elseif ($questionType === 'matching' && empty($settings['pairs'])) {
            $settings['pairs'] = [
                ['left' => '', 'right' => ''],
                ['left' => '', 'right' => ''],
                ['left' => '', 'right' => ''],
            ];
            $db->prepare('UPDATE activity_questions SET settings_json = ? WHERE id = ?')
                ->execute([portal_activity_json_encode($settings), $qid]);
        }

        portal_activity_audit($activityId, 'question_added', ['question_id' => $qid, 'type' => $questionType]);
        return ['ok' => true, 'question_id' => $qid];
    }
}

if (!function_exists('portal_activity_add_option')) {
    function portal_activity_add_option(int $activityId, int $questionId, array $opt, ?int $sortOrder = null): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $chk = $db->prepare(
            'SELECT id FROM activity_questions WHERE id = ? AND activity_version_id = ?'
        );
        $chk->execute([$questionId, $draftId]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'Question not found.'];
        }
        if ($sortOrder === null) {
            $max = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM activity_question_options WHERE question_id = ?');
            $max->execute([$questionId]);
            $sortOrder = (int) $max->fetchColumn() + 1;
        }
        $db->prepare(
            'INSERT INTO activity_question_options
                (question_id, option_text_html, media_id, is_correct, credit, feedback_html, match_key, pinned_position, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $questionId,
            portal_sanitize_rich_text((string) ($opt['option_text_html'] ?? $opt['text'] ?? '')),
            $opt['media_id'] ?? null,
            !empty($opt['is_correct']) ? 1 : 0,
            (float) ($opt['credit'] ?? (!empty($opt['is_correct']) ? 1 : 0)),
            portal_sanitize_rich_text((string) ($opt['feedback_html'] ?? '')),
            (string) ($opt['match_key'] ?? ''),
            $opt['pinned_position'] ?? null,
            $sortOrder,
        ]);
        return ['ok' => true, 'option_id' => (int) $db->lastInsertId()];
    }
}

if (!function_exists('portal_activity_update_question')) {
    function portal_activity_update_question(int $activityId, int $questionId, array $fields): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $chk = $db->prepare('SELECT * FROM activity_questions WHERE id = ? AND activity_version_id = ?');
        $chk->execute([$questionId, $draftId]);
        $existingQ = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existingQ) {
            return ['ok' => false, 'error' => 'Question not found.'];
        }

        $map = [
            'prompt_html' => static fn($v) => portal_activity_strip_inline_images(portal_sanitize_rich_text((string) $v)),
            'explanation_html' => static fn($v) => portal_activity_strip_inline_images(portal_sanitize_rich_text((string) $v)),
            'hint_html' => static fn($v) => portal_activity_strip_inline_images(portal_sanitize_rich_text((string) $v)),
            'teacher_notes' => static fn($v) => substr((string) $v, 0, 5000),
            'points' => static fn($v) => (float) $v,
            'difficulty' => static fn($v) => (string) $v,
            'tags' => static fn($v) => (string) $v,
            'required' => static fn($v) => (int) !empty($v),
            'manual_marking' => static fn($v) => (int) !empty($v),
            'section_id' => static fn($v) => $v === null || $v === '' ? null : (int) $v,
            'sort_order' => static fn($v) => (int) $v,
            'question_type' => static fn($v) => (string) $v,
        ];
        $effectiveType = (string) ($fields['question_type'] ?? $existingQ['question_type'] ?? '');
        if ($effectiveType === 'long_response') {
            // Cannot be auto-marked — ignore any attempt to turn marking off.
            $fields['manual_marking'] = 1;
        }
        $sets = [];
        $params = [];
        foreach ($map as $key => $caster) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            if ($key === 'question_type' && !in_array((string) $fields[$key], portal_activity_question_types(), true)) {
                return ['ok' => false, 'error' => 'Invalid question type.'];
            }
            $sets[] = "$key = ?";
            $params[] = $caster($fields[$key]);
        }
        if (array_key_exists('settings', $fields) && is_array($fields['settings'])) {
            $sets[] = 'settings_json = ?';
            $params[] = portal_activity_json_encode($fields['settings']);
        } elseif (array_key_exists('settings_json', $fields)) {
            $sets[] = 'settings_json = ?';
            $params[] = is_string($fields['settings_json'])
                ? $fields['settings_json']
                : portal_activity_json_encode($fields['settings_json']);
        }
        if ($sets === []) {
            return ['ok' => true, 'question_id' => $questionId];
        }
        $sets[] = "updated_at = datetime('now')";
        $params[] = $questionId;
        $db->prepare('UPDATE activity_questions SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        if (isset($fields['options']) && is_array($fields['options'])) {
            $db->prepare('DELETE FROM activity_question_options WHERE question_id = ?')->execute([$questionId]);
            foreach (array_values($fields['options']) as $i => $opt) {
                if (is_array($opt)) {
                    portal_activity_add_option($activityId, $questionId, $opt, $i);
                }
            }
        }

        return ['ok' => true, 'question_id' => $questionId];
    }
}

if (!function_exists('portal_activity_delete_question')) {
    function portal_activity_delete_question(int $activityId, int $questionId): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $db->prepare(
            'DELETE FROM activity_questions WHERE id = ? AND activity_version_id = ?'
        )->execute([$questionId, $draftId]);
        portal_activity_audit($activityId, 'question_deleted', ['question_id' => $questionId]);
        return ['ok' => true];
    }
}

if (!function_exists('portal_activity_reorder_questions')) {
    function portal_activity_reorder_questions(int $activityId, array $questionIds): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $stmt = $db->prepare(
            'UPDATE activity_questions SET sort_order = ? WHERE id = ? AND activity_version_id = ?'
        );
        foreach (array_values($questionIds) as $i => $qid) {
            $stmt->execute([(int) $i, (int) $qid, $draftId]);
        }
        return ['ok' => true];
    }
}

if (!function_exists('portal_activity_duplicate_question')) {
    function portal_activity_duplicate_question(int $activityId, int $questionId): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_questions WHERE id = ? AND activity_version_id = ?');
        $stmt->execute([$questionId, $draftId]);
        $q = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$q) {
            return ['ok' => false, 'error' => 'Question not found.'];
        }

        $created = portal_activity_add_question(
            $activityId,
            (string) $q['question_type'],
            (string) $q['prompt_html'],
            $q['section_id'] !== null ? (int) $q['section_id'] : null,
            [
                'explanation_html' => $q['explanation_html'],
                'hint_html' => $q['hint_html'],
                'teacher_notes' => $q['teacher_notes'],
                'points' => $q['points'],
                'difficulty' => $q['difficulty'],
                'tags' => $q['tags'],
                'required' => $q['required'],
                'manual_marking' => $q['manual_marking'],
                'settings' => portal_activity_json_decode((string) $q['settings_json'], []),
                'skip_default_options' => true,
            ]
        );
        if (empty($created['ok'])) {
            return $created;
        }
        $newId = (int) $created['question_id'];
        $optStmt = $db->prepare('SELECT * FROM activity_question_options WHERE question_id = ? ORDER BY sort_order, id');
        $optStmt->execute([$questionId]);
        foreach ($optStmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
            portal_activity_add_option($activityId, $newId, $opt, (int) $opt['sort_order']);
        }
        return ['ok' => true, 'question_id' => $newId];
    }
}

if (!function_exists('portal_activity_save_rubric')) {
    function portal_activity_save_rubric(int $activityId, int $questionId, array $rubric): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $draftId = (int) $ctx['draft_version_id'];
        $db = portal_db();
        $chk = $db->prepare('SELECT id FROM activity_questions WHERE id = ? AND activity_version_id = ?');
        $chk->execute([$questionId, $draftId]);
        if (!$chk->fetchColumn()) {
            return ['ok' => false, 'error' => 'Question not found.'];
        }

        $existing = $db->prepare('SELECT id FROM activity_rubrics WHERE question_id = ? LIMIT 1');
        $existing->execute([$questionId]);
        $rubricId = $existing->fetchColumn();
        $user = portal_current_user();

        if ($rubricId) {
            $rubricId = (int) $rubricId;
            $db->prepare(
                "UPDATE activity_rubrics
                 SET title = ?, student_visible_before = ?, student_visible_after = ?,
                     updated_at = datetime('now')
                 WHERE id = ?"
            )->execute([
                trim((string) ($rubric['title'] ?? '')),
                !empty($rubric['student_visible_before']) ? 1 : 0,
                array_key_exists('student_visible_after', $rubric) ? (int) !empty($rubric['student_visible_after']) : 1,
                $rubricId,
            ]);
            $db->prepare(
                'DELETE FROM activity_rubric_levels WHERE criterion_id IN
                    (SELECT id FROM activity_rubric_criteria WHERE rubric_id = ?)'
            )->execute([$rubricId]);
            $db->prepare('DELETE FROM activity_rubric_criteria WHERE rubric_id = ?')->execute([$rubricId]);
        } else {
            $db->prepare(
                'INSERT INTO activity_rubrics
                    (question_id, title, student_visible_before, student_visible_after, created_by)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $questionId,
                trim((string) ($rubric['title'] ?? 'Rubric')),
                !empty($rubric['student_visible_before']) ? 1 : 0,
                array_key_exists('student_visible_after', $rubric) ? (int) !empty($rubric['student_visible_after']) : 1,
                (int) ($user['id'] ?? 0) ?: null,
            ]);
            $rubricId = (int) $db->lastInsertId();
        }

        foreach (array_values($rubric['criteria'] ?? []) as $ci => $crit) {
            if (!is_array($crit)) {
                continue;
            }
            $db->prepare(
                'INSERT INTO activity_rubric_criteria
                    (rubric_id, title, description, maximum_points, sort_order)
                 VALUES (?,?,?,?,?)'
            )->execute([
                $rubricId,
                trim((string) ($crit['title'] ?? '')),
                (string) ($crit['description'] ?? ''),
                (float) ($crit['maximum_points'] ?? 0),
                $ci,
            ]);
            $critId = (int) $db->lastInsertId();
            foreach (array_values($crit['levels'] ?? []) as $li => $lvl) {
                if (!is_array($lvl)) {
                    continue;
                }
                $db->prepare(
                    'INSERT INTO activity_rubric_levels
                        (criterion_id, title, description, points, sort_order)
                     VALUES (?,?,?,?,?)'
                )->execute([
                    $critId,
                    trim((string) ($lvl['title'] ?? '')),
                    (string) ($lvl['description'] ?? ''),
                    (float) ($lvl['points'] ?? 0),
                    $li,
                ]);
            }
        }

        return ['ok' => true, 'rubric_id' => $rubricId];
    }
}

// Alias expected name
if (!function_exists('portal_activity_update_rubric')) {
    function portal_activity_update_rubric(int $activityId, int $questionId, array $rubric): array
    {
        return portal_activity_save_rubric($activityId, $questionId, $rubric);
    }
}

// ── Scoring ───────────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_normalize_text')) {
    function portal_activity_normalize_text(string $text, bool $caseSensitive = false, bool $stripPunctuation = true): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if ($stripPunctuation) {
            $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;
            $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        }
        if (!$caseSensitive) {
            $text = mb_strtolower($text, 'UTF-8');
        }
        return $text;
    }
}

// ── Written-answer marking assistance ─────────────────────────────────────────
// These helpers never change a stored score on their own. They produce a
// suggestion a teacher can accept, so written work still needs a human decision
// before the grade leaves "pending".

if (!function_exists('portal_activity_text_stopwords')) {
    /** @return array<string, true> */
    function portal_activity_text_stopwords(): array
    {
        static $words = null;
        if ($words === null) {
            $words = array_fill_keys([
                'a', 'an', 'the', 'and', 'or', 'but', 'if', 'then', 'than', 'so', 'because', 'as', 'of', 'at',
                'by', 'for', 'with', 'about', 'into', 'through', 'during', 'to', 'from', 'in', 'on', 'off',
                'out', 'over', 'under', 'again', 'further', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
                'am', 'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having', 'i', 'you', 'he', 'she',
                'it', 'we', 'they', 'me', 'him', 'her', 'them', 'my', 'your', 'his', 'its', 'our', 'their',
                'this', 'that', 'these', 'those', 'there', 'here', 'what', 'which', 'who', 'whom', 'when',
                'where', 'why', 'how', 'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some',
                'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'too', 'very', 'can', 'will', 'just',
                'should', 'would', 'could', 'also', 'may', 'might', 'must', 'shall', 'get', 'got', 'thing',
            ], true);
        }
        return $words;
    }
}

if (!function_exists('portal_activity_text_stem')) {
    /**
     * Very light suffix trimming so "increases"/"increasing" match "increase".
     * Deliberately conservative — a wrong stem is worse than no stem here.
     */
    function portal_activity_text_stem(string $word): string
    {
        if (mb_strlen($word) < 5) {
            return $word;
        }
        foreach (['ies' => 'y', 'ing' => '', 'edly' => '', 'ed' => '', 'es' => '', 's' => ''] as $suffix => $replacement) {
            $len = mb_strlen($suffix);
            if (mb_substr($word, -$len) !== $suffix) {
                continue;
            }
            $stem = mb_substr($word, 0, mb_strlen($word) - $len) . $replacement;
            if (mb_strlen($stem) < 3) {
                return $word;
            }
            // "running" → "runn" → "run"
            $last = mb_substr($stem, -1);
            if ($replacement === '' && mb_strlen($stem) > 3 && $last === mb_substr($stem, -2, 1)
                && !in_array($last, ['a', 'e', 'i', 'o', 'u', 's', 'l'], true)) {
                $stem = mb_substr($stem, 0, mb_strlen($stem) - 1);
            }
            return $stem;
        }
        return $word;
    }
}

if (!function_exists('portal_activity_text_tokens')) {
    /** @return list<string> */
    function portal_activity_text_tokens(string $text, bool $keepStopwords = false): array
    {
        $normalized = portal_activity_normalize_text($text, false, true);
        if ($normalized === '') {
            return [];
        }
        $stop = portal_activity_text_stopwords();
        $tokens = [];
        foreach (preg_split('/\s+/u', $normalized) ?: [] as $part) {
            if ($part === '') {
                continue;
            }
            if (!$keepStopwords && isset($stop[$part])) {
                continue;
            }
            if (mb_strlen($part) < 2 && !ctype_digit($part)) {
                continue;
            }
            $tokens[] = portal_activity_text_stem($part);
        }
        return $tokens;
    }
}

if (!function_exists('portal_activity_text_bigram_overlap')) {
    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    function portal_activity_text_bigram_overlap(array $a, array $b): float
    {
        $pairs = static function (array $tokens): array {
            $out = [];
            $count = count($tokens);
            for ($i = 0; $i < $count - 1; $i++) {
                $out[$tokens[$i] . ' ' . $tokens[$i + 1]] = true;
            }
            return $out;
        };
        $left = $pairs($a);
        $right = $pairs($b);
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $shared = count(array_intersect_key($left, $right));
        return (2 * $shared) / (count($left) + count($right));
    }
}

if (!function_exists('portal_activity_text_similarity')) {
    /**
     * Blended similarity between an expected answer and a student's writing.
     * Returns 0..1. Recall of the expected wording carries the most weight, so
     * a long answer that covers the model answer still scores well.
     */
    function portal_activity_text_similarity(string $expected, string $given): float
    {
        $expectedTokens = portal_activity_text_tokens($expected);
        $givenTokens = portal_activity_text_tokens($given);
        if ($expectedTokens === [] || $givenTokens === []) {
            return 0.0;
        }

        $expectedSet = array_values(array_unique($expectedTokens));
        $givenSet = array_values(array_unique($givenTokens));
        $shared = count(array_intersect($expectedSet, $givenSet));

        $recall = $shared / count($expectedSet);
        $dice = (2 * $shared) / (count($expectedSet) + count($givenSet));
        $bigram = portal_activity_text_bigram_overlap($expectedTokens, $givenTokens);

        // Character similarity rescues typos and very short answers. similar_text
        // is expensive, so only compare a bounded slice.
        $expectedPlain = mb_substr(implode(' ', $expectedTokens), 0, 1200);
        $givenPlain = mb_substr(implode(' ', $givenTokens), 0, 1200);
        $percent = 0.0;
        similar_text($expectedPlain, $givenPlain, $percent);
        $charSim = max(0.0, min(1.0, $percent / 100));

        $score = (0.5 * $recall) + (0.25 * $dice) + (0.15 * $bigram) + (0.10 * $charSim);
        return round(max(0.0, min(1.0, $score)), 4);
    }
}

if (!function_exists('portal_activity_normalize_keywords')) {
    /**
     * Accepts an array or a free-text string with commas / new lines.
     * "|" inside an item still means alternative wordings for that key point.
     *
     * @param mixed $raw
     * @return list<string>
     */
    function portal_activity_normalize_keywords(mixed $raw): array
    {
        $chunks = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $chunks[] = (string) $item;
            }
        } else {
            $chunks[] = (string) $raw;
        }

        $out = [];
        foreach ($chunks as $chunk) {
            foreach (preg_split('/[\n,]+/u', $chunk) ?: [] as $piece) {
                $piece = trim((string) $piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }

        return array_values(array_unique($out));
    }
}

if (!function_exists('portal_activity_keyword_report')) {
    /**
     * Each keyword may list synonyms separated by "|" — any one of them counts.
     *
     * @param list<string> $keywords
     * @return array{hits: list<string>, misses: list<string>, coverage: float|null}
     */
    function portal_activity_keyword_report(array $keywords, string $answerText): array
    {
        $keywords = array_values(array_filter(array_map(
            static fn($k): string => trim((string) $k),
            $keywords
        ), static fn(string $k): bool => $k !== ''));

        if ($keywords === []) {
            return ['hits' => [], 'misses' => [], 'coverage' => null];
        }

        $haystack = ' ' . implode(' ', portal_activity_text_tokens($answerText)) . ' ';
        $hits = [];
        $misses = [];
        foreach ($keywords as $keyword) {
            $matched = false;
            foreach (explode('|', $keyword) as $variant) {
                $needleTokens = portal_activity_text_tokens($variant, true);
                if ($needleTokens === []) {
                    continue;
                }
                if (str_contains($haystack, ' ' . implode(' ', $needleTokens) . ' ')) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                $hits[] = $keyword;
            } else {
                $misses[] = $keyword;
            }
        }

        return [
            'hits' => $hits,
            'misses' => $misses,
            'coverage' => count($hits) / count($keywords),
        ];
    }
}

if (!function_exists('portal_activity_answer_text')) {
    function portal_activity_answer_text(mixed $answer): string
    {
        if (is_string($answer)) {
            return $answer;
        }
        if (is_numeric($answer)) {
            return (string) $answer;
        }
        if (is_array($answer)) {
            foreach (['text', 'value', 'response', 'answer'] as $key) {
                if (isset($answer[$key]) && (is_string($answer[$key]) || is_numeric($answer[$key]))) {
                    return (string) $answer[$key];
                }
            }
        }
        return '';
    }
}

if (!function_exists('portal_activity_written_suggestion')) {
    /**
     * Advisory marking suggestion for a written answer. Returns null when the
     * teacher has not given anything to compare against.
     *
     * @return array<string, mixed>|null
     */
    function portal_activity_written_suggestion(array $question, mixed $answer): ?array
    {
        $type = (string) ($question['question_type'] ?? '');
        if (!in_array($type, ['long_response', 'short_text'], true)) {
            return null;
        }

        $settings = $question['settings'] ?? null;
        if (!is_array($settings)) {
            $settings = portal_activity_json_decode((string) ($question['settings_json'] ?? '{}'), []) ?: [];
        }
        if (array_key_exists('auto_suggest', $settings) && empty($settings['auto_suggest'])) {
            return null;
        }

        $expected = trim((string) ($settings['expected_answer'] ?? ''));
        $keywords = portal_activity_normalize_keywords($settings['keywords'] ?? []);
        if ($expected === '' && $keywords === []) {
            return null;
        }

        $points = max(0.0, (float) ($question['points'] ?? 0));
        $answerText = portal_activity_answer_text($answer);
        $answerWords = count(portal_activity_text_tokens($answerText, true));

        if (trim($answerText) === '') {
            return [
                'similarity' => null,
                'similarity_percent' => null,
                'keyword_hits' => [],
                'keyword_misses' => array_values($keywords),
                'keyword_coverage' => $keywords === [] ? null : 0.0,
                'verdict' => 'blank',
                'verdict_label' => 'Nothing submitted',
                'suggested_score' => 0.0,
                'points' => $points,
                'confidence' => 'high',
                'word_count' => 0,
                'expected_word_count' => count(portal_activity_text_tokens($expected, true)),
                'expected_answer' => $expected,
            ];
        }

        $similarity = $expected === '' ? null : portal_activity_text_similarity($expected, $answerText);
        $keywordReport = portal_activity_keyword_report($keywords, $answerText);
        $coverage = $keywordReport['coverage'];

        if ($similarity !== null && $coverage !== null) {
            $ratio = (0.55 * $coverage) + (0.45 * $similarity);
        } elseif ($coverage !== null) {
            $ratio = $coverage;
        } else {
            $ratio = (float) $similarity;
        }

        // Round the extremes so teachers see clean "full marks" / "no marks"
        // suggestions instead of 4.5-out-of-5 noise.
        if ($ratio >= 0.88) {
            $ratio = 1.0;
        } elseif ($ratio <= 0.12) {
            $ratio = 0.0;
        }

        if ($ratio >= 0.7) {
            $verdict = 'likely_correct';
            $verdictLabel = 'Closely matches the expected answer';
        } elseif ($ratio >= 0.4) {
            $verdict = 'partial';
            $verdictLabel = 'Partly matches the expected answer';
        } else {
            $verdict = 'likely_incorrect';
            $verdictLabel = 'Does not match the expected answer';
        }

        $expectedWords = count(portal_activity_text_tokens($expected, true));
        if ($answerWords < 4 || ($expected !== '' && $expectedWords < 4)) {
            $confidence = 'low';
        } elseif (($ratio >= 0.8 || $ratio <= 0.15) && $answerWords >= 8) {
            $confidence = 'high';
        } else {
            $confidence = 'medium';
        }

        return [
            'similarity' => $similarity,
            'similarity_percent' => $similarity === null ? null : (int) round($similarity * 100),
            'keyword_hits' => $keywordReport['hits'],
            'keyword_misses' => $keywordReport['misses'],
            'keyword_coverage' => $coverage,
            'verdict' => $verdict,
            'verdict_label' => $verdictLabel,
            'suggested_score' => round($points * $ratio * 2) / 2,
            'points' => $points,
            'confidence' => $confidence,
            'word_count' => $answerWords,
            'expected_word_count' => $expectedWords,
            'expected_answer' => $expected,
        ];
    }
}

if (!function_exists('portal_activity_answer_view')) {
    /**
     * Turns a stored answer payload into something a teacher can read at a
     * glance: option labels instead of ids, given vs expected for each blank,
     * and the plain text of written work.
     *
     * Teacher-facing only — it includes the correct answers.
     *
     * @return array<string, mixed>
     */
    function portal_activity_answer_view(array $question, array $options, mixed $answer): array
    {
        $type = (string) ($question['question_type'] ?? '');
        $settings = $question['settings'] ?? null;
        if (!is_array($settings)) {
            $settings = portal_activity_json_decode((string) ($question['settings_json'] ?? '{}'), []) ?: [];
        }
        $label = static fn(array $opt): string => trim(strip_tags((string) ($opt['option_text_html'] ?? ''))) ?: 'Untitled option';
        $blank = ['kind' => 'empty', 'text' => '', 'word_count' => 0, 'items' => [], 'rows' => [], 'expected_text' => ''];

        if ($type === 'single_choice' || $type === 'true_false' || $type === 'multiple_choice') {
            if ($type === 'multiple_choice') {
                $selected = is_array($answer) ? ($answer['option_ids'] ?? []) : [];
                $selected = is_array($selected) ? array_map('strval', $selected) : [];
            } else {
                $one = is_array($answer) ? ($answer['option_id'] ?? $answer['value'] ?? null) : $answer;
                $selected = ($one === null || $one === '') ? [] : [(string) $one];
            }
            $items = [];
            foreach ($options as $opt) {
                $items[] = [
                    'label' => $label($opt),
                    'chosen' => in_array((string) $opt['id'], $selected, true),
                    'correct' => !empty($opt['is_correct']) || (float) ($opt['credit'] ?? 0) > 0,
                ];
            }
            return array_merge($blank, [
                'kind' => $selected === [] ? 'empty' : 'options',
                'items' => $items,
            ]);
        }

        if ($type === 'short_text' || $type === 'long_response') {
            $text = portal_activity_answer_text($answer);
            $accepted = $settings['accepted_answers'] ?? [];
            if (!is_array($accepted)) {
                $accepted = $accepted === '' ? [] : [(string) $accepted];
            }
            $expectedText = $type === 'short_text'
                ? implode(' · ', array_map('strval', $accepted))
                : trim((string) ($settings['expected_answer'] ?? ''));
            return array_merge($blank, [
                'kind' => trim($text) === '' ? 'empty' : 'text',
                'text' => $text,
                'word_count' => count(portal_activity_text_tokens($text, true)),
                'expected_text' => $expectedText,
            ]);
        }

        if ($type === 'numeric') {
            $raw = is_array($answer) ? ($answer['value'] ?? $answer['number'] ?? null) : $answer;
            $unit = is_array($answer) ? trim((string) ($answer['unit'] ?? '')) : '';
            if ($raw === null || $raw === '') {
                return $blank;
            }
            $tolerance = (float) ($settings['absolute_tolerance'] ?? $settings['tolerance'] ?? 0);
            $expected = (string) ($settings['correct_value'] ?? '');
            if ($expected !== '' && $tolerance > 0) {
                $expected .= ' (±' . $tolerance . ')';
            }
            return array_merge($blank, [
                'kind' => 'value',
                'text' => trim((string) $raw . ' ' . $unit),
                'expected_text' => $expected,
            ]);
        }

        if ($type === 'rating_scale') {
            $raw = is_array($answer) ? ($answer['value'] ?? null) : $answer;
            if ($raw === null || $raw === '') {
                return $blank;
            }
            return array_merge($blank, ['kind' => 'value', 'text' => (string) $raw]);
        }

        if ($type === 'fill_blank') {
            $blanks = is_array($settings['blanks'] ?? null) ? $settings['blanks'] : [];
            $responses = is_array($answer) ? ($answer['blanks'] ?? $answer) : [];
            $responses = is_array($responses) ? $responses : [];
            $rows = [];
            foreach ($blanks as $i => $spec) {
                $given = (string) ($responses[$i] ?? $responses[(string) $i] ?? '');
                $accepted = is_array($spec) ? ($spec['accepted'] ?? $spec['accepted_answers'] ?? []) : [(string) $spec];
                if (!is_array($accepted)) {
                    $accepted = [$accepted];
                }
                $correct = false;
                foreach ($accepted as $candidate) {
                    if (portal_activity_normalize_text($given) === portal_activity_normalize_text((string) $candidate)) {
                        $correct = true;
                        break;
                    }
                }
                $rows[] = [
                    'label' => 'Blank ' . ((int) $i + 1),
                    'given' => $given,
                    'expected' => implode(' · ', array_map('strval', $accepted)),
                    'correct' => $correct,
                ];
            }
            return array_merge($blank, ['kind' => $rows === [] ? 'empty' : 'rows', 'rows' => $rows]);
        }

        if ($type === 'ordering') {
            $given = is_array($answer) ? ($answer['order'] ?? $answer) : [];
            $given = is_array($given) ? array_map('strval', array_values($given)) : [];
            if ($given === []) {
                return $blank;
            }
            $correctOrder = $settings['correct_order'] ?? array_map(
                static fn(array $o): string => (string) $o['id'],
                array_values($options)
            );
            $correctOrder = array_map('strval', array_values(is_array($correctOrder) ? $correctOrder : []));
            $labels = [];
            foreach ($options as $opt) {
                $labels[(string) $opt['id']] = $label($opt);
            }
            $rows = [];
            foreach ($given as $i => $id) {
                $rows[] = [
                    'label' => 'Position ' . ($i + 1),
                    'given' => $labels[$id] ?? $id,
                    'expected' => isset($correctOrder[$i]) ? ($labels[$correctOrder[$i]] ?? $correctOrder[$i]) : '',
                    'correct' => isset($correctOrder[$i]) && $correctOrder[$i] === $id,
                ];
            }
            return array_merge($blank, ['kind' => 'rows', 'rows' => $rows]);
        }

        if ($type === 'matching') {
            $given = is_array($answer) ? ($answer['matches'] ?? $answer) : [];
            $given = is_array($given) ? $given : [];
            if ($given === []) {
                return $blank;
            }
            $pairs = is_array($settings['pairs'] ?? null) ? $settings['pairs'] : [];
            $expectedByLeft = [];
            foreach ($pairs as $pair) {
                $left = (string) ($pair['left'] ?? $pair[0] ?? '');
                if ($left !== '') {
                    $expectedByLeft[$left] = (string) ($pair['right'] ?? $pair[1] ?? '');
                }
            }
            $rows = [];
            foreach ($given as $left => $right) {
                $expected = $expectedByLeft[(string) $left] ?? '';
                $rows[] = [
                    'label' => (string) $left,
                    'given' => (string) $right,
                    'expected' => $expected,
                    'correct' => $expected !== '' && $expected === (string) $right,
                ];
            }
            return array_merge($blank, ['kind' => 'rows', 'rows' => $rows]);
        }

        if ($type === 'flashcard') {
            $mark = is_array($answer)
                ? (string) ($answer['value'] ?? $answer['mark'] ?? $answer['text'] ?? '')
                : (string) $answer;
            $mark = strtolower(trim($mark));
            $label = match ($mark) {
                'known', 'know' => 'Know',
                'learning', 'still_learning', 'unknown' => 'Still learning',
                default => $mark !== '' ? $mark : '',
            };
            return array_merge($blank, [
                'kind' => $label === '' ? 'empty' : 'value',
                'text' => $label,
            ]);
        }

        if ($answer === null || $answer === '' || $answer === []) {
            return $blank;
        }
        return array_merge($blank, ['kind' => 'text', 'text' => portal_activity_answer_text($answer)]);
    }
}

if (!function_exists('portal_activity_score_answer')) {
    function portal_activity_score_answer(array $question, array $options, mixed $answer): ?float
    {
        $type = (string) ($question['question_type'] ?? '');
        $points = (float) ($question['points'] ?? 0);
        if (in_array($type, ['long_response', 'rating_scale'], true)) {
            return null;
        }
        if (!empty($question['manual_marking'])) {
            return null;
        }

        $settings = portal_activity_json_decode((string) ($question['settings_json'] ?? '{}'), []) ?: [];

        if ($type === 'flashcard') {
            $mark = is_array($answer)
                ? (string) ($answer['value'] ?? $answer['mark'] ?? $answer['text'] ?? '')
                : (string) $answer;
            $mark = strtolower(trim($mark));
            if ($mark === 'known' || $mark === 'know') {
                return $points;
            }
            if ($mark === 'learning' || $mark === 'still_learning' || $mark === 'unknown') {
                return 0.0;
            }
            return 0.0;
        }

        if ($type === 'single_choice' || $type === 'true_false') {
            $selected = is_array($answer) ? ($answer['option_id'] ?? $answer['value'] ?? null) : $answer;
            foreach ($options as $opt) {
                if ((string) $opt['id'] === (string) $selected || (string) ($opt['option_text_html'] ?? '') === (string) $selected) {
                    if (!empty($opt['is_correct']) || (float) ($opt['credit'] ?? 0) > 0) {
                        $credit = (float) ($opt['credit'] ?? 0);
                        return $credit > 0 ? $points * min(1.0, $credit) : $points;
                    }
                    return 0.0;
                }
            }
            return 0.0;
        }

        if ($type === 'multiple_choice') {
            $selected = is_array($answer) ? ($answer['option_ids'] ?? $answer) : [];
            if (!is_array($selected)) {
                return 0.0;
            }
            $selected = array_map('strval', $selected);
            $correctIds = [];
            $totalCredit = 0.0;
            $earned = 0.0;
            foreach ($options as $opt) {
                $id = (string) $opt['id'];
                $isCorrect = !empty($opt['is_correct']) || (float) ($opt['credit'] ?? 0) > 0;
                $credit = (float) ($opt['credit'] ?? ($isCorrect ? 1 : 0));
                if ($isCorrect) {
                    $correctIds[] = $id;
                    $totalCredit += max($credit, 0.0001);
                }
                if (in_array($id, $selected, true)) {
                    if ($isCorrect) {
                        $earned += max($credit, 0.0001);
                    } else {
                        $earned -= abs($credit > 0 ? $credit : 1);
                    }
                }
            }
            if ($totalCredit <= 0) {
                return 0.0;
            }
            $ratio = max(0.0, min(1.0, $earned / $totalCredit));
            return round($points * $ratio, 4);
        }

        if ($type === 'short_text') {
            $text = is_array($answer) ? (string) ($answer['text'] ?? $answer['value'] ?? '') : (string) $answer;
            $caseSensitive = !empty($settings['case_sensitive']);
            $stripPunct = !array_key_exists('normalize_punctuation', $settings) || !empty($settings['normalize_punctuation']);
            $normalized = portal_activity_normalize_text($text, $caseSensitive, $stripPunct);
            $accepted = $settings['accepted_answers'] ?? [];
            if (!is_array($accepted)) {
                $accepted = [$accepted];
            }
            foreach ($accepted as $candidate) {
                if ($normalized === portal_activity_normalize_text((string) $candidate, $caseSensitive, $stripPunct)) {
                    return $points;
                }
            }
            return 0.0;
        }

        if ($type === 'numeric') {
            $raw = is_array($answer) ? ($answer['value'] ?? $answer['number'] ?? null) : $answer;
            if ($raw === null || $raw === '') {
                return 0.0;
            }
            $value = (float) $raw;
            $correct = (float) ($settings['correct_value'] ?? $settings['correct'] ?? 0);
            $absTol = (float) ($settings['absolute_tolerance'] ?? $settings['tolerance'] ?? 0);
            $pctTol = (float) ($settings['percentage_tolerance'] ?? 0);
            $tolerance = $absTol;
            if ($pctTol > 0) {
                $tolerance = max($tolerance, abs($correct) * ($pctTol / 100.0));
            }
            $unitOk = true;
            $requiredUnit = (string) ($settings['unit'] ?? '');
            $acceptedUnits = $settings['accepted_units'] ?? ($requiredUnit !== '' ? [$requiredUnit] : []);
            if (!is_array($acceptedUnits)) {
                $acceptedUnits = [$acceptedUnits];
            }
            $acceptedUnits = array_values(array_filter(array_map(
                static fn($u) => strtolower(trim((string) $u)),
                $acceptedUnits
            )));
            if (!empty($settings['unit_required']) && $acceptedUnits !== []) {
                $givenUnit = is_array($answer) ? strtolower(trim((string) ($answer['unit'] ?? ''))) : '';
                $unitOk = in_array($givenUnit, $acceptedUnits, true);
            } elseif ($acceptedUnits !== [] && is_array($answer) && trim((string) ($answer['unit'] ?? '')) !== '') {
                $givenUnit = strtolower(trim((string) $answer['unit']));
                $unitOk = in_array($givenUnit, $acceptedUnits, true);
            }
            if (!$unitOk) {
                return 0.0;
            }
            return abs($value - $correct) <= $tolerance + 1e-9 ? $points : 0.0;
        }

        if ($type === 'fill_blank') {
            $blanks = $settings['blanks'] ?? [];
            if (!is_array($blanks) || $blanks === []) {
                return null;
            }
            $responses = is_array($answer) ? ($answer['blanks'] ?? $answer) : [];
            if (!is_array($responses)) {
                return 0.0;
            }
            $per = $points / count($blanks);
            $score = 0.0;
            foreach ($blanks as $i => $blank) {
                $resp = is_array($responses) ? (string) ($responses[$i] ?? $responses[(string) $i] ?? '') : '';
                $accepted = is_array($blank) ? ($blank['accepted'] ?? $blank['accepted_answers'] ?? []) : [(string) $blank];
                if (!is_array($accepted)) {
                    $accepted = [$accepted];
                }
                $norm = portal_activity_normalize_text($resp);
                foreach ($accepted as $cand) {
                    if ($norm === portal_activity_normalize_text((string) $cand)) {
                        $score += $per;
                        break;
                    }
                }
            }
            return round($score, 4);
        }

        if ($type === 'ordering') {
            $correctOrder = $settings['correct_order'] ?? array_map(
                static fn(array $o): string => (string) $o['id'],
                array_values($options)
            );
            $given = is_array($answer) ? ($answer['order'] ?? $answer) : [];
            if (!is_array($given) || $given === []) {
                return 0.0;
            }
            $given = array_map('strval', array_values($given));
            $correctOrder = array_map('strval', array_values($correctOrder));
            $n = count($correctOrder);
            if ($n === 0) {
                return 0.0;
            }
            $right = 0;
            foreach ($correctOrder as $i => $id) {
                if (($given[$i] ?? null) === $id) {
                    $right++;
                }
            }
            return round($points * ($right / $n), 4);
        }

        if ($type === 'matching') {
            $pairs = $settings['pairs'] ?? null;
            $given = is_array($answer) ? ($answer['matches'] ?? $answer) : [];
            if (!is_array($given)) {
                return 0.0;
            }
            if (is_array($pairs) && $pairs !== []) {
                $n = count($pairs);
                $right = 0;
                foreach ($pairs as $pair) {
                    $left = (string) ($pair['left'] ?? $pair[0] ?? '');
                    $rightKey = (string) ($pair['right'] ?? $pair[1] ?? '');
                    if ((string) ($given[$left] ?? '') === $rightKey) {
                        $right++;
                    }
                }
                return $n > 0 ? round($points * ($right / $n), 4) : 0.0;
            }
            $byKey = [];
            foreach ($options as $opt) {
                $key = (string) ($opt['match_key'] ?? '');
                if ($key !== '') {
                    $byKey[(string) $opt['id']] = $key;
                }
            }
            if ($byKey === []) {
                return 0.0;
            }
            $n = count($byKey);
            $right = 0;
            foreach ($byKey as $leftId => $rightKey) {
                if ((string) ($given[$leftId] ?? '') === $rightKey || (string) ($given[$leftId] ?? '') === (string) $rightKey) {
                    $right++;
                }
            }
            return round($points * ($right / $n), 4);
        }

        return null;
    }
}

if (!function_exists('portal_activity_score_attempt')) {
    function portal_activity_score_attempt(int $attemptId): void
    {
        $db = portal_db();
        $attemptStmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $attemptStmt->execute([$attemptId]);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return;
        }

        $versionId = (int) $attempt['activity_version_id'];
        $tree = portal_activity_load_version_tree($versionId, true);
        $order = portal_activity_json_decode((string) $attempt['question_order_json'], []) ?: [];
        $questionMap = [];
        foreach ($tree['questions'] as $q) {
            $questionMap[(int) $q['id']] = $q;
        }
        $ids = $order !== [] ? array_map('intval', $order) : array_keys($questionMap);

        $answersStmt = $db->prepare('SELECT * FROM activity_answers WHERE attempt_id = ?');
        $answersStmt->execute([$attemptId]);
        $answers = [];
        foreach ($answersStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $answers[(int) $row['question_id']] = $row;
        }

        $total = 0.0;
        $max = 0.0;
        $needsManual = false;

        foreach ($ids as $qid) {
            if (!isset($questionMap[$qid])) {
                continue;
            }
            $q = $questionMap[$qid];
            $points = (float) ($q['points'] ?? 0);
            $max += $points;
            $options = $tree['options_by_question'][$qid] ?? [];
            $answerRow = $answers[$qid] ?? null;
            $answer = $answerRow
                ? portal_activity_json_decode((string) $answerRow['answer_json'], null)
                : null;

            $auto = portal_activity_score_answer($q, $options, $answer);
            $manual = $answerRow && $answerRow['manual_score'] !== null
                ? (float) $answerRow['manual_score']
                : null;
            $isManual = !empty($q['manual_marking']);

            if ($isManual && $manual === null) {
                // Teacher still needs to mark this question.
                $needsManual = true;
                $final = $auto; // may be null until marked
            } elseif ($auto === null && $manual === null) {
                // Auto-scorable type with no usable answer → 0 points.
                $auto = 0.0;
                $final = 0.0;
                $total += 0.0;
            } else {
                $final = $manual !== null ? $manual : (float) $auto;
                $total += $final;
            }

            if ($answerRow !== null) {
                $db->prepare(
                    "UPDATE activity_answers
                     SET auto_score = ?, final_score = COALESCE(manual_score, ?), updated_at = datetime('now')
                     WHERE attempt_id = ? AND question_id = ?"
                )->execute([
                    $auto,
                    $auto,
                    $attemptId,
                    $qid,
                ]);
            } else {
                $db->prepare(
                    "INSERT OR IGNORE INTO activity_answers
                        (attempt_id, question_id, answer_json, auto_score, final_score)
                     VALUES (?, ?, 'null', ?, ?)"
                )->execute([$attemptId, $qid, $auto, $final]);
            }
        }

        // No final percentage until every teacher-marked question has a score.
        // Auto points are still stored on answers for the marking screen.
        $percentage = ($needsManual || $max <= 0)
            ? null
            : round(($total / $max) * 100, 2);
        $storedScore = $needsManual ? null : $total;
        $status = $needsManual ? 'awaiting_manual_marking' : 'marked';
        if (in_array((string) $attempt['status'], ['auto_submitted'], true)) {
            // keep auto_submitted until marking completes; then use derived status
            if (!$needsManual) {
                $status = 'marked';
            }
        } elseif (in_array((string) $attempt['status'], ['submitted', 'awaiting_manual_marking', 'marked', 'released'], true)) {
            // fine
        } else {
            $status = $needsManual ? 'awaiting_manual_marking' : 'marked';
        }

        if ((string) $attempt['status'] === 'released') {
            $status = 'released';
        } elseif ((string) $attempt['status'] === 'auto_submitted' && $needsManual) {
            $status = 'awaiting_manual_marking';
        } elseif ((string) $attempt['status'] === 'auto_submitted' && !$needsManual) {
            $status = 'marked';
        }

        $db->prepare(
            "UPDATE activity_attempts
             SET score = ?, maximum_score = ?, percentage = ?, status = ?, updated_at = datetime('now')
             WHERE id = ?"
        )->execute([$storedScore, $max, $percentage, $status, $attemptId]);
    }
}

// ── Attempt engine ────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_get_accommodation')) {
    function portal_activity_get_accommodation(int $activityId, int $userId): ?array
    {
        $stmt = portal_db()->prepare(
            'SELECT * FROM activity_accommodations WHERE activity_id = ? AND user_id = ?'
        );
        $stmt->execute([$activityId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('portal_activity_save_accommodation')) {
    function portal_activity_save_accommodation(int $activityId, int $userId, array $fields, int $updatedBy): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);

        portal_db()->prepare(
            "INSERT INTO activity_accommodations
                (activity_id, user_id, extra_time_percent, extra_minutes, max_attempts_override,
                 allow_paste, fullscreen_exempt, navigation_override, closes_at_override, notes,
                 updated_by, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(activity_id, user_id) DO UPDATE SET
                extra_time_percent = excluded.extra_time_percent,
                extra_minutes = excluded.extra_minutes,
                max_attempts_override = excluded.max_attempts_override,
                allow_paste = excluded.allow_paste,
                fullscreen_exempt = excluded.fullscreen_exempt,
                navigation_override = excluded.navigation_override,
                closes_at_override = excluded.closes_at_override,
                notes = excluded.notes,
                updated_by = excluded.updated_by,
                updated_at = datetime('now')"
        )->execute([
            $activityId,
            $userId,
            (float) ($fields['extra_time_percent'] ?? 0),
            (int) ($fields['extra_minutes'] ?? 0),
            array_key_exists('max_attempts_override', $fields) && $fields['max_attempts_override'] !== '' && $fields['max_attempts_override'] !== null
                ? (int) $fields['max_attempts_override'] : null,
            !empty($fields['allow_paste']) ? 1 : 0,
            !empty($fields['fullscreen_exempt']) ? 1 : 0,
            (string) ($fields['navigation_override'] ?? ''),
            (string) ($fields['closes_at_override'] ?? ''),
            substr((string) ($fields['notes'] ?? ''), 0, 2000),
            $updatedBy ?: null,
        ]);

        portal_activity_audit($activityId, 'accommodation_saved', ['target_user_id' => $userId]);
        return ['ok' => true, 'accommodation' => portal_activity_get_accommodation($activityId, $userId)];
    }
}

if (!function_exists('portal_activity_student_card_summary')) {
    function portal_activity_student_card_summary(array $activity, int $userId): array
    {
        $db = portal_db();
        $activityId = (int) $activity['id'];
        $acc = portal_activity_get_accommodation($activityId, $userId);
        $maxAttempts = (int) ($activity['max_attempts'] ?? 0);
        if ($acc && $acc['max_attempts_override'] !== null && $acc['max_attempts_override'] !== '') {
            $maxAttempts = (int) $acc['max_attempts_override'];
        }

        $stmt = $db->prepare(
            "SELECT * FROM activity_attempts
             WHERE activity_id = ? AND user_id = ?
             ORDER BY attempt_number DESC"
        );
        $stmt->execute([$activityId, $userId]);
        $attempts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $count = count($attempts);
        $inProgress = null;
        $best = null;
        foreach ($attempts as $a) {
            if (($a['status'] ?? '') === 'in_progress') {
                $inProgress = $a;
            }
            if ($a['percentage'] !== null && ($best === null || (float) $a['percentage'] > (float) $best)) {
                $best = (float) $a['percentage'];
            }
        }

        $remaining = $maxAttempts <= 0 ? null : max(0, $maxAttempts - $count);
        if ($inProgress !== null && $remaining !== null) {
            // in-progress counts toward attempts already
        }

        $canStart = portal_activity_can_start($activity, $userId);
        $latest = $attempts[0] ?? null;
        $studentStatus = 'not_started';
        $now = time();
        $opens = trim((string) ($activity['opens_at'] ?? ''));
        $closes = trim((string) ($activity['closes_at'] ?? ''));
        if ($opens !== '') {
            $ts = portal_db_timestamp($opens) ?? strtotime($opens);
            if ($ts && $now < $ts) {
                $studentStatus = 'available_soon';
            }
        }
        if ($closes !== '') {
            $closeOverride = $acc['closes_at_override'] ?? '';
            $closeTs = portal_db_timestamp($closeOverride !== '' ? (string) $closeOverride : $closes)
                ?? strtotime($closeOverride !== '' ? (string) $closeOverride : $closes);
            if ($closeTs && $now > $closeTs && $inProgress === null) {
                $studentStatus = 'closed';
            }
        }
        if ($remaining === 0 && $inProgress === null && $count > 0) {
            $studentStatus = 'attempts_used';
        }
        if ($inProgress !== null) {
            $studentStatus = 'in_progress';
        } elseif ($latest) {
            $st = (string) ($latest['status'] ?? '');
            if (in_array($st, ['submitted', 'auto_submitted'], true)) {
                $studentStatus = 'submitted';
            }
            if ($st === 'awaiting_manual_marking') {
                $studentStatus = 'awaiting_marking';
            }
            if (in_array($st, ['marked', 'released'], true)) {
                $studentStatus = 'completed';
            }
            if ($st === 'invalidated' && $remaining === 0) {
                $studentStatus = 'attempts_used';
            }
        }
        if (($activity['status'] ?? '') !== 'published' && $studentStatus === 'not_started') {
            $studentStatus = 'available_soon';
        }

        $action = 'Start';
        if ($studentStatus === 'in_progress') {
            $action = 'Resume';
        } elseif ($studentStatus === 'awaiting_marking') {
            $action = 'Awaiting marking';
        } elseif (in_array($studentStatus, ['submitted', 'completed'], true)) {
            $action = 'View result';
        } elseif ($studentStatus === 'available_soon') {
            $action = 'Available soon';
        } elseif (in_array($studentStatus, ['closed', 'attempts_used'], true)) {
            $action = $best !== null ? 'View result' : 'Closed';
        }

        return [
            'activity_id' => $activityId,
            'status' => (string) ($activity['status'] ?? 'draft'),
            'student_status' => $studentStatus,
            'primary_action' => $action,
            'mode' => (string) ($activity['mode'] ?? ''),
            'mode_label' => portal_activity_mode_label((string) ($activity['mode'] ?? '')),
            'attempt_count' => $count,
            'max_attempts' => $maxAttempts,
            'attempts_remaining' => $remaining,
            'in_progress_attempt_id' => $inProgress ? (int) $inProgress['id'] : null,
            'best_percentage' => $best,
            'can_start' => !empty($canStart['ok']),
            'can_start_message' => $canStart['error'] ?? null,
            'include_in_gradebook' => (int) ($activity['include_in_gradebook'] ?? 0),
            'xp_enabled' => (int) ($activity['xp_enabled'] ?? 0),
            'xp_amount' => (int) ($activity['xp_amount'] ?? 0),
            'estimated_minutes' => (int) ($activity['estimated_minutes'] ?? 0),
            'opens_at' => (string) ($activity['opens_at'] ?? ''),
            'closes_at' => (string) ($activity['closes_at'] ?? ''),
            'due_at' => (string) ($activity['due_at'] ?? ''),
            'short_description' => (string) ($activity['short_description'] ?? ''),
        ];
    }
}

if (!function_exists('portal_activity_can_start')) {
    function portal_activity_can_start(array $activity, int $userId): array
    {
        $courseId = (int) ($activity['course_id'] ?? 0);
        if (!portal_can_access_course($courseId)) {
            return ['ok' => false, 'error' => 'You do not have access to this activity.'];
        }
        if (($activity['status'] ?? '') !== 'published' && !portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'This activity is not available yet.'];
        }

        if (!portal_can_manage_course($courseId) && function_exists('portal_activity_content_locked')
            && portal_activity_content_locked($activity)) {
            return ['ok' => false, 'error' => 'This activity is locked by your teacher.'];
        }

        $pub = portal_activity_published_version_id((int) $activity['id']);
        if ($pub === null && !portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'This activity is not available yet.'];
        }

        $now = time();
        $opens = trim((string) ($activity['opens_at'] ?? ''));
        if ($opens !== '') {
            $ts = portal_db_timestamp($opens) ?? strtotime($opens);
            if ($ts && $now < $ts) {
                return ['ok' => false, 'error' => 'This activity is not open yet.'];
            }
        }

        $acc = portal_activity_get_accommodation((int) $activity['id'], $userId);
        $closes = trim((string) ($activity['closes_at'] ?? ''));
        if ($acc && trim((string) ($acc['closes_at_override'] ?? '')) !== '') {
            $closes = trim((string) $acc['closes_at_override']);
        }
        if ($closes !== '') {
            $ts = portal_db_timestamp($closes) ?? strtotime($closes);
            if ($ts && $now > $ts) {
                return ['ok' => false, 'error' => 'This activity has closed.'];
            }
        }

        $db = portal_db();
        $inProgress = $db->prepare(
            "SELECT id FROM activity_attempts
             WHERE activity_id = ? AND user_id = ? AND status = 'in_progress' LIMIT 1"
        );
        $inProgress->execute([(int) $activity['id'], $userId]);
        if ($inProgress->fetchColumn()) {
            return ['ok' => true, 'resume' => true];
        }

        $maxAttempts = (int) ($activity['max_attempts'] ?? 0);
        if ($acc && $acc['max_attempts_override'] !== null && $acc['max_attempts_override'] !== '') {
            $maxAttempts = (int) $acc['max_attempts_override'];
        }
        if ($maxAttempts > 0) {
            $cnt = $db->prepare(
                'SELECT COUNT(*) FROM activity_attempts WHERE activity_id = ? AND user_id = ?'
            );
            $cnt->execute([(int) $activity['id'], $userId]);
            if ((int) $cnt->fetchColumn() >= $maxAttempts) {
                return ['ok' => false, 'error' => 'You have no attempts remaining.'];
            }
        }

        return ['ok' => true];
    }
}

if (!function_exists('portal_activity_verify_attempt_token')) {
    function portal_activity_verify_attempt_token(array $attempt, string $token): bool
    {
        $hash = (string) ($attempt['session_token_hash'] ?? '');
        if ($hash === '' || $token === '') {
            return false;
        }
        return hash_equals($hash, portal_activity_hash_token($token));
    }
}

if (!function_exists('portal_activity_attempt_end_reason_meta')) {
    /** @return array{label:string,description:string,class:string} */
    function portal_activity_attempt_end_reason_meta(string $reason): array
    {
        return match ($reason) {
            'submitted' => [
                'label' => 'Submitted normally',
                'description' => 'The student used the submit action.',
                'class' => 'normal',
            ],
            'time_expired' => [
                'label' => 'Time limit expired',
                'description' => 'The assessment was submitted when its timer reached zero.',
                'class' => 'timer',
            ],
            'connection_lost' => [
                'label' => 'Connection grace period expired',
                'description' => 'The student stayed offline beyond the recovery window.',
                'class' => 'connection',
            ],
            'page_left' => [
                'label' => 'Assessment page was left',
                'description' => 'The student navigated away, refreshed, or closed the assessment.',
                'class' => 'left',
            ],
            default => [
                'label' => $reason !== '' ? ucfirst(str_replace('_', ' ', $reason)) : 'Not recorded',
                'description' => $reason !== '' ? 'The attempt ended automatically.' : 'This older attempt has no recorded end reason.',
                'class' => 'unknown',
            ],
        };
    }
}

if (!function_exists('portal_activity_expire_if_needed')) {
    function portal_activity_expire_if_needed(array $attempt): array
    {
        if (($attempt['status'] ?? '') !== 'in_progress') {
            return $attempt;
        }
        $expires = trim((string) ($attempt['expires_at'] ?? ''));
        if ($expires === '') {
            return $attempt;
        }
        $ts = portal_db_timestamp($expires) ?? strtotime($expires);
        if (!$ts || time() < $ts) {
            return $attempt;
        }

        $db = portal_db();
        $db->prepare(
            "UPDATE activity_attempts
             SET status = 'auto_submitted', submitted_at = datetime('now'),
                 end_reason = 'time_expired', resume_allowed = 0, updated_at = datetime('now')
             WHERE id = ? AND status = 'in_progress'"
        )->execute([(int) $attempt['id']]);

        portal_activity_score_attempt((int) $attempt['id']);

        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $stmt->execute([(int) $attempt['id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: $attempt;
    }
}

if (!function_exists('portal_activity_end_assessment_attempt')) {
    /** End a formal assessment when its one-sitting player is left. */
    function portal_activity_end_assessment_attempt(int $attemptId, int $userId, string $reason = 'page_left'): array
    {
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ? AND user_id = ?');
        $stmt->execute([$attemptId, $userId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return ['ok' => false, 'error' => 'Attempt not found.'];
        }

        $activity = portal_activity_find((int) $attempt['activity_id']);
        if ($activity === null || ($activity['mode'] ?? '') !== 'assessment') {
            return ['ok' => false, 'error' => 'Only assessments end when the page is left.'];
        }
        if (($attempt['status'] ?? '') !== 'in_progress') {
            return ['ok' => true, 'attempt' => $attempt, 'already_ended' => true];
        }

        $allowedReasons = ['page_left', 'connection_lost'];
        $reason = in_array($reason, $allowedReasons, true) ? $reason : 'page_left';
        $db->prepare(
            "UPDATE activity_attempts
             SET status = 'auto_submitted', submitted_at = datetime('now'),
                 end_reason = ?, resume_allowed = 0, updated_at = datetime('now')
             WHERE id = ? AND user_id = ? AND status = 'in_progress'"
        )->execute([$reason, $attemptId, $userId]);

        portal_activity_record_integrity_event(
            $attemptId,
            $userId,
            'assessment_left',
            'assessment-left-' . $attemptId,
            null,
            'source_not_available',
            ['reason' => $reason]
        );
        portal_activity_score_attempt($attemptId);

        $fresh = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $fresh->execute([$attemptId]);
        return [
            'ok' => true,
            'attempt' => $fresh->fetch(PDO::FETCH_ASSOC) ?: $attempt,
            'ended_on_leave' => true,
        ];
    }
}

if (!function_exists('portal_activity_start_attempt')) {
    function portal_activity_start_attempt(int $activityId, int $userId, string $integrityAck = ''): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }

        $can = portal_activity_can_start($activity, $userId);
        if (empty($can['ok'])) {
            return $can;
        }

        $db = portal_db();
        $resume = $db->prepare(
            "SELECT * FROM activity_attempts
             WHERE activity_id = ? AND user_id = ? AND status = 'in_progress'
             ORDER BY id DESC LIMIT 1"
        );
        $resume->execute([$activityId, $userId]);
        $existing = $resume->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $existing = portal_activity_expire_if_needed($existing);
            if (($existing['status'] ?? '') === 'in_progress') {
                if (($activity['mode'] ?? '') === 'assessment') {
                    if (empty($existing['resume_allowed'])) {
                        portal_activity_end_assessment_attempt((int) $existing['id'], $userId, 'page_left');
                        return [
                            'ok' => false,
                            'ended' => true,
                            'error' => 'This assessment ended when you left it. Return to the activity page to view the result or begin another attempt.',
                        ];
                    }
                    $db->prepare(
                        "UPDATE activity_attempts SET resume_allowed = 0, updated_at = datetime('now') WHERE id = ?"
                    )->execute([(int) $existing['id']]);
                }
                // Issue a fresh token for resume (hash updated).
                $token = bin2hex(random_bytes(32));
                $db->prepare(
                    "UPDATE activity_attempts SET session_token_hash = ?, updated_at = datetime('now') WHERE id = ?"
                )->execute([portal_activity_hash_token($token), (int) $existing['id']]);
                $payload = portal_activity_get_attempt_for_player((int) $existing['id'], $userId);
                $payload['token'] = $token;
                $payload['resumed'] = true;
                return $payload;
            }
        }

        // The notice is acknowledged once, before a new monitored attempt.
        // Resuming an existing attempt must not ask the student again.
        if (($activity['mode'] ?? '') === 'assessment' && !empty($activity['integrity_enabled'])) {
            if (trim($integrityAck) === '') {
                return ['ok' => false, 'error' => 'Please acknowledge the integrity notice before starting.'];
            }
        }

        $versionId = portal_activity_published_version_id($activityId);
        if ($versionId === null) {
            if (portal_can_manage_course((int) $activity['course_id'])) {
                $versionId = portal_activity_draft_version_id($activityId);
            }
        }
        if ($versionId === null) {
            return ['ok' => false, 'error' => 'This activity is not available yet.'];
        }

        $tree = portal_activity_load_version_tree($versionId, true);
        $questionIds = array_map(static fn(array $q): int => (int) $q['id'], $tree['questions']);
        if ($questionIds === []) {
            return ['ok' => false, 'error' => 'This activity has no questions.'];
        }

        if (!empty($activity['shuffle_questions'])) {
            shuffle($questionIds);
        }
        $pool = (int) ($activity['questions_per_attempt'] ?? 0);
        if ($pool > 0 && $pool < count($questionIds)) {
            $questionIds = array_slice($questionIds, 0, $pool);
        }

        $optionOrder = [];
        foreach ($questionIds as $qid) {
            $opts = $tree['options_by_question'][$qid] ?? [];
            $oids = array_map(static fn(array $o): int => (int) $o['id'], $opts);
            if (!empty($activity['shuffle_options'])) {
                $pinned = [];
                $free = [];
                foreach ($opts as $o) {
                    if ($o['pinned_position'] !== null && $o['pinned_position'] !== '') {
                        $pinned[(int) $o['pinned_position']] = (int) $o['id'];
                    } else {
                        $free[] = (int) $o['id'];
                    }
                }
                shuffle($free);
                $oids = [];
                $fi = 0;
                $total = count($pinned) + count($free);
                for ($i = 0; $i < $total; $i++) {
                    if (isset($pinned[$i])) {
                        $oids[] = $pinned[$i];
                    } elseif (isset($free[$fi])) {
                        $oids[] = $free[$fi++];
                    }
                }
                foreach ($free as $j => $id) {
                    if ($j >= $fi) {
                        $oids[] = $id;
                    }
                }
            }
            $optionOrder[(string) $qid] = $oids;
        }

        $acc = portal_activity_get_accommodation($activityId, $userId);
        $limit = (int) ($activity['time_limit_seconds'] ?? 0);
        $expiresAt = '';
        if ($limit > 0) {
            $extraPct = $acc ? (float) ($acc['extra_time_percent'] ?? 0) : 0.0;
            $extraMin = $acc ? (int) ($acc['extra_minutes'] ?? 0) : 0;
            $seconds = (int) round($limit * (1 + ($extraPct / 100))) + ($extraMin * 60);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + max(1, $seconds));
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = portal_activity_hash_token($token);

        $db->beginTransaction();
        try {
            $numStmt = $db->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) FROM activity_attempts WHERE activity_id = ? AND user_id = ?'
            );
            $numStmt->execute([$activityId, $userId]);
            $attemptNumber = (int) $numStmt->fetchColumn() + 1;

            // Re-check limits inside transaction
            $maxAttempts = (int) ($activity['max_attempts'] ?? 0);
            if ($acc && $acc['max_attempts_override'] !== null && $acc['max_attempts_override'] !== '') {
                $maxAttempts = (int) $acc['max_attempts_override'];
            }
            if ($maxAttempts > 0 && $attemptNumber > $maxAttempts) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'You have no attempts remaining.'];
            }

            $db->prepare(
                "INSERT INTO activity_attempts
                    (activity_id, activity_version_id, course_id, user_id, attempt_number, status,
                     expires_at, question_order_json, option_order_json, session_token_hash, integrity_ack)
                 VALUES (?,?,?,?,?,'in_progress',?,?,?,?,?)"
            )->execute([
                $activityId,
                $versionId,
                (int) $activity['course_id'],
                $userId,
                $attemptNumber,
                $expiresAt,
                portal_activity_json_encode($questionIds),
                portal_activity_json_encode($optionOrder),
                $tokenHash,
                substr($integrityAck, 0, 500),
            ]);
            $attemptId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            portal_log_security_event('activity_start_failed', 'medium', $e->getMessage());
            return ['ok' => false, 'error' => 'The attempt could not be started. Please try again.'];
        }

        $payload = portal_activity_get_attempt_for_player($attemptId, $userId);
        $payload['token'] = $token;
        $payload['resumed'] = false;
        return $payload;
    }
}

if (!function_exists('portal_activity_get_attempt_for_player')) {
    function portal_activity_get_attempt_for_player(int $attemptId, int $userId): array
    {
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || (int) $attempt['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Attempt not found.'];
        }

        $attempt = portal_activity_expire_if_needed($attempt);
        $activity = portal_activity_find((int) $attempt['activity_id']);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_course_access($activity);

        // Never include correct answers while in progress.
        $includeCorrect = false;
        $attemptStatus = (string) ($attempt['status'] ?? '');
        if ($attemptStatus !== 'in_progress') {
            $includeCorrect = portal_activity_feedback_visible($activity, $attempt)
                && (string) ($activity['feedback_policy'] ?? '') !== 'never';
            if (
                ($activity['mode'] ?? '') === 'assessment'
                && empty($activity['results_released'])
                && $attemptStatus !== 'released'
            ) {
                $includeCorrect = false;
            }
        }

        // Grade is incomplete until teacher marking finishes — do not expose a score.
        $scoreReady = $includeCorrect
            && $attemptStatus !== 'awaiting_manual_marking'
            && $attempt['percentage'] !== null;

        $tree = portal_activity_load_version_tree((int) $attempt['activity_version_id'], $includeCorrect);
        $order = portal_activity_json_decode((string) $attempt['question_order_json'], []) ?: [];
        $optionOrder = portal_activity_json_decode((string) $attempt['option_order_json'], []) ?: [];

        $ansStmt = $db->prepare('SELECT question_id, answer_json, autosave_revision, final_score, feedback_html FROM activity_answers WHERE attempt_id = ?');
        $ansStmt->execute([$attemptId]);
        $answers = [];
        foreach ($ansStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entry = [
                'answer' => portal_activity_json_decode((string) $row['answer_json'], null),
                'revision' => (int) $row['autosave_revision'],
            ];
            if ($includeCorrect) {
                $entry['score'] = $row['final_score'];
                $entry['feedback_html'] = $row['feedback_html'];
            }
            $answers[(int) $row['question_id']] = $entry;
        }

        $questionsOut = [];
        $qMap = [];
        foreach ($tree['questions'] as $q) {
            $qMap[(int) $q['id']] = $q;
        }
        foreach ($order as $qid) {
            $qid = (int) $qid;
            if (!isset($qMap[$qid])) {
                continue;
            }
            $q = $qMap[$qid];
            $opts = $tree['options_by_question'][$qid] ?? [];
            $orderedIds = $optionOrder[(string) $qid] ?? $optionOrder[$qid] ?? null;
            if (is_array($orderedIds) && $orderedIds !== []) {
                $byId = [];
                foreach ($opts as $o) {
                    $byId[(int) $o['id']] = $o;
                }
                $sorted = [];
                foreach ($orderedIds as $oid) {
                    if (isset($byId[(int) $oid])) {
                        $sorted[] = $byId[(int) $oid];
                    }
                }
                $opts = $sorted !== [] ? $sorted : $opts;
            }
            $questionsOut[] = [
                'id' => $qid,
                'section_id' => $q['section_id'],
                'question_type' => $q['question_type'],
                'prompt_html' => $q['prompt_html'],
                'hint_html' => (
                    ($activity['mode'] ?? '') !== 'assessment'
                    && (
                        ($activity['mode'] ?? '') === 'practice'
                        || ($activity['feedback_policy'] ?? '') === 'after_each'
                    )
                )
                    ? ($q['hint_html'] ?? '')
                    : '',
                'explanation_html' => $includeCorrect ? ($q['explanation_html'] ?? '') : '',
                'points' => (float) $q['points'],
                'required' => (int) $q['required'],
                'settings' => portal_activity_json_decode((string) $q['settings_json'], []) ?: [],
                'media' => is_array($q['media'] ?? null) ? $q['media'] : [],
                'options' => array_map(static function (array $o) use ($includeCorrect): array {
                    $out = [
                        'id' => (int) $o['id'],
                        'option_text_html' => $o['option_text_html'],
                        'sort_order' => (int) ($o['sort_order'] ?? 0),
                    ];
                    if ($includeCorrect) {
                        $out['is_correct'] = (int) ($o['is_correct'] ?? 0);
                        $out['feedback_html'] = $o['feedback_html'] ?? '';
                    }
                    return $out;
                }, $opts),
            ];
        }

        $acc = portal_activity_get_accommodation((int) $activity['id'], $userId);
        $safeAcc = null;
        if ($acc) {
            $safeAcc = [
                'allow_paste' => (int) $acc['allow_paste'],
                'fullscreen_exempt' => (int) $acc['fullscreen_exempt'],
                'navigation_override' => (string) $acc['navigation_override'],
            ];
        }

        return [
            'ok' => true,
            'attempt' => [
                'id' => (int) $attempt['id'],
                'status' => $attempt['status'],
                'attempt_number' => (int) $attempt['attempt_number'],
                'started_at' => $attempt['started_at'],
                'expires_at' => $attempt['expires_at'],
                'submitted_at' => $attempt['submitted_at'],
                'score' => $scoreReady ? $attempt['score'] : null,
                'maximum_score' => $scoreReady ? $attempt['maximum_score'] : null,
                'percentage' => $scoreReady ? $attempt['percentage'] : null,
                'autosave_revision' => (int) $attempt['autosave_revision'],
            ],
            'activity' => [
                'id' => (int) $activity['id'],
                'title' => $activity['title'],
                'mode' => $activity['mode'],
                'mode_label' => portal_activity_mode_label((string) $activity['mode']),
                'instructions_html' => $activity['instructions_html'],
                'navigation_policy' => $acc && trim((string) ($acc['navigation_override'] ?? '')) !== ''
                    ? $acc['navigation_override']
                    : $activity['navigation_policy'],
                'feedback_policy' => $activity['feedback_policy'],
                'paste_policy' => $activity['paste_policy'],
                'copy_policy' => $activity['copy_policy'],
                'integrity_enabled' => (int) (($activity['mode'] === 'assessment') ? $activity['integrity_enabled'] : 0),
                'focus_monitoring' => (int) (($activity['mode'] === 'assessment') ? $activity['focus_monitoring'] : 0),
                'fullscreen_policy' => $activity['fullscreen_policy'],
                'time_limit_seconds' => (int) $activity['time_limit_seconds'],
            ],
            'sections' => $tree['sections'],
            'questions' => $questionsOut,
            'answers' => $answers,
            'accommodation' => $safeAcc,
            'server_now' => portal_activity_now_utc(),
        ];
    }
}

if (!function_exists('portal_activity_preview_session')) {
    /**
     * @param array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    function portal_activity_preview_session(int $activityId, ?array $set = null): array
    {
        if (!isset($_SESSION['activity_previews']) || !is_array($_SESSION['activity_previews'])) {
            $_SESSION['activity_previews'] = [];
        }
        $key = (string) $activityId;
        if ($set !== null) {
            $_SESSION['activity_previews'][$key] = $set;
        }
        $row = $_SESSION['activity_previews'][$key] ?? [];
        return is_array($row) ? $row : [];
    }
}

if (!function_exists('portal_activity_preview_option_order')) {
    /**
     * @param list<array<string, mixed>> $opts
     * @return list<int>
     */
    function portal_activity_preview_option_order(array $opts, bool $shuffle): array
    {
        $oids = array_map(static fn(array $o): int => (int) $o['id'], $opts);
        if (!$shuffle) {
            return $oids;
        }
        $pinned = [];
        $free = [];
        foreach ($opts as $o) {
            if ($o['pinned_position'] !== null && $o['pinned_position'] !== '') {
                $pinned[(int) $o['pinned_position']] = (int) $o['id'];
            } else {
                $free[] = (int) $o['id'];
            }
        }
        shuffle($free);
        $oids = [];
        $fi = 0;
        $total = count($pinned) + count($free);
        for ($i = 0; $i < $total; $i++) {
            if (isset($pinned[$i])) {
                $oids[] = $pinned[$i];
            } elseif (isset($free[$fi])) {
                $oids[] = $free[$fi++];
            }
        }
        foreach ($free as $j => $id) {
            if ($j >= $fi) {
                $oids[] = $id;
            }
        }
        return $oids;
    }
}

if (!function_exists('portal_activity_preview_player_payload')) {
    /**
     * Student-player payload from the current draft, without creating an attempt.
     *
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    function portal_activity_preview_player_payload(array $activity, array $session = []): array
    {
        $activityId = (int) ($activity['id'] ?? 0);
        $versionId = portal_activity_draft_version_id($activityId)
            ?? portal_activity_published_version_id($activityId);
        if ($versionId === null) {
            return ['ok' => false, 'error' => 'No draft to preview.'];
        }

        $submitted = !empty($session['submitted']);
        $fakeAttempt = [
            'status' => $submitted
                ? (string) ($session['status'] ?? 'submitted')
                : 'in_progress',
            'results_released' => $activity['results_released'] ?? 0,
        ];
        $includeCorrect = $submitted
            && portal_activity_feedback_visible($activity, $fakeAttempt)
            && (string) ($activity['feedback_policy'] ?? '') !== 'never';
        if (
            ($activity['mode'] ?? '') === 'assessment'
            && empty($activity['results_released'])
            && ($fakeAttempt['status'] ?? '') !== 'released'
        ) {
            $includeCorrect = false;
        }

        $tree = portal_activity_load_version_tree($versionId, $includeCorrect);
        $scoringTree = $includeCorrect ? $tree : portal_activity_load_version_tree($versionId, true);

        $questionIds = array_map('intval', $session['question_ids'] ?? []);
        if ($questionIds === []) {
            $questionIds = array_map(static fn(array $q): int => (int) $q['id'], $tree['questions']);
            if ($questionIds === []) {
                return ['ok' => false, 'error' => 'This activity has no questions.'];
            }
            if (!empty($activity['shuffle_questions'])) {
                shuffle($questionIds);
            }
            $pool = (int) ($activity['questions_per_attempt'] ?? 0);
            if ($pool > 0 && $pool < count($questionIds)) {
                $questionIds = array_slice($questionIds, 0, $pool);
            }
        }

        $optionOrder = is_array($session['option_order'] ?? null) ? $session['option_order'] : [];
        if ($optionOrder === []) {
            foreach ($questionIds as $qid) {
                $optionOrder[(string) $qid] = portal_activity_preview_option_order(
                    $tree['options_by_question'][$qid] ?? $scoringTree['options_by_question'][$qid] ?? [],
                    !empty($activity['shuffle_options'])
                );
            }
        }

        $answersIn = is_array($session['answers'] ?? null) ? $session['answers'] : [];
        $qMap = [];
        foreach ($tree['questions'] as $q) {
            $qMap[(int) $q['id']] = $q;
        }
        $scoreMap = [];
        foreach ($scoringTree['questions'] as $q) {
            $scoreMap[(int) $q['id']] = $q;
        }

        $questionsOut = [];
        $answersOut = [];
        $scoreTotal = 0.0;
        $maxTotal = 0.0;
        $awaitingManual = false;

        foreach ($questionIds as $qid) {
            if (!isset($qMap[$qid]) && !isset($scoreMap[$qid])) {
                continue;
            }
            $q = $qMap[$qid] ?? $scoreMap[$qid];
            $opts = $tree['options_by_question'][$qid] ?? [];
            $orderedIds = $optionOrder[(string) $qid] ?? $optionOrder[$qid] ?? null;
            if (is_array($orderedIds) && $orderedIds !== []) {
                $byId = [];
                foreach ($opts as $o) {
                    $byId[(int) $o['id']] = $o;
                }
                $sorted = [];
                foreach ($orderedIds as $oid) {
                    if (isset($byId[(int) $oid])) {
                        $sorted[] = $byId[(int) $oid];
                    }
                }
                $opts = $sorted !== [] ? $sorted : $opts;
            }

            $entry = $answersIn[$qid] ?? $answersIn[(string) $qid] ?? null;
            $answerVal = is_array($entry) ? ($entry['answer'] ?? null) : null;
            $rev = is_array($entry) ? (int) ($entry['revision'] ?? 0) : 0;
            $answerRow = [
                'answer' => $answerVal,
                'revision' => $rev,
            ];

            $rawQ = $scoreMap[$qid] ?? $q;
            $rawOpts = $scoringTree['options_by_question'][$qid] ?? [];
            $auto = portal_activity_score_answer($rawQ, $rawOpts, $answerVal);
            $points = (float) ($rawQ['points'] ?? 0);
            $maxTotal += $points;
            if ($auto === null) {
                if ($answerVal !== null && $answerVal !== '' && $answerVal !== []) {
                    $awaitingManual = true;
                }
            } else {
                $scoreTotal += $auto;
            }
            if ($includeCorrect) {
                $answerRow['score'] = $auto;
                $answerRow['feedback_html'] = '';
            }
            if ($answerVal !== null) {
                $answersOut[$qid] = $answerRow;
            }

            $questionsOut[] = [
                'id' => $qid,
                'section_id' => $q['section_id'] ?? null,
                'question_type' => $q['question_type'],
                'prompt_html' => $q['prompt_html'],
                'hint_html' => (
                    ($activity['mode'] ?? '') !== 'assessment'
                    && (
                        ($activity['mode'] ?? '') === 'practice'
                        || ($activity['feedback_policy'] ?? '') === 'after_each'
                    )
                )
                    ? ($q['hint_html'] ?? '')
                    : '',
                'explanation_html' => $includeCorrect ? ($q['explanation_html'] ?? '') : '',
                'points' => $points,
                'required' => (int) ($q['required'] ?? 0),
                'settings' => portal_activity_json_decode((string) ($q['settings_json'] ?? '{}'), []) ?: [],
                'media' => is_array($q['media'] ?? null) ? $q['media'] : [],
                'options' => array_map(static function (array $o) use ($includeCorrect): array {
                    $out = [
                        'id' => (int) $o['id'],
                        'option_text_html' => $o['option_text_html'],
                        'sort_order' => (int) ($o['sort_order'] ?? 0),
                    ];
                    if ($includeCorrect) {
                        $out['is_correct'] = (int) ($o['is_correct'] ?? 0);
                        $out['feedback_html'] = $o['feedback_html'] ?? '';
                    }
                    return $out;
                }, $opts),
            ];
        }

        $scoreReady = $includeCorrect && !$awaitingManual;
        $status = !$submitted
            ? 'in_progress'
            : ($awaitingManual ? 'awaiting_manual_marking' : 'submitted');
        $percentage = $maxTotal > 0 ? round(100 * $scoreTotal / $maxTotal, 2) : 0.0;
        $token = (string) ($session['token'] ?? 'preview');
        $expiresAt = (string) ($session['expires_at'] ?? '');

        return [
            'ok' => true,
            'preview' => true,
            'recorded' => false,
            'token' => $token,
            'resumed' => false,
            'attempt' => [
                'id' => 0,
                'status' => $status,
                'attempt_number' => 1,
                'started_at' => $session['started_at'] ?? portal_activity_now_utc(),
                'expires_at' => $expiresAt !== '' ? $expiresAt : null,
                'submitted_at' => $submitted ? portal_activity_now_utc() : null,
                'score' => $scoreReady ? $scoreTotal : null,
                'maximum_score' => $scoreReady ? $maxTotal : null,
                'percentage' => $scoreReady ? $percentage : null,
                'autosave_revision' => 0,
            ],
            'activity' => [
                'id' => $activityId,
                'title' => $activity['title'],
                'mode' => $activity['mode'],
                'mode_label' => portal_activity_mode_label((string) $activity['mode']),
                'instructions_html' => $activity['instructions_html'],
                'navigation_policy' => $activity['navigation_policy'],
                'feedback_policy' => $activity['feedback_policy'],
                'paste_policy' => $activity['paste_policy'],
                'copy_policy' => $activity['copy_policy'],
                'integrity_enabled' => (int) ((($activity['mode'] ?? '') === 'assessment') ? $activity['integrity_enabled'] : 0),
                'focus_monitoring' => (int) ((($activity['mode'] ?? '') === 'assessment') ? $activity['focus_monitoring'] : 0),
                'fullscreen_policy' => $activity['fullscreen_policy'],
                'time_limit_seconds' => (int) $activity['time_limit_seconds'],
            ],
            'sections' => $tree['sections'],
            'questions' => $questionsOut,
            'answers' => $answersOut,
            'accommodation' => null,
            'server_now' => portal_activity_now_utc(),
            'player' => null,
        ];
    }
}

if (!function_exists('portal_activity_preview_post')) {
    /**
     * Handle player API actions in teacher preview without writing attempts.
     *
     * @param array<string, mixed> $activity
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    function portal_activity_preview_post(array $activity, array $payload): array
    {
        $activityId = (int) ($activity['id'] ?? 0);
        $action = (string) ($payload['action'] ?? '');
        $session = portal_activity_preview_session($activityId);

        if ($action === 'start' || $action === 'resume') {
            $limit = (int) ($activity['time_limit_seconds'] ?? 0);
            $expiresAt = $limit > 0 ? gmdate('Y-m-d H:i:s', time() + max(1, $limit)) : '';
            $fresh = [
                'token' => 'preview',
                'started_at' => portal_activity_now_utc(),
                'expires_at' => $expiresAt,
                'answers' => [],
                'submitted' => false,
                'question_ids' => [],
                'option_order' => [],
            ];
            $payloadOut = portal_activity_preview_player_payload($activity, $fresh);
            if (empty($payloadOut['ok'])) {
                return $payloadOut;
            }
            $fresh['question_ids'] = array_map(static fn(array $q): int => (int) $q['id'], $payloadOut['questions']);
            $optionOrder = [];
            foreach ($payloadOut['questions'] as $q) {
                $optionOrder[(string) $q['id']] = array_map(
                    static fn(array $o): int => (int) $o['id'],
                    $q['options'] ?? []
                );
            }
            $fresh['option_order'] = $optionOrder;
            portal_activity_preview_session($activityId, $fresh);
            return $payloadOut;
        }

        if ($action === 'save_answer') {
            $qid = (int) ($payload['question_id'] ?? 0);
            if ($qid <= 0) {
                return ['ok' => false, 'error' => 'Invalid question.'];
            }
            $rev = max(1, (int) ($payload['revision'] ?? 1));
            $session['answers'] = is_array($session['answers'] ?? null) ? $session['answers'] : [];
            $session['answers'][$qid] = [
                'answer' => $payload['answer'] ?? null,
                'revision' => $rev,
            ];
            portal_activity_preview_session($activityId, $session);
            $response = [
                'ok' => true,
                'revision' => $rev,
                'attempt_revision' => $rev,
                'saved_at' => portal_activity_now_utc(),
            ];
            $mode = (string) ($activity['mode'] ?? '');
            $policy = (string) ($activity['feedback_policy'] ?? '');
            if (in_array($mode, ['practice', 'quiz', 'challenge'], true) && $policy === 'after_each') {
                $versionId = portal_activity_draft_version_id($activityId)
                    ?? portal_activity_published_version_id($activityId);
                if ($versionId !== null) {
                    $tree = portal_activity_load_version_tree($versionId, true);
                    $question = null;
                    foreach ($tree['questions'] as $q) {
                        if ((int) $q['id'] === $qid) {
                            $question = $q;
                            break;
                        }
                    }
                    if ($question) {
                        $options = $tree['options_by_question'][$qid] ?? [];
                        $auto = portal_activity_score_answer($question, $options, $payload['answer'] ?? null);
                        if ($auto !== null) {
                            $points = (float) ($question['points'] ?? 0);
                            $response['feedback'] = [
                                'correct' => $points <= 0 ? null : ($auto >= $points - 1e-9),
                                'score' => $auto,
                                'points' => $points,
                                'explanation_html' => (string) ($question['explanation_html'] ?? ''),
                                'message' => $auto >= $points - 1e-9
                                    ? 'Correct!'
                                    : ($auto > 0 ? 'Partially correct — review the explanation.' : 'Not quite — review the explanation and try again.'),
                            ];
                        }
                    }
                }
            }
            return $response;
        }

        if ($action === 'submit' || $action === 'result') {
            $session['submitted'] = true;
            portal_activity_preview_session($activityId, $session);
            $out = portal_activity_preview_player_payload($activity, $session);
            if (empty($out['ok'])) {
                return $out;
            }
            $out['player'] = $out;
            $out['gamification'] = null;
            return $out;
        }

        if (in_array($action, ['leave_assessment', 'integrity_event', 'sync_timer'], true)) {
            return [
                'ok' => true,
                'preview' => true,
                'attempt' => [
                    'id' => 0,
                    'status' => 'in_progress',
                    'expires_at' => $session['expires_at'] ?? null,
                ],
                'server_now' => portal_activity_now_utc(),
            ];
        }

        return ['ok' => false, 'error' => 'Unknown action.'];
    }
}

if (!function_exists('portal_activity_save_answer')) {
    function portal_activity_save_answer(
        int $attemptId,
        int $userId,
        int $questionId,
        mixed $answer,
        int $clientRevision,
        string $token
    ): array {
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || (int) $attempt['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Attempt not found.'];
        }
        if (!portal_activity_verify_attempt_token($attempt, $token)) {
            portal_log_security_event('activity_token_invalid', 'medium', 'attempt_id=' . $attemptId);
            return ['ok' => false, 'error' => 'Your session is invalid. Refresh and try again.'];
        }

        $attempt = portal_activity_expire_if_needed($attempt);
        if (($attempt['status'] ?? '') !== 'in_progress') {
            return ['ok' => false, 'error' => 'This attempt can no longer be edited.'];
        }

        $order = portal_activity_json_decode((string) $attempt['question_order_json'], []) ?: [];
        $order = array_map('intval', $order);
        if (!in_array($questionId, $order, true)) {
            return ['ok' => false, 'error' => 'Invalid question for this attempt.'];
        }

        $existing = $db->prepare(
            'SELECT autosave_revision FROM activity_answers WHERE attempt_id = ? AND question_id = ?'
        );
        $existing->execute([$attemptId, $questionId]);
        $currentRev = $existing->fetchColumn();
        if ($currentRev !== false && $clientRevision < (int) $currentRev) {
            return [
                'ok' => false,
                'error' => 'A newer answer was already saved.',
                'revision' => (int) $currentRev,
                'conflict' => true,
            ];
        }

        $newRev = max((int) $clientRevision, (int) ($currentRev ?: 0)) + (($currentRev !== false && (int) $clientRevision === (int) $currentRev) ? 0 : 0);
        // Accept same revision as idempotent overwrite; bump when client sends higher/equal intending save.
        if ($currentRev === false) {
            $newRev = max(1, $clientRevision);
        } elseif ($clientRevision > (int) $currentRev) {
            $newRev = $clientRevision;
        } else {
            // equal revision — treat as idempotent success without changing stored revision
            $newRev = (int) $currentRev;
        }

        $json = portal_activity_json_encode($answer);
        $db->prepare(
            "INSERT INTO activity_answers (attempt_id, question_id, answer_json, autosave_revision, updated_at)
             VALUES (?,?,?,?,datetime('now'))
             ON CONFLICT(attempt_id, question_id) DO UPDATE SET
                answer_json = excluded.answer_json,
                autosave_revision = excluded.autosave_revision,
                updated_at = datetime('now')"
        )->execute([$attemptId, $questionId, $json, $newRev]);

        $db->prepare(
            "UPDATE activity_attempts
             SET autosave_revision = autosave_revision + 1, updated_at = datetime('now')
             WHERE id = ?"
        )->execute([$attemptId]);

        $attemptRev = $db->prepare('SELECT autosave_revision FROM activity_attempts WHERE id = ?');
        $attemptRev->execute([$attemptId]);

        $response = [
            'ok' => true,
            'revision' => $newRev,
            'attempt_revision' => (int) $attemptRev->fetchColumn(),
            'saved_at' => portal_activity_now_utc(),
        ];

        // Immediate feedback for formative modes only — never for formal assessment mid-attempt.
        $activity = portal_activity_find((int) $attempt['activity_id']);
        $mode = (string) ($activity['mode'] ?? '');
        $policy = (string) ($activity['feedback_policy'] ?? '');
        if (
            $activity
            && in_array($mode, ['practice', 'quiz', 'challenge'], true)
            && $policy === 'after_each'
        ) {
            $qStmt = $db->prepare('SELECT * FROM activity_questions WHERE id = ? AND activity_version_id = ?');
            $qStmt->execute([$questionId, (int) $attempt['activity_version_id']]);
            $question = $qStmt->fetch(PDO::FETCH_ASSOC);
            if ($question) {
                $oStmt = $db->prepare('SELECT * FROM activity_question_options WHERE question_id = ? ORDER BY sort_order, id');
                $oStmt->execute([$questionId]);
                $options = $oStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $auto = portal_activity_score_answer($question, $options, $answer);
                if ($auto !== null) {
                    $points = (float) ($question['points'] ?? 0);
                    $response['feedback'] = [
                        'correct' => $points <= 0 ? null : ($auto >= $points - 1e-9),
                        'score' => $auto,
                        'points' => $points,
                        'explanation_html' => (string) ($question['explanation_html'] ?? ''),
                        'message' => $auto >= $points - 1e-9
                            ? 'Correct!'
                            : ($auto > 0 ? 'Partially correct — review the explanation.' : 'Not quite — review the explanation and try again.'),
                    ];
                    // Option-level feedback for the selected choice(s), without exposing other keys.
                    $selectedIds = [];
                    if (is_array($answer)) {
                        if (isset($answer['option_id'])) {
                            $selectedIds[] = (int) $answer['option_id'];
                        }
                        if (isset($answer['option_ids']) && is_array($answer['option_ids'])) {
                            $selectedIds = array_merge($selectedIds, array_map('intval', $answer['option_ids']));
                        }
                    }
                    $optFb = [];
                    foreach ($options as $opt) {
                        if (in_array((int) $opt['id'], $selectedIds, true) && trim((string) ($opt['feedback_html'] ?? '')) !== '') {
                            $optFb[] = (string) $opt['feedback_html'];
                        }
                    }
                    if ($optFb !== []) {
                        $response['feedback']['option_feedback_html'] = implode('', $optFb);
                    }
                }
            }
        }

        return $response;
    }
}

if (!function_exists('portal_activity_submit_attempt')) {
    function portal_activity_submit_attempt(int $attemptId, int $userId, string $token): array
    {
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || (int) $attempt['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Attempt not found.'];
        }
        if (!portal_activity_verify_attempt_token($attempt, $token)) {
            return ['ok' => false, 'error' => 'Your session is invalid. Refresh and try again.'];
        }

        $attempt = portal_activity_expire_if_needed($attempt);
        if (!in_array((string) $attempt['status'], ['in_progress'], true)) {
            // Idempotent: already submitted
            if (in_array((string) $attempt['status'], ['submitted', 'auto_submitted', 'awaiting_manual_marking', 'marked', 'released'], true)) {
                return ['ok' => true, 'attempt' => $attempt, 'already_submitted' => true];
            }
            return ['ok' => false, 'error' => 'This attempt cannot be submitted.'];
        }

        $db->prepare(
            "UPDATE activity_attempts
             SET status = 'submitted', submitted_at = datetime('now'),
                 end_reason = 'submitted', resume_allowed = 0, updated_at = datetime('now')
             WHERE id = ? AND status = 'in_progress'"
        )->execute([$attemptId]);

        portal_activity_score_attempt($attemptId);

        $activity = portal_activity_find((int) $attempt['activity_id']);
        $gamification = ['xp' => 0, 'badges' => [], 'pending_review' => false];
        if ($activity && ($activity['mode'] ?? '') !== 'assessment') {
            $gamification = portal_activity_award_badges_after_attempt($userId, $activity, $attemptId);
        } elseif ($activity && ($activity['mode'] ?? '') === 'assessment' && !empty($activity['xp_enabled'])) {
            // Formal-assessment rewards are deferred until staff release the result.
            // This prevents an integrity-flagged or invalidated attempt from granting XP.
            $gamification['pending_review'] = true;
        }

        $fresh = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $fresh->execute([$attemptId]);
        $attempt = $fresh->fetch(PDO::FETCH_ASSOC) ?: $attempt;

        return [
            'ok' => true,
            'attempt' => $attempt,
            'player' => portal_activity_get_attempt_for_player($attemptId, $userId),
            'gamification' => $gamification,
        ];
    }
}

// ── Integrity ─────────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_classify_paste_source')) {
    function portal_activity_classify_paste_source(?string $htmlSnippet): string
    {
        if ($htmlSnippet === null || trim($htmlSnippet) === '') {
            return 'source_not_available';
        }
        $html = $htmlSnippet;
        // Never treat as storing clipboard — callers must pass only short classification hints.
        if (strlen($html) > 2000) {
            $html = substr($html, 0, 2000);
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '' && (str_contains(strtolower($html), 'https://' . $host) || str_contains(strtolower($html), 'http://' . $host))) {
            return 'likely_internal_portal';
        }
        if (str_contains($html, 'data-portal-origin') || str_contains($html, 'data-portal-activity')) {
            return 'likely_internal_portal';
        }
        if (preg_match('/https?:\/\//i', $html)) {
            return 'external_or_unknown';
        }
        return 'source_not_available';
    }
}

if (!function_exists('portal_activity_integrity_summary_label')) {
    function portal_activity_integrity_summary_label(array $events): string
    {
        $count = count($events);
        if ($count === 0) {
            return 'No notable signals';
        }
        if ($count <= 3) {
            return 'A few signals';
        }
        if ($count <= 10) {
            return 'Review recommended';
        }
        return 'High number of signals';
    }
}

if (!function_exists('portal_activity_integrity_event_severity')) {
    /**
     * Classify a recorded event for teacher triage. This is deliberately
     * evidence-neutral: a signal requests review; it does not prove misconduct.
     *
     * @param array<string, mixed>|string $event
     */
    function portal_activity_integrity_event_severity(array|string $event): string
    {
        $type = is_array($event) ? (string) ($event['event_type'] ?? '') : $event;
        $source = is_array($event) ? (string) ($event['source_classification'] ?? '') : '';

        if (in_array($type, ['window_focus', 'connection_restored', 'assessment_left', 'attempt_reopened'], true)) {
            return 'info';
        }
        if (in_array($type, ['paste_attempt', 'paste_allowed'], true) && $source === 'likely_internal_portal') {
            return 'info';
        }
        if (in_array($type, [
            'paste_attempt', 'paste_allowed', 'paste_blocked', 'copy_attempt', 'cut_attempt',
            'fullscreen_exit', 'multiple_tab_detected', 'unusual_answer_burst',
        ], true)) {
            return 'high';
        }
        return 'review';
    }
}

if (!function_exists('portal_activity_integrity_review_state')) {
    /**
     * @param list<array<string, mixed>> $events
     * @return array{level:string,label:string,total_count:int,flagged_count:int,high_count:int,message:string}
     */
    function portal_activity_integrity_review_state(array $events): array
    {
        $flagged = 0;
        $high = 0;
        foreach ($events as $event) {
            $severity = portal_activity_integrity_event_severity($event);
            if ($severity === 'info') {
                continue;
            }
            $flagged++;
            if ($severity === 'high') {
                $high++;
            }
        }

        if ($flagged === 0) {
            return [
                'level' => 'clear',
                'label' => 'No integrity concerns recorded',
                'total_count' => count($events),
                'flagged_count' => 0,
                'high_count' => 0,
                'message' => 'No review-level signals were recorded for this attempt.',
            ];
        }

        $level = $high > 0 ? 'high' : 'review';
        return [
            'level' => $level,
            'label' => $level === 'high' ? 'Integrity checks flagged this attempt' : 'Integrity review needed',
            'total_count' => count($events),
            'flagged_count' => $flagged,
            'high_count' => $high,
            'message' => $flagged . ' review-level signal' . ($flagged === 1 ? '' : 's')
                . ' recorded. Review the timeline before releasing this result.',
        ];
    }
}

if (!function_exists('portal_activity_integrity_event_label')) {
    /** Neutral wording for a single integrity event type. */
    function portal_activity_integrity_event_label(string $eventType): string
    {
        return match ($eventType) {
            'paste_attempt', 'paste_allowed' => 'External or unknown paste attempt',
            'paste_blocked' => 'Paste was blocked by assessment settings',
            'copy_attempt', 'cut_attempt' => 'Copy or cut was attempted',
            'visibility_hidden' => 'Assessment page became hidden',
            'window_blur' => 'Focus left the assessment',
            'window_focus' => 'Focus returned to the assessment',
            'fullscreen_exit' => 'Fullscreen mode was exited',
            'multiple_tab_detected' => 'Multiple assessment tabs may have been open',
            'page_reload' => 'Assessment page was reloaded',
            'connection_lost' => 'Connection was lost',
            'connection_restored' => 'Connection was restored',
            'unusual_answer_burst' => 'Unusual answer timing was noted',
            'assessment_resumed' => 'Assessment was resumed after interruption',
            'assessment_left' => 'Assessment ended after the page was left',
            'attempt_reopened' => 'Teacher reopened the assessment attempt',
            default => 'Integrity signal recorded',
        };
    }
}

if (!function_exists('portal_activity_record_integrity_event')) {
    function portal_activity_record_integrity_event(
        int $attemptId,
        int $userId,
        string $eventType,
        string $idempotencyKey,
        ?int $questionId = null,
        string $sourceClassification = 'source_not_available',
        array $metadata = [],
        int $clientElapsedMs = 0,
        string $occurredAt = ''
    ): array {
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt || (int) $attempt['user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Attempt not found.'];
        }

        $activity = portal_activity_find((int) $attempt['activity_id']);
        if ($activity === null || ($activity['mode'] ?? '') !== 'assessment' || empty($activity['integrity_enabled'])) {
            return ['ok' => true, 'ignored' => true];
        }

        // Strip any clipboard/text payloads — never store them.
        unset($metadata['clipboard'], $metadata['text'], $metadata['paste_text'], $metadata['html'], $metadata['content']);

        $allowedSources = ['likely_internal_portal', 'external_or_unknown', 'source_not_available'];
        if (!in_array($sourceClassification, $allowedSources, true)) {
            $sourceClassification = 'source_not_available';
        }

        try {
            $db->prepare(
                'INSERT INTO activity_integrity_events
                    (attempt_id, user_id, question_id, event_type, source_classification,
                     event_metadata_json, client_elapsed_ms, occurred_at, idempotency_key)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $attemptId,
                $userId,
                $questionId,
                substr($eventType, 0, 64),
                $sourceClassification,
                portal_activity_json_encode($metadata),
                max(0, $clientElapsedMs),
                $occurredAt !== '' ? $occurredAt : portal_activity_now_utc(),
                substr($idempotencyKey, 0, 120),
            ]);
        } catch (PDOException $e) {
            // Unique idempotency — treat as success
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['ok' => true, 'duplicate' => true];
            }
            return ['ok' => false, 'error' => 'Could not record event.'];
        }

        return ['ok' => true];
    }
}

if (!function_exists('portal_activity_external_originality_check')) {
    function portal_activity_external_originality_check(string $text): array
    {
        unset($text); // unused — provider not configured
        return [
            'configured' => false,
            'message' => 'External originality checking is not configured for this portal.',
            'matches' => [],
        ];
    }
}

// ── Gamification ──────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_award_xp')) {
    function portal_activity_award_xp(
        int $userId,
        int $courseId,
        int $activityId,
        ?int $attemptId,
        string $eventType,
        int $xp,
        string $uniqueKey
    ): bool {
        if ($userId <= 0 || $xp === 0 || $uniqueKey === '') {
            return false;
        }
        try {
            $stmt = portal_db()->prepare(
                'INSERT OR IGNORE INTO gamification_events
                    (user_id, course_id, activity_id, attempt_id, event_type, xp_amount, unique_reward_key)
                 VALUES (?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $userId,
                $courseId,
                $activityId ?: null,
                $attemptId,
                substr($eventType, 0, 64),
                $xp,
                substr($uniqueKey, 0, 190),
            ]);
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_activity_award_badge')) {
    /**
     * @return array{key: string, title: string, description: string, icon: string}|null
     */
    function portal_activity_award_badge(int $userId, string $badgeKey, int $courseId = 0): ?array
    {
        $db = portal_db();
        $stmt = $db->prepare('SELECT id, title, description, icon FROM gamification_badges WHERE badge_key = ? AND enabled = 1');
        $stmt->execute([$badgeKey]);
        $badge = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$badge) {
            return null;
        }
        try {
            $ins = $db->prepare(
                'INSERT OR IGNORE INTO user_gamification_badges (user_id, badge_id, course_id) VALUES (?,?,?)'
            );
            $ins->execute([$userId, (int) $badge['id'], $courseId]);
            if ($ins->rowCount() > 0) {
                return [
                    'key' => $badgeKey,
                    'title' => (string) $badge['title'],
                    'description' => (string) $badge['description'],
                    'icon' => (string) $badge['icon'],
                ];
            }
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('portal_activity_award_badges_after_attempt')) {
    /**
     * @return array{xp: int, badges: list<array{key: string, title: string, description: string, icon: string}>}
     */
    function portal_activity_award_badges_after_attempt(int $userId, array $activity, int $attemptId): array
    {
        $result = ['xp' => 0, 'badges' => []];
        if (($activity['mode'] ?? '') === 'survey') {
            return $result;
        }

        $db = portal_db();
        $attemptStmt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
        $attemptStmt->execute([$attemptId]);
        $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            return $result;
        }

        $activityId = (int) $activity['id'];
        $courseId = (int) $activity['course_id'];
        $xpEnabled = !empty($activity['xp_enabled']);
        $baseXp = (int) ($activity['xp_amount'] ?? 0);

        if ($xpEnabled && $baseXp > 0) {
            $fullKey = 'activity_complete:' . $activityId . ':' . $userId;
            $awarded = portal_activity_award_xp(
                $userId,
                $courseId,
                $activityId,
                $attemptId,
                'activity_complete',
                $baseXp,
                $fullKey
            );
            if ($awarded) {
                $result['xp'] = $baseXp;
            } elseif (($activity['mode'] ?? '') === 'practice') {
                // Reduced XP for practice repeats — once per attempt, small amount
                $reduced = max(1, (int) floor($baseXp * 0.2));
                $awardedReduced = portal_activity_award_xp(
                    $userId,
                    $courseId,
                    $activityId,
                    $attemptId,
                    'practice_repeat',
                    $reduced,
                    'practice_repeat:' . $activityId . ':' . $userId . ':' . $attemptId
                );
                if ($awardedReduced) {
                    $result['xp'] = $reduced;
                }
            }
        }

        $newBadges = [];
        $addBadge = static function (?array $badge) use (&$newBadges): void {
            if ($badge !== null) {
                $newBadges[] = $badge;
            }
        };

        $completedStmt = $db->prepare(
            "SELECT COUNT(*) FROM activity_attempts
             WHERE user_id = ? AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
        );
        $completedStmt->execute([$userId]);
        $completedCount = (int) $completedStmt->fetchColumn();

        if ($completedCount === 1) {
            $addBadge(portal_activity_award_badge($userId, 'first_steps', $courseId));
        }
        if ($completedCount >= 3) {
            $addBadge(portal_activity_award_badge($userId, 'on_a_roll', $courseId));
        }

        if ($attempt['percentage'] !== null && (float) $attempt['percentage'] >= 100) {
            $addBadge(portal_activity_award_badge($userId, 'perfect_score', $courseId));
        }

        $prev = $db->prepare(
            "SELECT percentage FROM activity_attempts
             WHERE activity_id = ? AND user_id = ? AND id != ? AND percentage IS NOT NULL
             ORDER BY attempt_number DESC LIMIT 1"
        );
        $prev->execute([$activityId, $userId, $attemptId]);
        $prevPct = $prev->fetchColumn();
        if ($prevPct !== false && $attempt['percentage'] !== null && (float) $attempt['percentage'] > (float) $prevPct) {
            $addBadge(portal_activity_award_badge($userId, 'comeback', $courseId));
        }

        $distinct = $db->prepare(
            "SELECT COUNT(DISTINCT activity_id) FROM activity_attempts
             WHERE user_id = ? AND course_id = ?
               AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
        );
        $distinct->execute([$userId, $courseId]);
        if ((int) $distinct->fetchColumn() >= 3) {
            $addBadge(portal_activity_award_badge($userId, 'course_explorer', $courseId));
        }

        if (($activity['mode'] ?? '') === 'practice') {
            $practice = $db->prepare(
                "SELECT COUNT(*) FROM activity_attempts a
                 JOIN course_activities c ON c.id = a.activity_id
                 WHERE a.user_id = ? AND c.mode = 'practice'
                   AND a.status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
            );
            $practice->execute([$userId]);
            if ((int) $practice->fetchColumn() >= 5) {
                $addBadge(portal_activity_award_badge($userId, 'practice_progress', $courseId));
            }
        }

        if (trim((string) ($attempt['expires_at'] ?? '')) !== '' && trim((string) ($attempt['submitted_at'] ?? '')) !== '') {
            $exp = portal_db_timestamp((string) $attempt['expires_at']);
            $sub = portal_db_timestamp((string) $attempt['submitted_at']);
            if ($exp && $sub && $sub < $exp - 60) {
                $addBadge(portal_activity_award_badge($userId, 'quick_thinker', $courseId));
            }
        }

        $result['badges'] = $newBadges;
        return $result;
    }
}

if (!function_exists('portal_activity_course_leaderboard')) {
    /**
     * Privacy-friendly course XP leaderboard. Staff accounts are excluded.
     *
     * @return list<array{id:int,name:string,initials:string,xp:int}>
     */
    function portal_activity_course_leaderboard(int $courseId, int $limit = 8): array
    {
        $limit = max(1, min(25, $limit));
        $stmt = portal_db()->prepare(
            "SELECT u.id, u.name, u.initials, COALESCE(SUM(g.xp_amount), 0) AS xp
             FROM users u
             JOIN gamification_events g ON g.user_id = u.id AND g.course_id = ?
             WHERE u.role = 'student'
             GROUP BY u.id, u.name, u.initials
             HAVING SUM(g.xp_amount) > 0
             ORDER BY xp DESC, u.name ASC
             LIMIT ?"
        );
        $stmt->bindValue(1, $courseId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'initials' => (string) $row['initials'],
            'xp' => (int) $row['xp'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
}

// ── Media ─────────────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_media_allowed_extensions')) {
    /** @return array<string, list<string>> */
    function portal_activity_media_allowed_extensions(): array
    {
        return [
            'image' => ['png', 'jpg', 'jpeg', 'webp', 'gif'],
            'audio' => ['mp3', 'm4a', 'wav', 'ogg'],
            'video' => ['mp4', 'webm'],
        ];
    }
}

if (!function_exists('portal_activity_media_mime_map')) {
    /** @return array<string, list<string>> */
    function portal_activity_media_mime_map(): array
    {
        return [
            'png' => ['image/png'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'mp3' => ['audio/mpeg', 'audio/mp3'],
            'm4a' => ['audio/mp4', 'audio/m4a', 'audio/x-m4a'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
            'ogg' => ['audio/ogg', 'application/ogg'],
            'mp4' => ['video/mp4'],
            'webm' => ['video/webm'],
        ];
    }
}

if (!function_exists('portal_activity_media_max_bytes')) {
    function portal_activity_media_max_bytes(string $mediaType): int
    {
        return match ($mediaType) {
            'image' => 10 * 1024 * 1024,
            'audio' => 50 * 1024 * 1024,
            'video' => 400 * 1024 * 1024,
            default => 10 * 1024 * 1024,
        };
    }
}

if (!function_exists('portal_activity_media_path_safe')) {
    function portal_activity_media_path_safe(string $storagePath): ?string
    {
        $storagePath = str_replace(['\\', "\0"], ['/', ''], $storagePath);
        $storagePath = ltrim($storagePath, '/');
        if ($storagePath === '' || str_contains($storagePath, '..')) {
            return null;
        }
        if (!str_starts_with($storagePath, 'activities/')) {
            return null;
        }

        $base = portal_uploads_base();
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storagePath);
        $realBase = realpath($base);
        if ($realBase === false) {
            return null;
        }
        $dir = realpath(dirname($full));
        if ($dir === false || !str_starts_with(strtolower($dir), strtolower($realBase))) {
            return null;
        }
        if (!is_file($full)) {
            return null;
        }
        $realFile = realpath($full);
        if ($realFile === false || !str_starts_with(strtolower($realFile), strtolower($realBase . DIRECTORY_SEPARATOR))) {
            return null;
        }
        return $realFile;
    }
}

if (!function_exists('portal_activity_question_media_limit')) {
    function portal_activity_question_media_limit(?string $questionType): int
    {
        return ($questionType === 'flashcard') ? 1 : 3;
    }
}

if (!function_exists('portal_activity_media_can_attach')) {
    /**
     * @return array{ok: bool, error?: string, limit?: int, count?: int}
     */
    function portal_activity_media_can_attach(int $questionId): array
    {
        $stmt = portal_db()->prepare('SELECT question_type FROM activity_questions WHERE id = ?');
        $stmt->execute([$questionId]);
        $type = $stmt->fetchColumn();
        if ($type === false) {
            return ['ok' => false, 'error' => 'Question not found.'];
        }
        $limit = portal_activity_question_media_limit((string) $type);
        $countStmt = portal_db()->prepare(
            "SELECT COUNT(*) FROM (
                SELECT COALESCE(NULLIF(sha256, ''), 'id-' || id) AS k
                FROM activity_media
                WHERE question_id = ?
                GROUP BY k
             )"
        );
        $countStmt->execute([$questionId]);
        $count = (int) $countStmt->fetchColumn();
        if ($count >= $limit) {
            return [
                'ok' => false,
                'error' => $limit === 1
                    ? 'This card already has media. Remove it to add another.'
                    : 'This question already has ' . $limit . ' attachments. Remove one to add another.',
                'limit' => $limit,
                'count' => $count,
            ];
        }
        return ['ok' => true, 'limit' => $limit, 'count' => $count];
    }
}

if (!function_exists('portal_activity_store_media')) {
    function portal_activity_store_media(
        int $courseId,
        ?int $activityId,
        ?int $versionId,
        ?int $questionId,
        array $fileField,
        string $mediaType,
        string $mediaRole = 'attachment',
        string $altText = '',
        string $caption = ''
    ): array {
        if (!portal_can_manage_course($courseId)) {
            return ['ok' => false, 'error' => 'You cannot upload media for this course.'];
        }

        if ($questionId !== null && $questionId > 0) {
            $limitCheck = portal_activity_media_can_attach($questionId);
            if (empty($limitCheck['ok'])) {
                return $limitCheck;
            }
        }

        $allowed = portal_activity_media_allowed_extensions();
        if (!isset($allowed[$mediaType])) {
            return ['ok' => false, 'error' => 'Unsupported media type.'];
        }

        if (($fileField['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            portal_log_blocked_upload('activity media upload error');
            return ['ok' => false, 'error' => 'Your media upload could not be verified.'];
        }

        $tmp = (string) ($fileField['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            portal_log_blocked_upload('activity media not uploaded file');
            return ['ok' => false, 'error' => 'Your media upload could not be verified.'];
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'Empty media files are not allowed.'];
        }
        if ($size > portal_activity_media_max_bytes($mediaType)) {
            return ['ok' => false, 'error' => 'That file is too large.'];
        }

        $original = (string) ($fileField['name'] ?? 'upload');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed[$mediaType], true)) {
            portal_log_blocked_upload('activity media bad extension');
            return ['ok' => false, 'error' => 'Unsupported file type.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $mimeMap = portal_activity_media_mime_map();
        $okMimes = $mimeMap[$ext] ?? [];
        if ($mime === '' || $okMimes === [] || !in_array($mime, $okMimes, true)) {
            portal_log_blocked_upload('activity media mime mismatch');
            return ['ok' => false, 'error' => 'Your media upload could not be verified.'];
        }

        if ($mediaType === 'image') {
            $info = @getimagesize($tmp);
            if ($info === false) {
                return ['ok' => false, 'error' => 'Your media upload could not be verified.'];
            }
        }

        $sha = hash_file('sha256', $tmp) ?: '';
        if ($questionId !== null && $questionId > 0 && $sha !== '') {
            $dup = portal_db()->prepare(
                'SELECT id FROM activity_media WHERE question_id = ? AND sha256 = ? ORDER BY id ASC LIMIT 1'
            );
            $dup->execute([$questionId, $sha]);
            $existingId = (int) ($dup->fetchColumn() ?: 0);
            if ($existingId > 0) {
                return [
                    'ok' => true,
                    'media_id' => $existingId,
                    'duplicate' => true,
                    'mime_type' => $mime,
                    'filesize' => $size,
                    'original_filename' => $original,
                    'media_type' => $mediaType,
                ];
            }
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $relDir = 'activities/' . $courseId;
        $absDir = portal_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relDir);
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return ['ok' => false, 'error' => 'Could not store media.'];
        }
        $absPath = $absDir . DIRECTORY_SEPARATOR . $storedName;
        $relPath = $relDir . '/' . $storedName;

        if (!move_uploaded_file($tmp, $absPath)) {
            return ['ok' => false, 'error' => 'Could not store media.'];
        }

        $user = portal_current_user();
        $db = portal_db();
        try {
            $db->beginTransaction();
            $db->prepare(
                'INSERT INTO activity_media
                    (course_id, activity_id, activity_version_id, question_id, media_role, media_type,
                     original_filename, storage_path, mime_type, filesize, sha256, alt_text, caption, uploaded_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $courseId,
                $activityId,
                $versionId,
                $questionId,
                substr($mediaRole, 0, 40),
                $mediaType,
                substr($original, 0, 255),
                $relPath,
                $mime,
                $size,
                $sha,
                substr($altText, 0, 255),
                substr($caption, 0, 500),
                (int) ($user['id'] ?? 0) ?: null,
            ]);
            $mediaId = (int) $db->lastInsertId();
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($absPath);
            portal_log_security_event('activity_media_store_failed', 'medium', $e->getMessage());
            return ['ok' => false, 'error' => 'Could not store media.'];
        }

        return [
            'ok' => true,
            'media_id' => $mediaId,
            'mime_type' => $mime,
            'filesize' => $size,
            'original_filename' => $original,
            'media_type' => $mediaType,
        ];
    }
}

if (!function_exists('portal_activity_media_client_row')) {
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    function portal_activity_media_client_row(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'question_id' => isset($row['question_id']) && $row['question_id'] !== '' && $row['question_id'] !== null
                ? (int) $row['question_id']
                : null,
            'media_type' => (string) ($row['media_type'] ?? 'image'),
            'original_filename' => (string) ($row['original_filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'filesize' => (int) ($row['filesize'] ?? 0),
            'sha256' => (string) ($row['sha256'] ?? ''),
            'alt_text' => (string) ($row['alt_text'] ?? ''),
            'caption' => (string) ($row['caption'] ?? ''),
            'url' => 'activity-media.php?id=' . (int) ($row['id'] ?? 0),
        ];
    }
}

if (!function_exists('portal_activity_media_for_questions')) {
    /**
     * @param list<int> $questionIds
     * @return array<int, list<array<string, mixed>>>
     */
    function portal_activity_media_for_questions(array $questionIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $questionIds))));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = portal_db()->prepare(
            "SELECT id, question_id, media_type, original_filename, mime_type, filesize, sha256, alt_text, caption
             FROM activity_media
             WHERE question_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmt->execute($ids);
        $byQuestion = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = (int) $row['question_id'];
            $byQuestion[$qid][] = portal_activity_media_client_row($row);
        }
        return $byQuestion;
    }
}

if (!function_exists('portal_activity_delete_media')) {
    function portal_activity_delete_media(int $activityId, int $mediaId): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        if (!portal_can_manage_course((int) $activity['course_id'])) {
            return ['ok' => false, 'error' => 'You cannot manage this activity.'];
        }
        $stmt = portal_db()->prepare(
            'SELECT * FROM activity_media WHERE id = ? AND activity_id = ?'
        );
        $stmt->execute([$mediaId, $activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ok' => false, 'error' => 'Media not found.'];
        }
        $abs = portal_activity_media_path_safe((string) ($row['storage_path'] ?? ''));
        portal_db()->prepare('DELETE FROM activity_media WHERE id = ? AND activity_id = ?')->execute([$mediaId, $activityId]);
        if ($abs !== null && is_file($abs)) {
            @unlink($abs);
        }
        portal_activity_audit($activityId, 'media_deleted', ['media_id' => $mediaId]);
        return ['ok' => true, 'media_id' => $mediaId];
    }
}

// ── Analytics / CSV / gradebook ───────────────────────────────────────────────

if (!function_exists('portal_activity_csv_safe')) {
    function portal_activity_csv_safe(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}

if (!function_exists('portal_activity_delete_attempts')) {
    /**
     * Permanently remove attempts (and answers / integrity / XP events) for an activity.
     *
     * @param list<int|string> $attemptIds
     * @return array{ok: bool, deleted?: int, attempt_ids?: list<int>, error?: string}
     */
    function portal_activity_delete_attempts(int $activityId, array $attemptIds): array
    {
        $ids = [];
        foreach ($attemptIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return ['ok' => true, 'deleted' => 0, 'attempt_ids' => []];
        }

        $db = portal_db();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $chk = $db->prepare(
            "SELECT id FROM activity_attempts WHERE activity_id = ? AND id IN ($placeholders)"
        );
        $chk->execute(array_merge([$activityId], $ids));
        $valid = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($valid === []) {
            return ['ok' => true, 'deleted' => 0, 'attempt_ids' => []];
        }

        $ph = implode(',', array_fill(0, count($valid), '?'));
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM activity_answers WHERE attempt_id IN ($ph)")->execute($valid);
            $db->prepare("DELETE FROM activity_integrity_events WHERE attempt_id IN ($ph)")->execute($valid);
            $db->prepare("DELETE FROM gamification_events WHERE attempt_id IN ($ph)")->execute($valid);
            $db->prepare(
                "DELETE FROM activity_attempts WHERE activity_id = ? AND id IN ($ph)"
            )->execute(array_merge([$activityId], $valid));
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'error' => 'Could not delete attempts.'];
        }

        portal_activity_audit($activityId, 'attempts_deleted', [
            'attempt_ids' => $valid,
            'count' => count($valid),
        ]);

        return ['ok' => true, 'deleted' => count($valid), 'attempt_ids' => $valid];
    }
}

if (!function_exists('portal_activity_results_summary')) {
    function portal_activity_results_summary(int $activityId): array
    {
        $db = portal_db();
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS attempts,
                    COUNT(DISTINCT user_id) AS students,
                    AVG(percentage) AS avg_percentage,
                    MAX(percentage) AS max_percentage,
                    MIN(percentage) AS min_percentage,
                    SUM(CASE WHEN status IN ('awaiting_manual_marking') THEN 1 ELSE 0 END) AS awaiting_marking,
                    SUM(CASE WHEN EXISTS (
                        SELECT 1 FROM activity_integrity_events e
                        WHERE e.attempt_id = activity_attempts.id
                          AND e.event_type NOT IN ('window_focus','connection_restored')
                          AND NOT (
                              e.event_type IN ('paste_attempt','paste_allowed')
                              AND e.source_classification = 'likely_internal_portal'
                          )
                    ) THEN 1 ELSE 0 END) AS integrity_flagged
             FROM activity_attempts
             WHERE activity_id = ?
               AND status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
        );
        $stmt->execute([$activityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'attempts' => (int) ($row['attempts'] ?? 0),
            'students' => (int) ($row['students'] ?? 0),
            'avg_percentage' => $row['avg_percentage'] !== null ? round((float) $row['avg_percentage'], 2) : null,
            'max_percentage' => $row['max_percentage'] !== null ? round((float) $row['max_percentage'], 2) : null,
            'min_percentage' => $row['min_percentage'] !== null ? round((float) $row['min_percentage'], 2) : null,
            'awaiting_marking' => (int) ($row['awaiting_marking'] ?? 0),
            'integrity_flagged' => (int) ($row['integrity_flagged'] ?? 0),
        ];
    }
}

if (!function_exists('portal_activity_question_analytics')) {
    function portal_activity_question_analytics(int $activityId): array
    {
        $versionId = portal_activity_published_version_id($activityId)
            ?? portal_activity_draft_version_id($activityId);
        if ($versionId === null) {
            return [];
        }
        $tree = portal_activity_load_version_tree($versionId, true);
        $db = portal_db();
        $out = [];

        foreach ($tree['questions'] as $q) {
            $qid = (int) $q['id'];
            $stmt = $db->prepare(
                "SELECT AVG(a.final_score) AS avg_score, COUNT(a.id) AS responses
                 FROM activity_answers a
                 JOIN activity_attempts t ON t.id = a.attempt_id
                 WHERE a.question_id = ? AND t.activity_id = ?
                   AND t.status IN ('submitted','auto_submitted','awaiting_manual_marking','marked','released')"
            );
            $stmt->execute([$qid, $activityId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $avg = $row['avg_score'] !== null ? (float) $row['avg_score'] : null;
            $points = (float) $q['points'];
            $out[] = [
                'question_id' => $qid,
                'question_type' => $q['question_type'],
                'prompt_excerpt' => mb_substr(trim(strip_tags((string) $q['prompt_html'])), 0, 120),
                'points' => $points,
                'responses' => (int) ($row['responses'] ?? 0),
                'avg_score' => $avg !== null ? round($avg, 3) : null,
                'facility' => ($avg !== null && $points > 0) ? round(($avg / $points) * 100, 1) : null,
            ];
        }

        return $out;
    }
}

if (!function_exists('portal_activity_gradebook_rows')) {
    function portal_activity_gradebook_rows(int $courseId, int $userId): array
    {
        $db = portal_db();
        $stmt = $db->prepare(
            "SELECT a.id AS activity_id, a.title, a.grade_weight, a.mode,
                    t.id AS attempt_id, t.percentage, t.score, t.maximum_score,
                    t.submitted_at, t.status, t.updated_at
             FROM course_activities a
             JOIN activity_attempts t ON t.activity_id = a.id AND t.user_id = ?
             WHERE a.course_id = ?
               AND (a.include_in_gradebook = 1 OR t.status = 'released')
               AND a.status = 'published'
               AND a.feedback_policy != 'never'
               AND t.status = 'released'
             ORDER BY t.submitted_at DESC, t.id DESC"
        );
        $stmt->execute([$userId, $courseId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $bestByActivity = [];
        foreach ($rows as $row) {
            $aid = (int) $row['activity_id'];
            // Prefer released/marked with percentage
            if (!isset($bestByActivity[$aid])) {
                $bestByActivity[$aid] = $row;
                continue;
            }
            $cur = $bestByActivity[$aid];
            $curPct = $cur['percentage'] !== null ? (float) $cur['percentage'] : -1;
            $newPct = $row['percentage'] !== null ? (float) $row['percentage'] : -1;
            if ($newPct > $curPct) {
                $bestByActivity[$aid] = $row;
            }
        }

        $out = [];
        foreach ($bestByActivity as $row) {
            $status = (string) $row['status'];
            $score = $row['percentage'];
            $markedAt = '';
            if (in_array($status, ['marked', 'released'], true)) {
                $markedAt = (string) ($row['updated_at'] ?? $row['submitted_at'] ?? '');
            } elseif ($status === 'awaiting_manual_marking') {
                $score = null;
            }

            $out[] = [
                'title' => (string) $row['title'],
                'mode' => (string) ($row['mode'] ?? 'quiz'),
                'score' => $score !== null ? round((float) $score, 2) : null,
                'weight' => (float) ($row['grade_weight'] ?? 100),
                'submission_weight' => (float) ($row['grade_weight'] ?? 100),
                'marked_at' => $markedAt,
                'submitted_at' => (string) ($row['submitted_at'] ?? ''),
                'activity_id' => (int) $row['activity_id'],
                'attempt_id' => (int) $row['attempt_id'],
                'status' => $status,
                'source' => 'activity',
            ];
        }

        return array_values($out);
    }
}

// ── JSON API helpers ──────────────────────────────────────────────────────────

if (!function_exists('portal_activity_json_ok')) {
    function portal_activity_json_ok(array $data = [], array $extra = []): never
    {
        portal_json_response(array_merge(['ok' => true], $data, $extra));
    }
}

if (!function_exists('portal_activity_json_error')) {
    function portal_activity_json_error(string $message, int $code = 400, array $extra = []): never
    {
        portal_json_response(array_merge(['ok' => false, 'error' => $message], $extra), $code);
    }
}

// ── Question bank ─────────────────────────────────────────────────────────────

if (!function_exists('portal_activity_bank_save_question')) {
    function portal_activity_bank_save_question(int $ownerId, array $snapshot, array $meta = []): array
    {
        $type = (string) ($snapshot['question_type'] ?? $meta['question_type'] ?? '');
        if (!in_array($type, portal_activity_question_types(), true)) {
            return ['ok' => false, 'error' => 'Invalid question type.'];
        }
        $visibility = (string) ($meta['visibility'] ?? 'private');
        if (!in_array($visibility, ['private', 'course', 'school'], true)) {
            $visibility = 'private';
        }

        $db = portal_db();
        $db->prepare(
            "INSERT INTO question_bank_items
                (owner_id, visibility, source_course_id, question_type, title,
                 question_snapshot_json, difficulty, tags)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([
            $ownerId,
            $visibility,
            isset($meta['source_course_id']) ? (int) $meta['source_course_id'] : null,
            $type,
            substr((string) ($meta['title'] ?? strip_tags((string) ($snapshot['prompt_html'] ?? 'Question'))), 0, 200),
            portal_activity_json_encode($snapshot),
            (string) ($meta['difficulty'] ?? $snapshot['difficulty'] ?? 'medium'),
            (string) ($meta['tags'] ?? $snapshot['tags'] ?? ''),
        ]);

        return ['ok' => true, 'bank_item_id' => (int) $db->lastInsertId()];
    }
}

if (!function_exists('portal_activity_bank_list')) {
    function portal_activity_bank_list(int $ownerId, ?int $courseId = null, int $limit = 50, int $offset = 0): array
    {
        $db = portal_db();
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $sql = "SELECT * FROM question_bank_items
                WHERE owner_id = ?
                   OR visibility = 'school'
                   OR (visibility = 'course' AND source_course_id = ?)
                ORDER BY updated_at DESC, id DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $db->prepare($sql);
        $stmt->execute([$ownerId, $courseId ?? 0]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('portal_activity_bank_add_to_activity')) {
    function portal_activity_bank_add_to_activity(int $activityId, int $bankItemId): array
    {
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }
        $db = portal_db();
        $stmt = $db->prepare('SELECT * FROM question_bank_items WHERE id = ?');
        $stmt->execute([$bankItemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return ['ok' => false, 'error' => 'Bank item not found.'];
        }

        $user = portal_current_user();
        $ownerOk = (int) $item['owner_id'] === (int) ($user['id'] ?? 0);
        $vis = (string) $item['visibility'];
        $courseId = (int) ($ctx['activity']['course_id'] ?? 0);
        if (!$ownerOk && $vis === 'private') {
            return ['ok' => false, 'error' => 'You cannot use that question.'];
        }
        if ($vis === 'course' && (int) ($item['source_course_id'] ?? 0) !== $courseId && !$ownerOk) {
            return ['ok' => false, 'error' => 'You cannot use that question.'];
        }

        $snap = portal_activity_json_decode((string) $item['question_snapshot_json'], []) ?: [];
        $created = portal_activity_add_question(
            $activityId,
            (string) ($snap['question_type'] ?? $item['question_type']),
            (string) ($snap['prompt_html'] ?? ''),
            isset($snap['section_id']) ? (int) $snap['section_id'] : null,
            [
                'explanation_html' => $snap['explanation_html'] ?? '',
                'hint_html' => $snap['hint_html'] ?? '',
                'teacher_notes' => $snap['teacher_notes'] ?? '',
                'points' => $snap['points'] ?? 1,
                'difficulty' => $snap['difficulty'] ?? $item['difficulty'],
                'tags' => $snap['tags'] ?? $item['tags'],
                'required' => $snap['required'] ?? 1,
                'manual_marking' => $snap['manual_marking'] ?? 0,
                'settings' => $snap['settings'] ?? [],
                'options' => $snap['options'] ?? [],
                'skip_default_options' => !empty($snap['options']),
            ]
        );

        return $created;
    }
}

// ── Import / export ───────────────────────────────────────────────────────────

if (!function_exists('portal_activity_import_csv_preview')) {
    function portal_activity_import_csv_preview(string $csvText): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csvText)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            return ['ok' => false, 'error' => 'CSV is empty.', 'rows' => []];
        }

        $header = str_getcsv(array_shift($lines));
        $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
        $expected = [
            'section', 'question_type', 'prompt', 'option_a', 'option_b', 'option_c', 'option_d',
            'correct_answer', 'accepted_answers', 'points', 'explanation', 'difficulty', 'tags',
        ];

        $rows = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $assoc = [];
            foreach ($header as $hi => $name) {
                $assoc[$name] = $cols[$hi] ?? '';
            }
            $errors = [];
            $type = strtolower(trim((string) ($assoc['question_type'] ?? '')));
            $type = str_replace([' ', '-'], '_', $type);
            $aliases = [
                'truefalse' => 'true_false',
                'mcq' => 'single_choice',
                'multiple' => 'multiple_choice',
                'short' => 'short_text',
                'long' => 'long_response',
                'fill' => 'fill_blank',
                'rating' => 'rating_scale',
            ];
            $type = $aliases[$type] ?? $type;
            if (!in_array($type, portal_activity_question_types(), true)) {
                $errors[] = 'Invalid question type.';
            }
            if (trim((string) ($assoc['prompt'] ?? '')) === '') {
                $errors[] = 'Prompt is required.';
            }
            $rows[] = [
                'line' => $i + 2,
                'data' => $assoc,
                'question_type' => $type,
                'errors' => $errors,
                'valid' => $errors === [],
            ];
        }

        return [
            'ok' => true,
            'header' => $header,
            'expected_columns' => $expected,
            'rows' => $rows,
            'valid_count' => count(array_filter($rows, static fn(array $r): bool => $r['valid'])),
            'invalid_count' => count(array_filter($rows, static fn(array $r): bool => !$r['valid'])),
        ];
    }
}

if (!function_exists('portal_activity_import_csv_apply')) {
    function portal_activity_import_csv_apply(int $activityId, string $csvText): array
    {
        $preview = portal_activity_import_csv_preview($csvText);
        if (empty($preview['ok'])) {
            return $preview;
        }
        $ctx = portal_activity_require_draft_version($activityId);
        if (empty($ctx['ok'])) {
            return $ctx;
        }

        $sectionCache = [];
        $imported = 0;
        $skipped = 0;

        foreach ($preview['rows'] as $row) {
            if (!$row['valid']) {
                $skipped++;
                continue;
            }
            $data = $row['data'];
            $sectionId = null;
            $sectionTitle = trim((string) ($data['section'] ?? ''));
            if ($sectionTitle !== '') {
                if (!isset($sectionCache[$sectionTitle])) {
                    $created = portal_activity_add_section($activityId, $sectionTitle);
                    $sectionCache[$sectionTitle] = (int) ($created['section_id'] ?? 0) ?: null;
                }
                $sectionId = $sectionCache[$sectionTitle];
            }

            $type = (string) $row['question_type'];
            $settings = [];
            $options = [];
            $correct = trim((string) ($data['correct_answer'] ?? ''));

            foreach (['a' => 'option_a', 'b' => 'option_b', 'c' => 'option_c', 'd' => 'option_d'] as $letter => $key) {
                $text = trim((string) ($data[$key] ?? ''));
                if ($text === '') {
                    continue;
                }
                $isCorrect = strcasecmp($correct, $letter) === 0
                    || strcasecmp($correct, $text) === 0
                    || str_contains(strtolower($correct), strtolower($letter));
                $options[] = [
                    'option_text_html' => portal_escape($text),
                    'is_correct' => $isCorrect ? 1 : 0,
                    'credit' => $isCorrect ? 1 : 0,
                ];
            }

            if ($type === 'short_text') {
                $accepted = array_filter(array_map('trim', explode('|', (string) ($data['accepted_answers'] ?? $correct))));
                $settings['accepted_answers'] = array_values($accepted);
            }
            if ($type === 'numeric' && $correct !== '') {
                $settings['correct_value'] = (float) $correct;
                $settings['tolerance'] = 0;
            }

            $result = portal_activity_add_question(
                $activityId,
                $type,
                '<p>' . portal_escape(portal_activity_csv_safe((string) $data['prompt'])) . '</p>',
                $sectionId,
                [
                    'points' => (float) ($data['points'] ?? 1),
                    'explanation_html' => trim((string) ($data['explanation'] ?? '')) !== ''
                        ? '<p>' . portal_escape((string) $data['explanation']) . '</p>' : '',
                    'difficulty' => (string) ($data['difficulty'] ?? 'medium'),
                    'tags' => (string) ($data['tags'] ?? ''),
                    'settings' => $settings,
                    'options' => $options,
                    'skip_default_options' => $options !== [],
                ]
            );
            if (!empty($result['ok'])) {
                $imported++;
            } else {
                $skipped++;
            }
        }

        portal_activity_audit($activityId, 'csv_imported', ['imported' => $imported, 'skipped' => $skipped]);

        return [
            'ok' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'draft_only' => true,
        ];
    }
}

if (!function_exists('portal_activity_export_definition_json')) {
    function portal_activity_export_definition_json(int $activityId): array
    {
        $activity = portal_activity_find($activityId);
        if ($activity === null) {
            return ['ok' => false, 'error' => 'Activity not found.'];
        }
        portal_activity_require_manage($activity);

        $versionId = portal_activity_draft_version_id($activityId)
            ?? portal_activity_published_version_id($activityId);
        if ($versionId === null) {
            return ['ok' => false, 'error' => 'No version to export.'];
        }

        $tree = portal_activity_load_version_tree($versionId, true);
        $questions = [];
        foreach ($tree['questions'] as $q) {
            $qid = (int) $q['id'];
            $questions[] = [
                'question_type' => $q['question_type'],
                'prompt_html' => $q['prompt_html'],
                'explanation_html' => $q['explanation_html'],
                'hint_html' => $q['hint_html'],
                'teacher_notes' => $q['teacher_notes'],
                'points' => (float) $q['points'],
                'difficulty' => $q['difficulty'],
                'tags' => $q['tags'],
                'required' => (int) $q['required'],
                'manual_marking' => (int) $q['manual_marking'],
                'settings' => portal_activity_json_decode((string) $q['settings_json'], []) ?: [],
                'section_id' => $q['section_id'],
                'sort_order' => (int) $q['sort_order'],
                'options' => array_map(static function (array $o): array {
                    return [
                        'option_text_html' => $o['option_text_html'],
                        'is_correct' => (int) ($o['is_correct'] ?? 0),
                        'credit' => (float) ($o['credit'] ?? 0),
                        'feedback_html' => $o['feedback_html'] ?? '',
                        'match_key' => $o['match_key'] ?? '',
                        'sort_order' => (int) ($o['sort_order'] ?? 0),
                    ];
                }, $tree['options_by_question'][$qid] ?? []),
            ];
        }

        $settings = [];
        foreach (portal_activity_settings_fields() as $field) {
            $settings[$field] = $activity[$field] ?? null;
        }

        return [
            'ok' => true,
            'export' => [
                'format' => 'portal_activity_v1',
                'exported_at' => portal_activity_now_utc(),
                'mode' => $activity['mode'],
                'title' => $activity['title'],
                'settings' => $settings,
                'sections' => array_map(static function (array $s): array {
                    return [
                        'id' => (int) $s['id'],
                        'title' => $s['title'],
                        'instructions_html' => $s['instructions_html'],
                        'sort_order' => (int) $s['sort_order'],
                    ];
                }, $tree['sections']),
                'questions' => $questions,
            ],
        ];
    }
}
