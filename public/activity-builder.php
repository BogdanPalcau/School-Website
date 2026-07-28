<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();
$uid = (int) ($me['id'] ?? 0);

$activityId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$activity = portal_activity_find($activityId);
if ($activity === null) {
    http_response_code(404);
    exit('Activity not found.');
}

$courseId = (int) $activity['course_id'];
if (!portal_can_manage_course($courseId)) {
    portal_log_security_event('activity_builder_denied', 'medium', 'activity_id=' . $activityId);
    if (portal_is_fetch_request() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_activity_json_error('You cannot manage this activity.', 403);
    }
    http_response_code(403);
    exit('Access denied.');
}

$courseStmt = $db->prepare('SELECT id, slug, full_title, title, code, accent FROM courses WHERE id = ?');
$courseStmt->execute([$courseId]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $courseId, 'slug' => '', 'full_title' => '', 'title' => '', 'code' => '', 'accent' => ''];

/**
 * @return array<string, mixed>
 */
function portal_ab_request_payload(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/**
 * @param array<string, mixed> $payload
 */
function portal_ab_verify_csrf(array $payload): bool
{
    $token = (string) ($payload['_token'] ?? $_POST['_token'] ?? '');
    $valid = $token !== ''
        && !empty($_SESSION['_csrf'])
        && hash_equals((string) $_SESSION['_csrf'], $token);
    if (!$valid && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        portal_log_security_event('csrf_failed', 'high', 'activity-builder CSRF failed');
    }
    return $valid;
}

/**
 * @return array{ok: bool, tree?: array, activity?: array, revision?: int, draft_version_id?: ?int, validation?: array}
 */
function portal_ab_fresh_payload(int $activityId): array
{
    $activity = portal_activity_find($activityId);
    if ($activity === null) {
        return ['ok' => false];
    }
    $draftId = portal_activity_draft_version_id($activityId);
    $tree = $draftId !== null
        ? portal_activity_load_version_tree($draftId, true)
        : ['sections' => [], 'questions' => [], 'options_by_question' => []];
    return [
        'ok' => true,
        'activity' => $activity,
        'tree' => $tree,
        'revision' => (int) ($activity['version'] ?? 1),
        'draft_version_id' => $draftId,
        'validation' => portal_activity_validate_for_publish($activityId),
    ];
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $payload = portal_ab_request_payload();
    if (!portal_ab_verify_csrf($payload)) {
        portal_activity_json_error('Invalid security token. Refresh and try again.', 403);
    }

    $action = (string) ($payload['action'] ?? $_POST['action'] ?? '');
    $activity = portal_activity_find($activityId);
    if ($activity === null) {
        portal_activity_json_error('Activity not found.', 404);
    }
    portal_activity_require_manage($activity);

    $respond = static function (array $result) use ($activityId): never {
        if (empty($result['ok'])) {
            portal_activity_json_error(
                (string) ($result['error'] ?? 'Request failed.'),
                !empty($result['conflict']) ? 409 : 400,
                $result
            );
        }
        $fresh = portal_ab_fresh_payload($activityId);
        portal_activity_json_ok(array_merge($result, [
            'activity' => $fresh['activity'] ?? null,
            'tree' => $fresh['tree'] ?? null,
            'revision' => $fresh['revision'] ?? ($result['revision'] ?? 0),
            'draft_version_id' => $fresh['draft_version_id'] ?? null,
            'validation' => $fresh['validation'] ?? null,
        ]));
    };

    switch ($action) {
        case 'save_settings':
            $fields = $payload['fields'] ?? $payload;
            if (!is_array($fields)) {
                $fields = [];
            }
            $revision = (int) ($payload['revision'] ?? $payload['expected_revision'] ?? 0);
            $respond(portal_activity_save_settings($activityId, $fields, $revision));

        case 'publish':
            $respond(portal_activity_publish($activityId));

        case 'unpublish':
            $respond(portal_activity_unpublish($activityId));

        case 'add_question':
            $type = (string) ($payload['question_type'] ?? $payload['type'] ?? '');
            $prompt = (string) ($payload['prompt_html'] ?? '');
            $sectionId = isset($payload['section_id']) && $payload['section_id'] !== ''
                ? (int) $payload['section_id'] : null;
            $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];
            foreach (['points', 'difficulty', 'tags', 'required', 'manual_marking', 'settings', 'options', 'explanation_html', 'hint_html', 'teacher_notes'] as $k) {
                if (array_key_exists($k, $payload) && !array_key_exists($k, $extra)) {
                    $extra[$k] = $payload[$k];
                }
            }
            $respond(portal_activity_add_question($activityId, $type, $prompt, $sectionId, $extra));

        case 'update_question':
            $qid = (int) ($payload['question_id'] ?? 0);
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : $payload;
            unset($fields['action'], $fields['_token'], $fields['id'], $fields['question_id'], $fields['revision']);
            $respond(portal_activity_update_question($activityId, $qid, $fields));

        case 'delete_question':
            $respond(portal_activity_delete_question($activityId, (int) ($payload['question_id'] ?? 0)));

        case 'reorder_questions':
            $ids = $payload['question_ids'] ?? $payload['order'] ?? [];
            if (!is_array($ids)) {
                $ids = [];
            }
            $respond(portal_activity_reorder_questions($activityId, array_map('intval', $ids)));

        case 'duplicate_question':
            $respond(portal_activity_duplicate_question($activityId, (int) ($payload['question_id'] ?? 0)));

        case 'add_section':
            $respond(portal_activity_add_section(
                $activityId,
                (string) ($payload['title'] ?? ''),
                (string) ($payload['instructions_html'] ?? '')
            ));

        case 'update_section':
            $sid = (int) ($payload['section_id'] ?? 0);
            $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];
            if ($fields === []) {
                foreach (['title', 'instructions_html', 'sort_order'] as $k) {
                    if (array_key_exists($k, $payload)) {
                        $fields[$k] = $payload[$k];
                    }
                }
            }
            $respond(portal_activity_update_section($activityId, $sid, $fields));

        case 'delete_section':
            $respond(portal_activity_delete_section($activityId, (int) ($payload['section_id'] ?? 0)));

        case 'add_option':
            $respond(portal_activity_add_option(
                $activityId,
                (int) ($payload['question_id'] ?? 0),
                is_array($payload['option'] ?? null) ? $payload['option'] : $payload,
                isset($payload['sort_order']) ? (int) $payload['sort_order'] : null
            ));

        case 'update_option': {
            $ctx = portal_activity_require_draft_version($activityId);
            if (empty($ctx['ok'])) {
                $respond($ctx);
            }
            $optionId = (int) ($payload['option_id'] ?? 0);
            $draftId = (int) $ctx['draft_version_id'];
            $chk = $db->prepare(
                "SELECT o.id FROM activity_question_options o
                 JOIN activity_questions q ON q.id = o.question_id
                 WHERE o.id = ? AND q.activity_version_id = ?"
            );
            $chk->execute([$optionId, $draftId]);
            if (!$chk->fetchColumn()) {
                $respond(['ok' => false, 'error' => 'Option not found.']);
            }
            $sets = [];
            $params = [];
            $map = [
                'option_text_html' => static fn($v) => portal_sanitize_rich_text((string) $v),
                'is_correct' => static fn($v) => !empty($v) ? 1 : 0,
                'credit' => static fn($v) => (float) $v,
                'feedback_html' => static fn($v) => portal_sanitize_rich_text((string) $v),
                'match_key' => static fn($v) => (string) $v,
                'sort_order' => static fn($v) => (int) $v,
                'pinned_position' => static fn($v) => $v === null || $v === '' ? null : (int) $v,
                'media_id' => static fn($v) => $v === null || $v === '' ? null : (int) $v,
            ];
            $src = is_array($payload['option'] ?? null) ? $payload['option'] : $payload;
            foreach ($map as $key => $caster) {
                if (!array_key_exists($key, $src) && !($key === 'option_text_html' && isset($src['text']))) {
                    continue;
                }
                $val = $key === 'option_text_html' && !array_key_exists($key, $src)
                    ? ($src['text'] ?? '')
                    : $src[$key];
                $sets[] = "$key = ?";
                $params[] = $caster($val);
            }
            if ($sets === []) {
                $respond(['ok' => true, 'option_id' => $optionId]);
            }
            $params[] = $optionId;
            $db->prepare('UPDATE activity_question_options SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($params);
            $respond(['ok' => true, 'option_id' => $optionId]);
        }

        case 'delete_option': {
            $ctx = portal_activity_require_draft_version($activityId);
            if (empty($ctx['ok'])) {
                $respond($ctx);
            }
            $optionId = (int) ($payload['option_id'] ?? 0);
            $draftId = (int) $ctx['draft_version_id'];
            $db->prepare(
                "DELETE FROM activity_question_options
                 WHERE id = ?
                   AND question_id IN (
                        SELECT id FROM activity_questions WHERE activity_version_id = ?
                   )"
            )->execute([$optionId, $draftId]);
            $respond(['ok' => true]);
        }

        case 'save_rubric':
            $respond(portal_activity_save_rubric(
                $activityId,
                (int) ($payload['question_id'] ?? 0),
                is_array($payload['rubric'] ?? null) ? $payload['rubric'] : $payload
            ));

        case 'upload_media': {
            if (!isset($_FILES['file'])) {
                portal_activity_json_error('No file uploaded.');
            }
            $mediaType = (string) ($payload['media_type'] ?? $_POST['media_type'] ?? 'image');
            $result = portal_activity_store_media(
                $courseId,
                $activityId,
                portal_activity_draft_version_id($activityId),
                isset($payload['question_id']) || isset($_POST['question_id'])
                    ? (int) ($payload['question_id'] ?? $_POST['question_id']) : null,
                $_FILES['file'],
                $mediaType,
                (string) ($payload['media_role'] ?? $_POST['media_role'] ?? 'attachment'),
                (string) ($payload['alt_text'] ?? $_POST['alt_text'] ?? ''),
                (string) ($payload['caption'] ?? $_POST['caption'] ?? '')
            );
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Upload failed.'));
            }
            $result['url'] = 'activity-media.php?id=' . (int) $result['media_id'];
            portal_activity_json_ok($result);
        }

        case 'validate':
            portal_activity_json_ok(['validation' => portal_activity_validate_for_publish($activityId)]);

        case 'preview_payload': {
            $draftId = portal_activity_draft_version_id($activityId);
            if ($draftId === null) {
                portal_activity_json_error('No draft to preview.');
            }
            $tree = portal_activity_load_version_tree($draftId, true);
            $questions = [];
            foreach ($tree['questions'] as $q) {
                $qid = (int) $q['id'];
                $settings = portal_activity_json_decode((string) ($q['settings_json'] ?? '{}'), []) ?: [];
                $questions[] = [
                    'id' => $qid,
                    'section_id' => $q['section_id'],
                    'question_type' => $q['question_type'],
                    'prompt_html' => $q['prompt_html'],
                    'hint_html' => $q['hint_html'] ?? '',
                    'explanation_html' => $q['explanation_html'] ?? '',
                    'points' => (float) $q['points'],
                    'required' => (int) $q['required'],
                    'manual_marking' => (int) $q['manual_marking'],
                    'settings' => $settings,
                    'options' => array_map(static function (array $o): array {
                        return [
                            'id' => (int) $o['id'],
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
            portal_activity_json_ok([
                'preview' => true,
                'recorded' => false,
                'banner' => 'Preview — responses are not recorded',
                'activity' => [
                    'id' => $activityId,
                    'title' => $activity['title'],
                    'mode' => $activity['mode'],
                    'mode_label' => portal_activity_mode_label((string) $activity['mode']),
                    'instructions_html' => $activity['instructions_html'],
                    'navigation_policy' => $activity['navigation_policy'],
                    'feedback_policy' => $activity['feedback_policy'],
                    'time_limit_seconds' => (int) $activity['time_limit_seconds'],
                ],
                'sections' => $tree['sections'],
                'questions' => $questions,
            ]);
        }

        case 'duplicate_activity': {
            $result = portal_activity_duplicate($activityId);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not duplicate.'));
            }
            portal_activity_json_ok([
                'activity_id' => (int) ($result['activity_id'] ?? 0),
                'redirect' => 'activity-builder.php?id=' . (int) ($result['activity_id'] ?? 0),
            ]);
        }

        case 'export_json': {
            $result = portal_activity_export_definition_json($activityId);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Export failed.'));
            }
            portal_activity_json_ok($result);
        }

        case 'import_csv_preview': {
            $csv = (string) ($payload['csv'] ?? $payload['csv_text'] ?? '');
            $result = portal_activity_import_csv_preview($csv);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Invalid CSV.'), 400, $result);
            }
            portal_activity_json_ok($result);
        }

        case 'import_csv_apply': {
            $csv = (string) ($payload['csv'] ?? $payload['csv_text'] ?? '');
            $respond(portal_activity_import_csv_apply($activityId, $csv));
        }

        case 'save_to_bank': {
            $qid = (int) ($payload['question_id'] ?? 0);
            $draftId = portal_activity_draft_version_id($activityId);
            if ($draftId === null) {
                portal_activity_json_error('No draft version.');
            }
            $tree = portal_activity_load_version_tree($draftId, true);
            $found = null;
            foreach ($tree['questions'] as $q) {
                if ((int) $q['id'] === $qid) {
                    $found = $q;
                    break;
                }
            }
            if ($found === null) {
                portal_activity_json_error('Question not found.');
            }
            $snapshot = [
                'question_type' => $found['question_type'],
                'prompt_html' => $found['prompt_html'],
                'explanation_html' => $found['explanation_html'],
                'hint_html' => $found['hint_html'],
                'teacher_notes' => $found['teacher_notes'],
                'points' => $found['points'],
                'difficulty' => $found['difficulty'],
                'tags' => $found['tags'],
                'required' => $found['required'],
                'manual_marking' => $found['manual_marking'],
                'settings' => portal_activity_json_decode((string) $found['settings_json'], []) ?: [],
                'options' => array_map(static function (array $o): array {
                    return [
                        'option_text_html' => $o['option_text_html'],
                        'is_correct' => (int) ($o['is_correct'] ?? 0),
                        'credit' => (float) ($o['credit'] ?? 0),
                        'feedback_html' => $o['feedback_html'] ?? '',
                        'match_key' => $o['match_key'] ?? '',
                    ];
                }, $tree['options_by_question'][$qid] ?? []),
            ];
            $result = portal_activity_bank_save_question($uid, $snapshot, [
                'visibility' => (string) ($payload['visibility'] ?? 'private'),
                'source_course_id' => $courseId,
                'title' => (string) ($payload['title'] ?? ''),
                'difficulty' => (string) ($found['difficulty'] ?? 'medium'),
                'tags' => (string) ($found['tags'] ?? ''),
            ]);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not save to bank.'));
            }
            portal_activity_json_ok($result);
        }

        case 'add_from_bank':
            $respond(portal_activity_bank_add_to_activity($activityId, (int) ($payload['bank_item_id'] ?? 0)));

        case 'list_bank': {
            $items = portal_activity_bank_list(
                $uid,
                $courseId,
                (int) ($payload['limit'] ?? 50),
                (int) ($payload['offset'] ?? 0)
            );
            portal_activity_json_ok(['items' => $items]);
        }

        case 'create_from_template': {
            $key = (string) ($payload['template_key'] ?? '');
            $stmt = $db->prepare('SELECT * FROM activity_templates WHERE template_key = ? AND enabled = 1');
            $stmt->execute([$key]);
            $tpl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$tpl) {
                portal_activity_json_error('Template not found.');
            }
            $def = portal_activity_json_decode((string) $tpl['definition_json'], []) ?: [];
            if (!empty($def['settings']) && is_array($def['settings'])) {
                portal_activity_save_settings($activityId, $def['settings'], (int) ($activity['version'] ?? 1));
            }
            foreach ($def['sections'] ?? [] as $sec) {
                if (is_array($sec)) {
                    portal_activity_add_section(
                        $activityId,
                        (string) ($sec['title'] ?? ''),
                        (string) ($sec['instructions_html'] ?? '')
                    );
                }
            }
            $sectionMap = [];
            $freshTree = portal_activity_load_version_tree((int) portal_activity_draft_version_id($activityId), true);
            foreach ($freshTree['sections'] as $s) {
                $sectionMap[(string) $s['title']] = (int) $s['id'];
            }
            foreach ($def['questions'] ?? [] as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $secTitle = (string) ($q['section'] ?? $q['section_title'] ?? '');
                $secId = $secTitle !== '' && isset($sectionMap[$secTitle]) ? $sectionMap[$secTitle] : null;
                portal_activity_add_question(
                    $activityId,
                    (string) ($q['question_type'] ?? 'single_choice'),
                    (string) ($q['prompt_html'] ?? ''),
                    $secId,
                    [
                        'points' => $q['points'] ?? 1,
                        'difficulty' => $q['difficulty'] ?? 'medium',
                        'tags' => $q['tags'] ?? '',
                        'settings' => $q['settings'] ?? [],
                        'options' => $q['options'] ?? [],
                        'explanation_html' => $q['explanation_html'] ?? '',
                        'hint_html' => $q['hint_html'] ?? '',
                        'manual_marking' => $q['manual_marking'] ?? 0,
                        'skip_default_options' => !empty($q['options']),
                    ]
                );
            }
            $respond(['ok' => true, 'template_key' => $key]);
        }

        case 'list_versions': {
            $stmt = $db->prepare(
                'SELECT id, version_number, status, change_summary, created_at, published_at
                 FROM activity_versions WHERE activity_id = ?
                 ORDER BY version_number DESC, id DESC'
            );
            $stmt->execute([$activityId]);
            portal_activity_json_ok(['versions' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []]);
        }

        case 'restore_version_as_draft': {
            $sourceVersionId = (int) ($payload['version_id'] ?? 0);
            $chk = $db->prepare('SELECT id FROM activity_versions WHERE id = ? AND activity_id = ?');
            $chk->execute([$sourceVersionId, $activityId]);
            if (!$chk->fetchColumn()) {
                portal_activity_json_error('Version not found.');
            }
            $ctx = portal_activity_require_draft_version($activityId);
            if (empty($ctx['ok'])) {
                $respond($ctx);
            }
            $draftId = (int) $ctx['draft_version_id'];
            $db->prepare('DELETE FROM activity_questions WHERE activity_version_id = ?')->execute([$draftId]);
            $db->prepare('DELETE FROM activity_sections WHERE activity_version_id = ?')->execute([$draftId]);
            portal_activity_copy_version_tree($sourceVersionId, $draftId);
            portal_activity_audit($activityId, 'version_restored_as_draft', [
                'source_version_id' => $sourceVersionId,
                'draft_version_id' => $draftId,
            ]);
            $respond(['ok' => true]);
        }

        default:
            portal_activity_json_error('Unknown action.', 400);
    }
}

