<?php
declare(strict_types=1);

/**
 * Comprehensive CLI checks for the Activities system.
 * Run: C:\xampp\php\php.exe tests/activity_system_check.php
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;

function expect_true(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

function expect_eq($a, $b, string $label): void
{
    expect_true($a === $b, $label . ' (got ' . var_export($a, true) . ')');
}

function expect_approx(?float $got, float $expected, string $label, float $eps = 0.01): void
{
    expect_true($got !== null && abs($got - $expected) <= $eps, $label . ' (got ' . var_export($got, true) . ')');
}

/**
 * @param array<string, mixed> $user
 */
function act_login_as(array $user): void
{
    $_SESSION['portal_user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'email' => (string) ($user['email'] ?? ''),
        'name' => (string) ($user['name'] ?? ''),
        'year' => (string) ($user['year'] ?? ''),
        'programme' => (string) ($user['programme'] ?? ''),
        'initials' => (string) ($user['initials'] ?? ''),
        'role' => (string) ($user['role'] ?? 'student'),
    ];
    $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');
}

function act_logout(): void
{
    unset($_SESSION['portal_user'], $_SESSION['portal_login_at']);
}

/**
 * @return array{id:int, username:string, email:string, name:string, year:string, programme:string, initials:string, role:string}
 */
function act_insert_user(PDO $db, string $username, string $role): array
{
    $db->prepare(
        'INSERT INTO users (username, email, password_hash, name, year, programme, initials, role)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([
        $username,
        $username . '@activity.test',
        password_hash('ActivityTestPass123!', PASSWORD_DEFAULT),
        'Activity ' . ucfirst($role),
        'Year 11',
        'Activity Test',
        strtoupper(substr($role, 0, 2)),
        $role,
    ]);
    $id = (int) $db->lastInsertId();
    return [
        'id' => $id,
        'username' => $username,
        'email' => $username . '@activity.test',
        'name' => 'Activity ' . ucfirst($role),
        'year' => 'Year 11',
        'programme' => 'Activity Test',
        'initials' => strtoupper(substr($role, 0, 2)),
        'role' => $role,
    ];
}

$slug = 'activity-test-' . bin2hex(random_bytes(4));
$adminUser = $teacherUser = $studentUser = null;
$courseId = 0;
$folderId = 0;
$createdActivityIds = [];
$db = portal_db();