$csrfToken = portal_csrf_token();
$draftId = portal_activity_draft_version_id($activityId);
if ($draftId === null) {
    $ctx = portal_activity_require_draft_version($activityId);
    $draftId = isset($ctx['draft_version_id']) ? (int) $ctx['draft_version_id'] : null;
    $activity = portal_activity_find($activityId) ?? $activity;
}
$tree = $draftId !== null
    ? portal_activity_load_version_tree($draftId, true)
    : ['sections' => [], 'questions' => [], 'options_by_question' => []];
$validation = portal_activity_validate_for_publish($activityId);

$questionTypes = [];
foreach (portal_activity_question_types() as $type) {
    $questionTypes[] = [
        'id' => $type,
        'label' => portal_activity_question_type_label($type),
    ];
}
$modes = [];
foreach (portal_activity_modes() as $mode) {
    $modes[] = [
        'id' => $mode,
        'label' => portal_activity_mode_label($mode),
    ];
}

$bootstrap = [
    'activity' => $activity,
    'tree' => $tree,
    'question_types' => $questionTypes,
    'modes' => $modes,
    'validation' => $validation,
    'draft_version_id' => $draftId,
    'revision' => (int) ($activity['version'] ?? 1),
    'course' => [
        'id' => (int) $course['id'],
        'slug' => (string) ($course['slug'] ?? ''),
        'title' => (string) ($course['full_title'] ?? $course['title'] ?? ''),
        'code' => (string) ($course['code'] ?? ''),
    ],
    'urls' => [
        'builder' => 'activity-builder.php?id=' . $activityId,
        'player' => 'activity.php?id=' . $activityId,
        'results' => 'activity-results.php?id=' . $activityId,
        'course' => 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content',
        'bank' => 'question-bank.php?activity_id=' . $activityId,
        'media' => 'activity-media.php',
    ],
];