try {
    echo "=== Fixtures ({$slug}) ===\n";

    $adminUser = act_insert_user($db, $slug . '-admin', 'admin');
    $teacherUser = act_insert_user($db, $slug . '-teacher', 'teacher');
    $studentUser = act_insert_user($db, $slug . '-student', 'student');

    $db->prepare(
        'INSERT INTO courses
         (slug, code, title, full_title, summary, year_group, term, status, status_label, accent, meeting, room, notice, student_count)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $slug,
        'ACT-TEST',
        'Activity Test Course',
        'Activity System Test Course',
        'Temporary course for activity_system_check.',
        'Test',
        'Test term',
        'open',
        'Open',
        '#c1202f',
        'Mon | 09:00',
        'Lab',
        '',
        0,
    ]);
    $courseId = (int) $db->lastInsertId();

    $db->prepare('INSERT INTO course_teachers (course_id, user_id, assignment_role) VALUES (?,?,?)')
        ->execute([$courseId, $teacherUser['id'], 'teacher']);
    $db->prepare('INSERT INTO enrollments (user_id, course_id) VALUES (?,?)')
        ->execute([$studentUser['id'], $courseId]);
    $db->prepare('INSERT INTO course_folders (course_id, title, description, locked) VALUES (?,?,?,0)')
        ->execute([$courseId, 'Activity Test Folder', 'Fixture folder']);
    $folderId = (int) $db->lastInsertId();

    // ── Permissions ──────────────────────────────────────────────────────────
    echo "\n=== Permissions ===\n";

    act_login_as($adminUser);
    $created = portal_activity_create($courseId, $folderId, 'Admin Assessment', 'assessment', $adminUser['id']);
    expect_true(!empty($created['ok']), 'admin can create activity');
    $adminActivityId = (int) ($created['activity_id'] ?? 0);
    $createdActivityIds[] = $adminActivityId;

    act_login_as($teacherUser);
    $createdT = portal_activity_create($courseId, $folderId, 'Teacher Quiz', 'quiz', $teacherUser['id']);
    expect_true(!empty($createdT['ok']), 'assigned teacher can create activity');
    $teacherActivityId = (int) ($createdT['activity_id'] ?? 0);
    $createdActivityIds[] = $teacherActivityId;

    act_login_as($studentUser);
    $deniedCreate = portal_activity_create($courseId, $folderId, 'Student Attempt', 'quiz', $studentUser['id']);
    expect_true(empty($deniedCreate['ok']), 'student cannot create activity');
    expect_true(portal_can_manage_course($courseId) === false, 'student portal_can_manage_course is false');

    // ── Scoring (unit-style, no DB needed) ───────────────────────────────────
    echo "\n=== Scoring ===\n";

    $scSingle = portal_activity_score_answer(
        ['question_type' => 'single_choice', 'points' => 2, 'settings_json' => '{}'],
        [['id' => 1, 'is_correct' => 1, 'credit' => 1], ['id' => 2, 'is_correct' => 0, 'credit' => 0]],
        ['option_id' => 1]
    );
    expect_approx($scSingle, 2.0, 'single_choice correct');

    $scMc = portal_activity_score_answer(
        ['question_type' => 'multiple_choice', 'points' => 4, 'settings_json' => '{}'],
        [
            ['id' => 10, 'is_correct' => 1, 'credit' => 1],
            ['id' => 11, 'is_correct' => 1, 'credit' => 1],
            ['id' => 12, 'is_correct' => 0, 'credit' => 0],
        ],
        ['option_ids' => [10, 11]]
    );
    expect_approx($scMc, 4.0, 'multiple_choice both correct');

    $scTf = portal_activity_score_answer(
        ['question_type' => 'true_false', 'points' => 1, 'settings_json' => '{}'],
        [['id' => 1, 'option_text_html' => 'True', 'is_correct' => 1, 'credit' => 1], ['id' => 2, 'option_text_html' => 'False', 'is_correct' => 0, 'credit' => 0]],
        ['option_id' => 1]
    );
    expect_approx($scTf, 1.0, 'true_false correct');

    $scShort = portal_activity_score_answer(
        ['question_type' => 'short_text', 'points' => 1, 'settings_json' => portal_activity_json_encode(['accepted_answers' => ['Paris', 'paris']])],
        [],
        ['text' => 'Paris']
    );
    expect_approx($scShort, 1.0, 'short_text accepted answer');

    $scNum = portal_activity_score_answer(
        ['question_type' => 'numeric', 'points' => 1, 'settings_json' => portal_activity_json_encode([
            'correct_value' => 10,
            'absolute_tolerance' => 0.5,
        ])],
        [],
        ['value' => 10.2]
    );
    expect_approx($scNum, 1.0, 'numeric within absolute_tolerance');

    $scBlank = portal_activity_score_answer(
        ['question_type' => 'fill_blank', 'points' => 2, 'settings_json' => portal_activity_json_encode([
            'blanks' => [
                ['accepted' => ['water']],
                ['accepted' => ['H2O', 'h2o']],
            ],
        ])],
        [],
        ['blanks' => ['water', 'H2O']]
    );
    expect_approx($scBlank, 2.0, 'fill_blank both blanks');

    $scOrder = portal_activity_score_answer(
        ['question_type' => 'ordering', 'points' => 3, 'settings_json' => portal_activity_json_encode([
            'correct_order' => ['a', 'b', 'c'],
        ])],
        [],
        ['order' => ['a', 'b', 'c']]
    );
    expect_approx($scOrder, 3.0, 'ordering full credit');

    $scMatch = portal_activity_score_answer(
        ['question_type' => 'matching', 'points' => 2, 'settings_json' => portal_activity_json_encode([
            'pairs' => [
                ['left' => 'cat', 'right' => 'meow'],
                ['left' => 'dog', 'right' => 'bark'],
            ],
        ])],
        [],
        ['matches' => ['cat' => 'meow', 'dog' => 'bark']]
    );
    expect_approx($scMatch, 2.0, 'matching full credit');

    $scLong = portal_activity_score_answer(
        ['question_type' => 'long_response', 'points' => 5, 'settings_json' => '{}'],
        [],
        ['text' => 'essay']
    );
    expect_true($scLong === null, 'long_response returns null');

    // ── Answer leakage + publish assessment ──────────────────────────────────
    echo "\n=== Answer leakage ===\n";

    act_login_as($teacherUser);
    $assess = portal_activity_create($courseId, $folderId, 'Leak Check Assessment', 'assessment', $teacherUser['id']);
    expect_true(!empty($assess['ok']), 'create assessment for leakage test');
    $assessId = (int) $assess['activity_id'];
    $createdActivityIds[] = $assessId;

    $qAdd = portal_activity_add_question($assessId, 'single_choice', '<p>Capital of France?</p>', null, [
        'points' => 1,
        'explanation_html' => '<p>Paris is the capital.</p>',
        'teacher_notes' => 'Do not show this note to students',
        'options' => [
            ['text' => 'Lyon', 'is_correct' => 0],
            ['text' => 'Paris', 'is_correct' => 1],
            ['text' => 'Nice', 'is_correct' => 0],
        ],
        'settings' => [
            'accepted_answers' => ['should-not-leak'],
            'correct_value' => 42,
        ],
    ]);
    expect_true(!empty($qAdd['ok']), 'add single_choice with secrets');

    $actRow = portal_activity_find($assessId);
    portal_activity_save_settings($assessId, [
        'max_attempts' => 1,
        'integrity_enabled' => 1,
        'feedback_policy' => 'when_released',
        'results_released' => 0,
    ], (int) ($actRow['version'] ?? 1));

    $published = portal_activity_publish($assessId);
    expect_true(!empty($published['ok']), 'publish assessment');
    $publishedVersionId = (int) ($published['published_version_id'] ?? 0);

    act_login_as($studentUser);
    $start = portal_activity_start_attempt($assessId, $studentUser['id'], 'I acknowledge integrity notice');
    expect_true(!empty($start['ok']), 'student can start assessment attempt');
    $attemptId = (int) ($start['attempt']['id'] ?? 0);
    $token = (string) ($start['token'] ?? '');
    $questionId = (int) ($start['questions'][0]['id'] ?? 0);

    $player = portal_activity_get_attempt_for_player($attemptId, $studentUser['id']);
    expect_true(!empty($player['ok']), 'get_attempt_for_player ok');
    $playerJson = portal_activity_json_encode($player);

    expect_true(!str_contains($playerJson, '"is_correct"'), 'player JSON has no is_correct');
    expect_true(!str_contains($playerJson, '"teacher_notes"'), 'player JSON has no teacher_notes');
    expect_true(!str_contains($playerJson, '"correct_value"'), 'player JSON has no correct_value');
    expect_true(!str_contains($playerJson, '"accepted_answers"'), 'player JSON has no accepted_answers');
    expect_true(!str_contains($playerJson, 'Paris is the capital'), 'player JSON has no non-empty explanation content');
    $emptyExplanations = true;
    foreach ($player['questions'] ?? [] as $pq) {
        if (trim((string) ($pq['explanation_html'] ?? '')) !== '') {
            $emptyExplanations = false;
        }
    }
    expect_true($emptyExplanations, 'player questions have empty explanation_html while in progress');
    // Explicit empty explanation_html is fine; non-empty secret text must not appear.
    expect_true(!str_contains($playerJson, 'Do not show this note'), 'teacher notes text absent');
    expect_true(!str_contains($playerJson, 'should-not-leak'), 'accepted_answers value absent');

    // ── Attempts ─────────────────────────────────────────────────────────────
    echo "\n=== Attempts ===\n";

    // Submit first attempt so max_attempts=1 blocks a new start.
    $submit = portal_activity_submit_attempt($attemptId, $studentUser['id'], $token);
    expect_true(!empty($submit['ok']), 'submit first attempt');

    $second = portal_activity_start_attempt($assessId, $studentUser['id'], 'ack again');
    expect_true(empty($second['ok']), 'max_attempts=1 blocks second start');

    // Expire auto-submit on a separate quiz with time limit / forced expires_at.
    act_login_as($teacherUser);
    $timed = portal_activity_create($courseId, $folderId, 'Timed Quiz', 'quiz', $teacherUser['id']);
    expect_true(!empty($timed['ok']), 'create timed quiz');
    $timedId = (int) $timed['activity_id'];
    $createdActivityIds[] = $timedId;
    portal_activity_add_question($timedId, 'true_false', '<p>Sky is blue?</p>');
    $timedRow = portal_activity_find($timedId);
    portal_activity_save_settings($timedId, ['max_attempts' => 0], (int) ($timedRow['version'] ?? 1));
    $pubTimed = portal_activity_publish($timedId);
    expect_true(!empty($pubTimed['ok']), 'publish timed quiz');

    act_login_as($studentUser);
    $timedStart = portal_activity_start_attempt($timedId, $studentUser['id']);
    expect_true(!empty($timedStart['ok']), 'start timed attempt');
    $timedAttemptId = (int) ($timedStart['attempt']['id'] ?? 0);
    $db->prepare("UPDATE activity_attempts SET expires_at = datetime('now', '-2 minutes') WHERE id = ?")
        ->execute([$timedAttemptId]);
    $timedRowAttempt = $db->prepare('SELECT * FROM activity_attempts WHERE id = ?');
    $timedRowAttempt->execute([$timedAttemptId]);
    $expired = portal_activity_expire_if_needed($timedRowAttempt->fetch(PDO::FETCH_ASSOC) ?: []);
    expect_true(
        in_array((string) ($expired['status'] ?? ''), ['auto_submitted', 'awaiting_manual_marking', 'marked', 'released'], true),
        'expire_if_needed auto-submits past expires_at (status=' . ($expired['status'] ?? '') . ')'
    );
    expect_true(trim((string) ($expired['submitted_at'] ?? '')) !== '', 'expired attempt has submitted_at set');

    // Revision conflict + forged question + bad token on a fresh practice activity.
    act_login_as($teacherUser);
    $practice = portal_activity_create($courseId, $folderId, 'Practice Save Checks', 'practice', $teacherUser['id']);
    expect_true(!empty($practice['ok']), 'create practice for save checks');
    $practiceId = (int) $practice['activity_id'];
    $createdActivityIds[] = $practiceId;
    portal_activity_add_question($practiceId, 'short_text', '<p>Colour of grass?</p>', null, [
        'settings' => ['accepted_answers' => ['green']],
    ]);
    $pracRow = portal_activity_find($practiceId);
    portal_activity_save_settings($practiceId, ['max_attempts' => 0], (int) ($pracRow['version'] ?? 1));
    expect_true(!empty(portal_activity_publish($practiceId)['ok']), 'publish practice');

    act_login_as($studentUser);
    $pStart = portal_activity_start_attempt($practiceId, $studentUser['id']);
    expect_true(!empty($pStart['ok']), 'start practice attempt');
    $pAttemptId = (int) ($pStart['attempt']['id'] ?? 0);
    $pToken = (string) ($pStart['token'] ?? '');
    $pQid = (int) ($pStart['questions'][0]['id'] ?? 0);

    $save1 = portal_activity_save_answer($pAttemptId, $studentUser['id'], $pQid, ['text' => 'green'], 1, $pToken);
    expect_true(!empty($save1['ok']), 'first save_answer ok');
    $rev = (int) ($save1['revision'] ?? 1);

    $conflict = portal_activity_save_answer(
        $pAttemptId,
        $studentUser['id'],
        $pQid,
        ['text' => 'blue'],
        max(0, $rev - 1),
        $pToken
    );
    expect_true(empty($conflict['ok']) && !empty($conflict['conflict']), 'older revision rejected as conflict');

    $forgedQ = portal_activity_save_answer(
        $pAttemptId,
        $studentUser['id'],
        99999999,
        ['text' => 'x'],
        $rev + 1,
        $pToken
    );
    expect_true(empty($forgedQ['ok']), 'forged question_id rejected');

    $badToken = portal_activity_save_answer(
        $pAttemptId,
        $studentUser['id'],
        $pQid,
        ['text' => 'green'],
        $rev + 1,
        'forged-token-not-valid'
    );
    expect_true(empty($badToken['ok']), 'wrong attempt token rejected');

    // ── Integrity ────────────────────────────────────────────────────────────
    echo "\n=== Integrity ===\n";

    // Practice should ignore integrity recording.
    $ign = portal_activity_record_integrity_event(
        $pAttemptId,
        $studentUser['id'],
        'paste_attempt',
        'idem-practice-' . bin2hex(random_bytes(4)),
        $pQid,
        'external_or_unknown',
        ['clipboard' => 'SECRET_CLIPBOARD_TEXT', 'text' => 'also secret', 'extra' => 'ok']
    );
    expect_true(!empty($ign['ok']) && !empty($ign['ignored']), 'integrity events ignored for practice');

    // Assessment attempt already submitted — create another assessment for integrity.
    act_login_as($teacherUser);
    $intAct = portal_activity_create($courseId, $folderId, 'Integrity Assessment', 'assessment', $teacherUser['id']);
    $intId = (int) ($intAct['activity_id'] ?? 0);
    $createdActivityIds[] = $intId;
    portal_activity_add_question($intId, 'true_false', '<p>Integrity Q?</p>');
    $intRow = portal_activity_find($intId);
    portal_activity_save_settings($intId, [
        'max_attempts' => 0,
        'integrity_enabled' => 1,
    ], (int) ($intRow['version'] ?? 1));
    expect_true(!empty(portal_activity_publish($intId)['ok']), 'publish integrity assessment');

    act_login_as($studentUser);
    $iStart = portal_activity_start_attempt($intId, $studentUser['id'], 'ack');
    expect_true(!empty($iStart['ok']), 'start integrity assessment');
    $iAttemptId = (int) ($iStart['attempt']['id'] ?? 0);
    $iQid = (int) ($iStart['questions'][0]['id'] ?? 0);

    $rec = portal_activity_record_integrity_event(
        $iAttemptId,
        $studentUser['id'],
        'paste_attempt',
        'idem-assess-' . bin2hex(random_bytes(4)),
        $iQid,
        'external_or_unknown',
        [
            'clipboard' => 'MUST_NOT_PERSIST',
            'text' => 'MUST_NOT_PERSIST',
            'paste_text' => 'MUST_NOT_PERSIST',
            'html' => '<b>MUST_NOT_PERSIST</b>',
            'content' => 'MUST_NOT_PERSIST',
            'count' => 1,
        ]
    );
    expect_true(!empty($rec['ok']) && empty($rec['ignored']), 'integrity event recorded for assessment');

    $metaStmt = $db->prepare(
        'SELECT event_metadata_json FROM activity_integrity_events WHERE attempt_id = ? ORDER BY id DESC LIMIT 1'
    );
    $metaStmt->execute([$iAttemptId]);
    $metaJson = (string) $metaStmt->fetchColumn();
    expect_true(!str_contains($metaJson, 'MUST_NOT_PERSIST'), 'metadata does not store clipboard text');
    expect_true(str_contains($metaJson, '"count"'), 'non-sensitive metadata retained');

    expect_eq(portal_activity_integrity_summary_label([]), 'No notable signals', 'summary: none');
    expect_eq(portal_activity_integrity_summary_label([1, 2]), 'A few signals', 'summary: few');
    expect_eq(portal_activity_integrity_summary_label(range(1, 5)), 'Review recommended', 'summary: review');
    expect_eq(portal_activity_integrity_summary_label(range(1, 12)), 'High number of signals', 'summary: high');

    $banned = ['Cheater', 'Cheating', 'Fraud', 'Guilty'];
    $eventTypes = [
        'paste_attempt', 'paste_blocked', 'copy_attempt', 'visibility_hidden',
        'window_blur', 'fullscreen_exit', 'multiple_tab_detected', 'unusual_answer_burst', 'unknown_xyz',
    ];
    $labelsClean = true;
    foreach ($eventTypes as $et) {
        $label = portal_activity_integrity_event_label($et);
        foreach ($banned as $word) {
            if (stripos($label, $word) !== false) {
                $labelsClean = false;
            }
        }
    }
    expect_true($labelsClean, 'integrity_event_label never contains Cheater/Cheating/Fraud/Guilty');

    // ── Written-answer marking assistance ────────────────────────────────────
    echo "\n=== Written marking assist ===\n";

    act_login_as($teacherUser);
    $written = portal_activity_create($courseId, $folderId, 'Written Assist Quiz', 'quiz', $teacherUser['id']);
    expect_true(!empty($written['ok']), 'create quiz for written marking');
    $writtenId = (int) $written['activity_id'];
    $createdActivityIds[] = $writtenId;

    $modelAnswer = 'Running raises the heart rate because muscles need more oxygen to release energy.';
    $wq = portal_activity_add_question($writtenId, 'long_response', '<p>Why does running raise your heart rate?</p>', null, [
        'points' => 5,
        'settings' => [
            'expected_answer' => $modelAnswer,
            'keywords' => ['heart rate|pulse', 'oxygen', 'energy'],
        ],
    ]);
    expect_true(!empty($wq['ok']), 'add long_response with expected answer');
    $wQidSource = (int) ($wq['question_id'] ?? 0);

    $wStored = $db->prepare('SELECT manual_marking, settings_json FROM activity_questions WHERE id = ?');
    $wStored->execute([$wQidSource]);
    $wStoredRow = $wStored->fetch(PDO::FETCH_ASSOC) ?: [];
    expect_eq((int) ($wStoredRow['manual_marking'] ?? 0), 1, 'long_response forced to teacher-marked');
    expect_true(
        str_contains((string) ($wStoredRow['settings_json'] ?? ''), 'expected_answer'),
        'expected_answer persisted in settings'
    );

    // A second, auto-marked question so we can prove auto + manual coexist.
    portal_activity_add_question($writtenId, 'true_false', '<p>Muscles use oxygen?</p>', null, [
        'points' => 2,
        'options' => [
            ['text' => 'True', 'is_correct' => 1],
            ['text' => 'False', 'is_correct' => 0],
        ],
    ]);

    $wRow = portal_activity_find($writtenId);
    portal_activity_save_settings($writtenId, ['max_attempts' => 0], (int) ($wRow['version'] ?? 1));
    expect_true(!empty(portal_activity_publish($writtenId)['ok']), 'publish written quiz');

    act_login_as($studentUser);
    $wStart = portal_activity_start_attempt($writtenId, $studentUser['id']);
    expect_true(!empty($wStart['ok']), 'start written attempt');
    $wAttemptId = (int) ($wStart['attempt']['id'] ?? 0);
    $wToken = (string) ($wStart['token'] ?? '');

    $wQid = 0;
    $tfQid = 0;
    foreach ($wStart['questions'] ?? [] as $sq) {
        if (($sq['question_type'] ?? '') === 'long_response') {
            $wQid = (int) $sq['id'];
        } elseif (($sq['question_type'] ?? '') === 'true_false') {
            $tfQid = (int) $sq['id'];
        }
    }
    expect_true($wQid > 0 && $tfQid > 0, 'both question ids resolved for the attempt');

    // The model answer and key points must never reach the student.
    $wPlayerJson = portal_activity_json_encode(portal_activity_get_attempt_for_player($wAttemptId, $studentUser['id']));
    expect_true(!str_contains($wPlayerJson, '"expected_answer"'), 'player JSON has no expected_answer key');
    expect_true(!str_contains($wPlayerJson, '"keywords"'), 'player JSON has no keywords key');
    expect_true(!str_contains($wPlayerJson, 'muscles need more oxygen'), 'model answer text absent from player JSON');

    portal_activity_save_answer(
        $wAttemptId,
        $studentUser['id'],
        $wQid,
        ['text' => 'When you run your heart rate increases because the muscles need more oxygen so they can release energy.'],
        1,
        $wToken
    );
    $wSubmit = portal_activity_submit_attempt($wAttemptId, $studentUser['id'], $wToken);
    expect_true(!empty($wSubmit['ok']), 'submit written attempt');

    $wAttemptRow = $db->prepare('SELECT status, score, percentage FROM activity_attempts WHERE id = ?');
    $wAttemptRow->execute([$wAttemptId]);
    $wAfter = $wAttemptRow->fetch(PDO::FETCH_ASSOC) ?: [];
    expect_eq((string) ($wAfter['status'] ?? ''), 'awaiting_manual_marking', 'written attempt waits for teacher marking');
    expect_true($wAfter['percentage'] === null, 'no percentage stored while written answer is unmarked');

    // Suggestion engine
    $wQuestionRow = $db->prepare('SELECT * FROM activity_questions WHERE id = ?');
    $wQuestionRow->execute([$wQid]);
    $wQuestion = $wQuestionRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $answerRow = $db->prepare('SELECT answer_json FROM activity_answers WHERE attempt_id = ? AND question_id = ?');
    $answerRow->execute([$wAttemptId, $wQid]);
    $storedAnswer = portal_activity_json_decode((string) $answerRow->fetchColumn(), null);

    $suggestion = portal_activity_written_suggestion($wQuestion, $storedAnswer);
    expect_true(is_array($suggestion), 'suggestion generated for written answer');
    expect_eq((string) ($suggestion['verdict'] ?? ''), 'likely_correct', 'close paraphrase judged likely_correct');
    expect_true((float) ($suggestion['suggested_score'] ?? -1) > 3.5, 'suggested score is generous for a close match');
    expect_eq(count($suggestion['keyword_misses'] ?? ['x']), 0, 'all key points detected');

    $wrongSuggestion = portal_activity_written_suggestion(
        $wQuestion,
        ['text' => 'Photosynthesis happens in chloroplasts using sunlight.']
    );
    expect_eq((string) ($wrongSuggestion['verdict'] ?? ''), 'likely_incorrect', 'unrelated answer judged likely_incorrect');
    expect_approx((float) $wrongSuggestion['suggested_score'], 0.0, 'unrelated answer suggests zero');

    $blankSuggestion = portal_activity_written_suggestion($wQuestion, ['text' => '']);
    expect_eq((string) ($blankSuggestion['verdict'] ?? ''), 'blank', 'empty answer reported as blank');

    // Opting out and missing configuration both disable suggestions.
    expect_true(
        portal_activity_written_suggestion(
            ['question_type' => 'long_response', 'points' => 5, 'settings' => []],
            ['text' => 'anything']
        ) === null,
        'no suggestion without an expected answer'
    );
    expect_true(
        portal_activity_written_suggestion(
            ['question_type' => 'long_response', 'points' => 5, 'settings' => [
                'expected_answer' => $modelAnswer,
                'auto_suggest' => 0,
            ]],
            ['text' => 'anything']
        ) === null,
        'no suggestion when auto_suggest is off'
    );

    // Similarity helper sanity
    expect_approx(portal_activity_text_similarity($modelAnswer, $modelAnswer), 1.0, 'identical text scores 1.0');
    expect_approx(portal_activity_text_similarity($modelAnswer, ''), 0.0, 'empty answer scores 0.0');
    expect_true(
        portal_activity_text_similarity($modelAnswer, 'The heart rate rises as muscles demand oxygen for energy.')
        > portal_activity_text_similarity($modelAnswer, 'I really enjoyed this topic in class.'),
        'relevant answer scores above waffle'
    );
    expect_eq(portal_activity_text_stem('running'), 'run', 'stemmer handles doubled consonants');

    // Teacher-facing answer view shows readable content, not raw ids.
    $tfQuestionRow = $db->prepare('SELECT * FROM activity_questions WHERE id = ?');
    $tfQuestionRow->execute([$tfQid]);
    $tfQuestion = $tfQuestionRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $tfOptions = $db->prepare('SELECT * FROM activity_question_options WHERE question_id = ? ORDER BY sort_order, id');
    $tfOptions->execute([$tfQid]);
    $tfOptionRows = $tfOptions->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $trueOptionId = 0;
    foreach ($tfOptionRows as $opt) {
        if (!empty($opt['is_correct'])) {
            $trueOptionId = (int) $opt['id'];
        }
    }
    $choiceView = portal_activity_answer_view($tfQuestion, $tfOptionRows, ['option_id' => $trueOptionId]);
    expect_eq((string) $choiceView['kind'], 'options', 'choice answer view uses options kind');
    $chosenCorrect = false;
    foreach ($choiceView['items'] as $item) {
        if (!empty($item['chosen']) && !empty($item['correct'])) {
            $chosenCorrect = true;
        }
    }
    expect_true($chosenCorrect, 'answer view marks the chosen correct option');

    $textView = portal_activity_answer_view($wQuestion, [], $storedAnswer);
    expect_eq((string) $textView['kind'], 'text', 'written answer view uses text kind');
    expect_true(($textView['word_count'] ?? 0) > 5, 'written answer view counts words');
    expect_true(
        str_contains((string) $textView['expected_text'], 'oxygen'),
        'teacher answer view carries the expected answer'
    );

    // Marking a written answer completes the grade.
    act_login_as($teacherUser);
    $db->prepare(
        "UPDATE activity_answers SET manual_score = ?, final_score = ?, marked_at = datetime('now')
         WHERE attempt_id = ? AND question_id = ?"
    )->execute([4.0, 4.0, $wAttemptId, $wQid]);
    portal_activity_score_attempt($wAttemptId);
    $wAttemptRow->execute([$wAttemptId]);
    $wMarked = $wAttemptRow->fetch(PDO::FETCH_ASSOC) ?: [];
    expect_eq((string) ($wMarked['status'] ?? ''), 'marked', 'attempt becomes marked once written answer is scored');
    expect_true($wMarked['percentage'] !== null, 'percentage appears after marking');

    // ── Gamification ─────────────────────────────────────────────────────────
    echo "\n=== Gamification ===\n";

    $xpKey = 'test_xp:' . $slug . ':' . $studentUser['id'];
    $firstXp = portal_activity_award_xp($studentUser['id'], $courseId, $practiceId, $pAttemptId, 'test_event', 10, $xpKey);
    $secondXp = portal_activity_award_xp($studentUser['id'], $courseId, $practiceId, $pAttemptId, 'test_event', 10, $xpKey);
    expect_true($firstXp === true, 'award_xp first call succeeds');
    expect_true($secondXp === false, 'award_xp second call idempotent (no double)');
    $xpCount = $db->prepare('SELECT COUNT(*) FROM gamification_events WHERE unique_reward_key = ?');
    $xpCount->execute([$xpKey]);
    expect_eq((int) $xpCount->fetchColumn(), 1, 'unique_reward_key stored once');

    // ── Media ────────────────────────────────────────────────────────────────
    echo "\n=== Media ===\n";

    $allowed = portal_activity_media_allowed_extensions();
    $allExt = array_merge(...array_values($allowed));
    expect_true(!in_array('svg', $allExt, true), 'svg extension not allowed');

    expect_true(portal_activity_media_path_safe('../etc/passwd') === null, 'path_safe rejects ../ traversal');
    expect_true(portal_activity_media_path_safe('activities/../secret') === null, 'path_safe rejects activities/../');
    expect_true(portal_activity_media_path_safe('courses/1/file.png') === null, 'path_safe rejects non-activities prefix');

    act_login_as($teacherUser);
    $emptyMedia = portal_activity_store_media(
        $courseId,
        $practiceId,
        null,
        null,
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'name' => 'x.png', 'size' => 0],
        'image'
    );
    expect_true(empty($emptyMedia['ok']), 'store_media rejects empty/missing upload');

    $svgMedia = portal_activity_store_media(
        $courseId,
        $practiceId,
        null,
        null,
        ['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__, 'name' => 'evil.svg', 'size' => 10],
        'image'
    );
    expect_true(empty($svgMedia['ok']), 'store_media rejects .svg (or non-uploaded file)');

    // ── CSV ──────────────────────────────────────────────────────────────────
    echo "\n=== CSV ===\n";

    expect_eq(portal_activity_csv_safe('=CMD()'), "'=CMD()", 'csv_safe prefixes =');
    expect_eq(portal_activity_csv_safe('+1+1'), "'+1+1", 'csv_safe prefixes +');
    expect_eq(portal_activity_csv_safe('-1'), "'-1", 'csv_safe prefixes -');
    expect_eq(portal_activity_csv_safe('@SUM(A1)'), "'@SUM(A1)", 'csv_safe prefixes @');
    expect_eq(portal_activity_csv_safe('Normal'), 'Normal', 'csv_safe leaves normal text');

    $csvPreview = portal_activity_import_csv_preview(
        "question_type,prompt,option_a,option_b,correct_answer,points\n"
        . "not_a_real_type,Hello?,A,B,A,1\n"
        . "single_choice,Real?,Yes,No,A,1\n"
    );
    expect_true(!empty($csvPreview['ok']), 'csv preview ok');
    expect_true(($csvPreview['invalid_count'] ?? 0) >= 1, 'csv preview rejects invalid types');
    $invalidRow = null;
    foreach ($csvPreview['rows'] as $row) {
        if (($row['question_type'] ?? '') === 'not_a_real_type' || in_array('Invalid question type.', $row['errors'] ?? [], true)) {
            $invalidRow = $row;
            break;
        }
    }
    expect_true($invalidRow !== null && empty($invalidRow['valid']), 'invalid type row marked invalid');

    // ── Versioning ───────────────────────────────────────────────────────────
    echo "\n=== Versioning ===\n";

    act_login_as($teacherUser);
    // Use assessment that already has a submitted attempt ($assessId / $publishedVersionId).
    expect_true($publishedVersionId > 0, 'have published version id from earlier attempt');
    $attemptVersionStmt = $db->prepare('SELECT activity_version_id FROM activity_attempts WHERE id = ?');
    $attemptVersionStmt->execute([$attemptId]);
    $attemptVersionId = (int) $attemptVersionStmt->fetchColumn();
    expect_eq($attemptVersionId, $publishedVersionId, 'attempt locked to original published version');

    // Edit draft and republish — creates a new published version.
    portal_activity_add_question($assessId, 'true_false', '<p>New version question?</p>');
    $repub = portal_activity_publish($assessId);
    expect_true(!empty($repub['ok']), 'republish after attempt creates new version');
    $newPublishedId = (int) ($repub['published_version_id'] ?? 0);
    expect_true($newPublishedId > 0 && $newPublishedId !== $publishedVersionId, 'new published version id differs');

    $attemptVersionStmt->execute([$attemptId]);
    expect_eq((int) $attemptVersionStmt->fetchColumn(), $publishedVersionId, 'old attempt stays on original version_id');

    $statusStmt = $db->prepare('SELECT status FROM activity_versions WHERE id = ?');
    $statusStmt->execute([$publishedVersionId]);
    expect_eq((string) $statusStmt->fetchColumn(), 'superseded', 'previous published version superseded');
} catch (Throwable $e) {
    $failures++;
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    echo "\n=== Cleanup ===\n";
    act_logout();
    try {
        if ($courseId > 0) {
            // Explicit child cleanup then course (defensive if cascades miss anything).
            $aidList = $db->prepare('SELECT id FROM course_activities WHERE course_id = ?');
            $aidList->execute([$courseId]);
            $aids = array_map('intval', $aidList->fetchAll(PDO::FETCH_COLUMN) ?: []);
            foreach ($aids as $aid) {
                $db->prepare(
                    'DELETE FROM activity_integrity_events WHERE attempt_id IN (SELECT id FROM activity_attempts WHERE activity_id = ?)'
                )->execute([$aid]);
                $db->prepare(
                    'DELETE FROM activity_answers WHERE attempt_id IN (SELECT id FROM activity_attempts WHERE activity_id = ?)'
                )->execute([$aid]);
                $db->prepare('DELETE FROM activity_attempts WHERE activity_id = ?')->execute([$aid]);
                $db->prepare('DELETE FROM activity_audit_events WHERE activity_id = ?')->execute([$aid]);
                $db->prepare('DELETE FROM activity_media WHERE activity_id = ?')->execute([$aid]);
                $db->prepare('DELETE FROM gamification_events WHERE activity_id = ?')->execute([$aid]);
                $vids = $db->prepare('SELECT id FROM activity_versions WHERE activity_id = ?');
                $vids->execute([$aid]);
                foreach ($vids->fetchAll(PDO::FETCH_COLUMN) ?: [] as $vid) {
                    $qids = $db->prepare('SELECT id FROM activity_questions WHERE activity_version_id = ?');
                    $qids->execute([(int) $vid]);
                    foreach ($qids->fetchAll(PDO::FETCH_COLUMN) ?: [] as $qid) {
                        $db->prepare('DELETE FROM activity_question_options WHERE question_id = ?')->execute([(int) $qid]);
                    }
                    $db->prepare('DELETE FROM activity_questions WHERE activity_version_id = ?')->execute([(int) $vid]);
                    $db->prepare('DELETE FROM activity_sections WHERE activity_version_id = ?')->execute([(int) $vid]);
                }
                $db->prepare('DELETE FROM activity_versions WHERE activity_id = ?')->execute([$aid]);
                $db->prepare('DELETE FROM course_activities WHERE id = ?')->execute([$aid]);
            }
            $db->prepare('DELETE FROM course_folder_items WHERE course_id = ?')->execute([$courseId]);
            $db->prepare('DELETE FROM course_folders WHERE course_id = ?')->execute([$courseId]);
            $db->prepare('DELETE FROM course_teachers WHERE course_id = ?')->execute([$courseId]);
            $db->prepare('DELETE FROM enrollments WHERE course_id = ?')->execute([$courseId]);
            $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$courseId]);
            echo "PASS  cleaned course {$courseId}\n";
        }
        foreach ([$adminUser, $teacherUser, $studentUser] as $u) {
            if ($u === null) {
                continue;
            }
            $uid = (int) $u['id'];
            $db->prepare('DELETE FROM gamification_events WHERE user_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM user_gamification_badges WHERE user_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        }
        echo "PASS  cleaned fixture users\n";
    } catch (Throwable $cleanupErr) {
        $failures++;
        echo 'FAIL  cleanup: ' . $cleanupErr->getMessage() . "\n";
    }
}

echo $failures === 0 ? "\nAll activity system checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