$backUrl = 'course.php?course=' . urlencode((string) ($course['slug'] ?? '')) . '&section=content';
$page_title = 'Edit activity · ' . (string) $activity['title'];
$page_eyebrow = 'Builder';
$page_heading = (string) $activity['title'];
$page_description = '';
$active_page = 'courses';
$layout_variant = 'app';

ob_start();
?>
<section class="activity-builder"
         id="activity-builder"
         data-activity-id="<?= (int) $activityId ?>"
         data-csrf="<?= portal_escape($csrfToken) ?>"
         data-revision="<?= (int) ($activity['version'] ?? 1) ?>"
         data-mode="<?= portal_escape((string) $activity['mode']) ?>"
         data-status="<?= portal_escape((string) $activity['status']) ?>">

    <header class="ab-header">
        <div class="ab-header-lead">
            <a class="ab-back" href="<?= portal_escape($backUrl) ?>" title="Back to course">
                <?= portal_icon('chevron-left', 'icon-sm') ?>
                <span><?= portal_escape((string) ($course['code'] ?: 'Course')) ?></span>
            </a>
            <div class="ab-header-title">
                <div class="ab-title-row">
                    <h1 class="ab-title" data-ab-title><?= portal_escape((string) $activity['title']) ?></h1>
                    <span class="activity-mode-pill activity-mode-pill--<?= portal_escape((string) $activity['mode']) ?>">
                        <?= portal_escape(portal_activity_mode_label((string) $activity['mode'])) ?>
                    </span>
                    <span class="ab-status-pill ab-status-pill--<?= portal_escape((string) $activity['status']) ?>" data-ab-status>
                        <?= portal_escape(ucfirst((string) $activity['status'])) ?>
                    </span>
                </div>
                <p class="ab-save-state" data-ab-save-state>Saved</p>
            </div>
        </div>
        <div class="ab-header-actions">
            <button type="button" class="ab-icon-btn ab-drawer-toggle" data-ab-drawer="left" aria-controls="ab-left" aria-expanded="false" title="Structure">
                <?= portal_icon('list-ordered', 'icon-sm') ?>
                <span>Structure</span>
            </button>
            <button type="button" class="ab-icon-btn ab-drawer-toggle" data-ab-drawer="right" aria-controls="ab-right" aria-expanded="false" title="Settings">
                <?= portal_icon('settings', 'icon-sm') ?>
                <span>Settings</span>
            </button>
            <button type="button" class="ab-icon-btn" data-ab-action="validate" title="Check for issues">
                <?= portal_icon('check-circle', 'icon-sm') ?>
                <span>Check</span>
            </button>
            <button type="button" class="ab-icon-btn" data-ab-action="preview" title="Preview as student">
                <?= portal_icon('eye', 'icon-sm') ?>
                <span>Preview</span>
            </button>
            <a class="ab-icon-btn ab-icon-btn--accent" href="activity-results.php?id=<?= (int) $activityId ?>" title="View student submissions">
                <?= portal_icon('users', 'icon-sm') ?>
                <span>Submissions</span>
            </a>
            <?php if (($activity['status'] ?? '') === 'published'): ?>
                <button type="button" class="ab-btn ab-btn--ghost" data-ab-action="unpublish">Unpublish</button>
            <?php else: ?>
                <button type="button" class="ab-btn ab-btn--primary" data-ab-action="publish">Publish</button>
            <?php endif; ?>
        </div>
    </header>

    <div class="ab-drawer-backdrop" data-ab-drawer-backdrop hidden></div>

    <div class="ab-panels">
        <aside class="ab-left ab-drawer" id="ab-left" data-ab-panel="left">
            <div class="ab-panel-head">
                <p class="ab-panel-label">Questions</p>
                <div class="ab-validation" data-ab-validation aria-live="polite"></div>
            </div>
            <div class="ab-toolbar">
                <button type="button" class="ab-btn ab-btn--primary ab-btn--block" data-ab-action="open-type-picker">
                    <?= portal_icon('plus', 'icon-sm') ?> Add question
                </button>
                <button type="button" class="ab-btn ab-btn--ghost ab-btn--block" data-ab-action="add-section">Add section</button>
            </div>
            <div class="ab-question-list" data-ab-question-list role="list"></div>
            <div class="ab-empty" data-ab-structure-empty hidden>
                <p>No questions yet</p>
                <span>Add a question to start building.</span>
            </div>
        </aside>

        <main class="ab-centre" id="ab-centre" data-ab-panel="centre">
            <div class="ab-editor" data-ab-editor>
                <div class="ab-empty ab-empty--centre" data-ab-editor-empty>
                    <div class="ab-empty-icon"><?= portal_icon('activity', 'icon') ?></div>
                    <p>Select a question</p>
                    <span>Pick one from the left, or add a new question to edit it here.</span>
                    <button type="button" class="ab-btn ab-btn--primary" data-ab-action="open-type-picker">
                        <?= portal_icon('plus', 'icon-sm') ?> Add question
                    </button>
                </div>
                <div class="ab-editor-form" data-ab-editor-form hidden></div>
            </div>
        </main>

        <aside class="ab-right ab-drawer" id="ab-right" data-ab-panel="right">
            <div class="ab-panel-head">
                <p class="ab-panel-label">Activity settings</p>
            </div>
            <div class="ab-side-section" data-ab-settings>
                <h3>Details</h3>
                <label class="ab-field">
                    <span>Title</span>
                    <input type="text" name="title" maxlength="200" data-ab-setting="title" value="<?= portal_escape((string) $activity['title']) ?>">
                </label>
                <label class="ab-field">
                    <span>Description <small>optional</small></span>
                    <input type="text" name="short_description" maxlength="500" data-ab-setting="short_description" value="<?= portal_escape((string) $activity['short_description']) ?>">
                </label>
                <label class="ab-field">
                    <span>Type</span>
                    <select data-ab-setting="mode">
                        <?php foreach ($modes as $mode): ?>
                            <option value="<?= portal_escape($mode['id']) ?>"<?= $activity['mode'] === $mode['id'] ? ' selected' : '' ?>>
                                <?= portal_escape($mode['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ab-field">
                    <span>Instructions for students</span>
                    <div class="quill-wrap ab-quill-compact"><div class="quill-editor" data-target="ab-instructions" data-ab-quill="instructions_html"></div></div>
                    <textarea id="ab-instructions" hidden><?= portal_escape((string) $activity['instructions_html']) ?></textarea>
                </label>
            </div>
            <div class="ab-side-section">
                <h3>Availability</h3>
                <label class="ab-field"><span>Opens</span>
                    <input type="datetime-local" data-ab-setting="opens_at" value="<?= portal_escape($activity['opens_at'] !== '' ? date('Y-m-d\TH:i', strtotime((string) $activity['opens_at'])) : '') ?>">
                </label>
                <label class="ab-field"><span>Closes</span>
                    <input type="datetime-local" data-ab-setting="closes_at" value="<?= portal_escape($activity['closes_at'] !== '' ? date('Y-m-d\TH:i', strtotime((string) $activity['closes_at'])) : '') ?>">
                </label>
                <label class="ab-field"><span>Due</span>
                    <input type="datetime-local" data-ab-setting="due_at" value="<?= portal_escape($activity['due_at'] !== '' ? date('Y-m-d\TH:i', strtotime((string) $activity['due_at'])) : '') ?>">
                </label>
                <div class="ab-field-row">
                    <label class="ab-field"><span>Time limit</span>
                        <select data-ab-setting-minutes="time_limit_seconds" title="How long each attempt may take">
                            <?php
                            $timeLimitMinutes = (int) $activity['time_limit_seconds'] > 0
                                ? (int) ceil(((int) $activity['time_limit_seconds']) / 60)
                                : 0;
                            $timeChoices = [
                                0 => 'No limit',
                                10 => '10 minutes',
                                15 => '15 minutes',
                                20 => '20 minutes',
                                30 => '30 minutes',
                                45 => '45 minutes',
                                60 => '60 minutes',
                                90 => '90 minutes',
                                120 => '2 hours',
                            ];
                            if ($timeLimitMinutes > 0 && !isset($timeChoices[$timeLimitMinutes])) {
                                $timeChoices[$timeLimitMinutes] = $timeLimitMinutes . ' minutes';
                                ksort($timeChoices, SORT_NUMERIC);
                                if (isset($timeChoices[0])) {
                                    $noLimit = $timeChoices[0];
                                    unset($timeChoices[0]);
                                    $timeChoices = [0 => $noLimit] + $timeChoices;
                                }
                            }
                            foreach ($timeChoices as $mins => $label):
                            ?>
                                <option value="<?= (int) $mins ?>"<?= $timeLimitMinutes === (int) $mins ? ' selected' : '' ?>><?= portal_escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="ab-field"><span>Attempts allowed</span>
                        <select data-ab-setting="max_attempts" title="How many times a student may take this activity">
                            <?php
                            $maxAttempts = (int) $activity['max_attempts'];
                            $attemptChoices = [
                                0 => 'Unlimited',
                                1 => '1 attempt',
                                2 => '2 attempts',
                                3 => '3 attempts',
                                5 => '5 attempts',
                                10 => '10 attempts',
                            ];
                            if ($maxAttempts > 0 && !isset($attemptChoices[$maxAttempts])) {
                                $attemptChoices[$maxAttempts] = $maxAttempts . ' attempts';
                                ksort($attemptChoices, SORT_NUMERIC);
                                if (isset($attemptChoices[0])) {
                                    $unlimited = $attemptChoices[0];
                                    unset($attemptChoices[0]);
                                    $attemptChoices = [0 => $unlimited] + $attemptChoices;
                                }
                            }
                            foreach ($attemptChoices as $count => $label):
                            ?>
                                <option value="<?= (int) $count ?>"<?= $maxAttempts === (int) $count ? ' selected' : '' ?>><?= portal_escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <p class="ab-hint-line">Students stop after they use their allowed attempts. Unlimited lets them retry freely.</p>
            </div>
            <div class="ab-side-section">
                <h3>Grading &amp; feedback</h3>
                <p class="ab-hint-line">Written answers are teacher-marked. Final grades stay pending until those responses are marked in Results.</p>
                <label class="ab-field"><span>When students see feedback</span>
                    <select data-ab-setting="feedback_policy">
                        <?php
                        $feedbackLabels = [
                            'after_each' => 'After each question',
                            'after_submission' => 'After they submit',
                            'after_close' => 'After the activity closes',
                            'when_released' => 'Only when you release grades',
                            'never' => 'Never',
                        ];
                        foreach ($feedbackLabels as $fp => $fpLabel):
                        ?>
                            <option value="<?= $fp ?>"<?= $activity['feedback_policy'] === $fp ? ' selected' : '' ?>><?= portal_escape($fpLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ab-field"><span>Question navigation</span>
                    <select data-ab-setting="navigation_policy">
                        <?php
                        $navLabels = [
                            'free' => 'Free — jump between questions',
                            'sequential' => 'Sequential — one after another',
                            'no_return' => 'No return — cannot go back',
                        ];
                        foreach ($navLabels as $np => $npLabel):
                        ?>
                            <option value="<?= $np ?>"<?= $activity['navigation_policy'] === $np ? ' selected' : '' ?>><?= portal_escape($npLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ab-check"><input type="checkbox" data-ab-setting-bool="shuffle_questions" value="1"<?= !empty($activity['shuffle_questions']) ? ' checked' : '' ?>><span>Shuffle questions</span></label>
                <label class="ab-check"><input type="checkbox" data-ab-setting-bool="shuffle_options" value="1"<?= !empty($activity['shuffle_options']) ? ' checked' : '' ?>><span>Shuffle answer options</span></label>
                <label class="ab-check"><input type="checkbox" data-ab-setting-bool="include_in_gradebook" value="1"<?= !empty($activity['include_in_gradebook']) ? ' checked' : '' ?>><span>Include in gradebook</span></label>
                <div class="ab-field-row">
                    <label class="ab-field"><span>Grade weight</span>
                        <input type="number" min="0" max="100" step="0.01" data-ab-setting="grade_weight" value="<?= portal_escape((string) ($activity['grade_weight'] ?? 100)) ?>">
                    </label>
                    <label class="ab-field"><span>Pass mark %</span>
                        <input type="number" min="0" max="100" step="0.01" data-ab-setting="pass_mark" value="<?= portal_escape((string) ($activity['pass_mark'] ?? '')) ?>">
                    </label>
                </div>
                <a class="ab-submissions-link" href="activity-results.php?id=<?= (int) $activityId ?>">
                    <?= portal_icon('users', 'icon-sm') ?>
                    <span>View student submissions</span>
                    <span aria-hidden="true">→</span>
                </a>
            </div>
            <details class="ab-side-section ab-side-section--collapsible" data-ab-integrity-settings>
                <summary>Integrity options <small>assessments</small></summary>
                <label class="ab-check"><input type="checkbox" data-ab-setting-bool="integrity_enabled" value="1"<?= !empty($activity['integrity_enabled']) ? ' checked' : '' ?>><span>Enable monitoring</span></label>
                <label class="ab-check"><input type="checkbox" data-ab-setting-bool="focus_monitoring" value="1"<?= !empty($activity['focus_monitoring']) ? ' checked' : '' ?>><span>Focus monitoring</span></label>
                <label class="ab-field"><span>Paste</span>
                    <select data-ab-setting="paste_policy">
                        <?php foreach (['allow' => 'Allow', 'allow_log' => 'Allow &amp; log', 'block_log' => 'Block &amp; log'] as $pp => $ppLabel): ?>
                            <option value="<?= $pp ?>"<?= $activity['paste_policy'] === $pp ? ' selected' : '' ?>><?= $ppLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ab-field"><span>Copy</span>
                    <select data-ab-setting="copy_policy">
                        <?php foreach (['allow' => 'Allow', 'log' => 'Log', 'block_log' => 'Block &amp; log'] as $cp => $cpLabel): ?>
                            <option value="<?= $cp ?>"<?= $activity['copy_policy'] === $cp ? ' selected' : '' ?>><?= $cpLabel ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="ab-field"><span>Fullscreen</span>
                    <select data-ab-setting="fullscreen_policy">
                        <?php foreach (['off' => 'Off', 'optional' => 'Optional', 'required' => 'Required'] as $fs => $fsLabel): ?>
                            <option value="<?= $fs ?>"<?= $activity['fullscreen_policy'] === $fs ? ' selected' : '' ?>><?= portal_escape($fsLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </details>
            <details class="ab-side-section ab-side-section--collapsible">
                <summary>Import / export</summary>
                <div class="ab-tool-grid">
                    <button type="button" class="ab-btn ab-btn--ghost" data-ab-action="import-csv">Import CSV</button>
                    <button type="button" class="ab-btn ab-btn--ghost" data-ab-action="export-json">Export JSON</button>
                    <button type="button" class="ab-btn ab-btn--ghost" data-ab-action="open-bank">Question bank</button>
                    <button type="button" class="ab-btn ab-btn--ghost" data-ab-action="duplicate-activity">Duplicate</button>
                </div>
            </details>
        </aside>
    </div>

    <dialog class="ab-modal" id="ab-type-picker" aria-labelledby="ab-type-picker-title">
        <form method="dialog" class="ab-modal-panel">
            <header class="ab-modal-head">
                <div>
                    <p class="ab-panel-label">New question</p>
                    <h2 id="ab-type-picker-title">Choose a type</h2>
                </div>
                <button type="submit" class="ab-icon-btn" value="cancel" aria-label="Close"><?= portal_icon('x', 'icon-sm') ?></button>
            </header>
            <div class="ab-type-grid" data-ab-type-grid>
                <?php
                $typeHints = [
                    'single_choice' => 'One correct option · auto-marked',
                    'multiple_choice' => 'Select all that apply · auto-marked',
                    'true_false' => 'True or false · auto-marked',
                    'short_text' => 'Accepted phrases · auto-marked',
                    'numeric' => 'Number with tolerance · auto-marked',
                    'long_response' => 'Essay / written · you mark, with a suggestion',
                    'fill_blank' => 'Complete the blanks',
                    'ordering' => 'Put items in order · auto-marked',
                    'matching' => 'Match related pairs · auto-marked',
                    'rating_scale' => 'Survey rating · ungraded',
                ];
                foreach ($questionTypes as $qt):
                    $isTeacherMarked = $qt['id'] === 'long_response';
                ?>
                    <button type="button" class="ab-type-card<?= $isTeacherMarked ? ' ab-type-card--manual' : '' ?>" data-ab-type="<?= portal_escape($qt['id']) ?>">
                        <span class="ab-type-card-icon"><?= portal_icon('help-circle', 'icon-sm') ?></span>
                        <strong><?= portal_escape($qt['label']) ?></strong>
                        <span><?= portal_escape($typeHints[$qt['id']] ?? $qt['id']) ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>
    </dialog>

    <dialog class="ab-modal" id="ab-preview-modal" aria-labelledby="ab-preview-title">
        <div class="ab-modal-panel ab-modal-panel--wide">
            <header class="ab-modal-head">
                <div>
                    <p class="ab-panel-label">Student view</p>
                    <h2 id="ab-preview-title">Preview</h2>
                </div>
                <button type="button" class="ab-icon-btn" data-ab-close-preview aria-label="Close"><?= portal_icon('x', 'icon-sm') ?></button>
            </header>
            <p class="ab-preview-banner" role="status">Preview — responses are not recorded</p>
            <div data-ab-preview-root class="activity-player ab-preview-player"></div>
        </div>
    </dialog>

    <input type="file" id="ab-media-file" accept="image/*,audio/*,video/*" hidden>
    <input type="file" id="ab-camera-capture" accept="image/*" capture="environment" hidden>
    <input type="file" id="ab-csv-file" accept=".csv,text/csv" hidden>
</section>

<script type="application/json" id="ab-bootstrap"><?= portal_activity_json_encode($bootstrap) ?></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
<script src="assets/portal-quill.js?v=20260727a"></script>
<script src="assets/activity-builder.js?v=20260728g"></script>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
