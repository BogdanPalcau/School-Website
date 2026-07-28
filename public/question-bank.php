<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
portal_require_login();

$db = portal_db();
$me = portal_current_user();
$uid = (int) ($me['id'] ?? 0);

if (!portal_is_course_staff() && !portal_is_admin()) {
    http_response_code(403);
    exit('Staff access only.');
}

$activityId = (int) ($_GET['activity_id'] ?? $_POST['activity_id'] ?? 0);
$activity = $activityId > 0 ? portal_activity_find($activityId) : null;
if ($activity !== null) {
    portal_activity_require_manage($activity);
}

$courseId = $activity !== null ? (int) $activity['course_id'] : (int) ($_GET['course_id'] ?? 0);

/**
 * @return array<string, mixed>
 */
function portal_qb_payload(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode(is_string($raw) ? $raw : '', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    $payload = portal_qb_payload();
    $token = (string) ($payload['_token'] ?? '');
    if ($token === '' || empty($_SESSION['_csrf']) || !hash_equals((string) $_SESSION['_csrf'], $token)) {
        portal_activity_json_error('Invalid security token.', 403);
    }

    $action = (string) ($payload['action'] ?? '');
    switch ($action) {
        case 'add_to_activity': {
            $aid = (int) ($payload['activity_id'] ?? $activityId);
            $bankId = (int) ($payload['bank_item_id'] ?? 0);
            $act = portal_activity_find($aid);
            if ($act === null) {
                portal_activity_json_error('Activity not found.', 404);
            }
            portal_activity_require_manage($act);
            $result = portal_activity_bank_add_to_activity($aid, $bankId);
            if (empty($result['ok'])) {
                portal_activity_json_error((string) ($result['error'] ?? 'Could not add.'), 400, $result);
            }
            portal_activity_json_ok(array_merge($result, [
                'redirect' => 'activity-builder.php?id=' . $aid,
            ]));
        }

        case 'create_collection': {
            $title = trim((string) ($payload['title'] ?? ''));
            if ($title === '') {
                portal_activity_json_error('Title is required.');
            }
            $vis = (string) ($payload['visibility'] ?? 'private');
            if (!in_array($vis, ['private', 'course', 'school'], true)) {
                $vis = 'private';
            }
            $db->prepare(
                'INSERT INTO question_bank_collections (owner_id, title, description, visibility)
                 VALUES (?,?,?,?)'
            )->execute([
                $uid,
                substr($title, 0, 200),
                substr(trim((string) ($payload['description'] ?? '')), 0, 1000),
                $vis,
            ]);
            portal_activity_json_ok(['collection_id' => (int) $db->lastInsertId()]);
        }

        case 'add_to_collection': {
            $collectionId = (int) ($payload['collection_id'] ?? 0);
            $bankId = (int) ($payload['bank_item_id'] ?? 0);
            $chk = $db->prepare('SELECT id FROM question_bank_collections WHERE id = ? AND owner_id = ?');
            $chk->execute([$collectionId, $uid]);
            if (!$chk->fetchColumn()) {
                portal_activity_json_error('Collection not found.', 404);
            }
            $db->prepare(
                'INSERT OR IGNORE INTO question_bank_collection_items (collection_id, question_bank_item_id)
                 VALUES (?,?)'
            )->execute([$collectionId, $bankId]);
            portal_activity_json_ok([]);
        }

        default:
            portal_activity_json_error('Unknown action.', 400);
    }
}

$csrfToken = portal_csrf_token();
$q = trim((string) ($_GET['q'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$visFilter = trim((string) ($_GET['visibility'] ?? ''));
$collectionId = (int) ($_GET['collection'] ?? 0);

$items = portal_activity_bank_list($uid, $courseId > 0 ? $courseId : null, 100, 0);
if ($q !== '' || $typeFilter !== '' || $visFilter !== '') {
    $items = array_values(array_filter($items, static function (array $item) use ($q, $typeFilter, $visFilter): bool {
        if ($typeFilter !== '' && (string) $item['question_type'] !== $typeFilter) {
            return false;
        }
        if ($visFilter !== '' && (string) $item['visibility'] !== $visFilter) {
            return false;
        }
        if ($q !== '') {
            $hay = strtolower((string) $item['title'] . ' ' . (string) $item['tags']);
            if (!str_contains($hay, strtolower($q))) {
                return false;
            }
        }
        return true;
    }));
}

if ($collectionId > 0) {
    $idsStmt = $db->prepare(
        'SELECT question_bank_item_id FROM question_bank_collection_items WHERE collection_id = ?'
    );
    $idsStmt->execute([$collectionId]);
    $allowed = array_map('intval', $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $allowedMap = array_fill_keys($allowed, true);
    $items = array_values(array_filter($items, static fn(array $i): bool => isset($allowedMap[(int) $i['id']])));
}

$collections = $db->prepare(
    "SELECT c.*, (SELECT COUNT(*) FROM question_bank_collection_items i WHERE i.collection_id = c.id) AS item_count
     FROM question_bank_collections c
     WHERE c.owner_id = ? OR c.visibility = 'school'
     ORDER BY c.title ASC"
);
$collections->execute([$uid]);
$collectionRows = $collections->fetchAll(PDO::FETCH_ASSOC) ?: [];

$page_title = 'Question bank | ' . portal_school_name();
$page_eyebrow = 'Teaching tools';
$page_heading = 'Question bank';
$page_description = 'Browse, filter, and reuse questions across your activities.';
$active_page = 'courses';

ob_start();
?>
<section class="question-bank-page">
    <header class="qb-header">
        <div>
            <p class="eyebrow">Reusable questions</p>
            <h1><?= portal_escape($page_heading) ?></h1>
            <p><?= portal_escape($page_description) ?></p>
        </div>
        <?php if ($activity !== null): ?>
            <a class="button" href="activity-builder.php?id=<?= (int) $activityId ?>">Back to builder</a>
        <?php endif; ?>
    </header>

    <form method="GET" class="qb-filters">
        <?php if ($activityId > 0): ?>
            <input type="hidden" name="activity_id" value="<?= (int) $activityId ?>">
        <?php endif; ?>
        <label>
            <span class="visually-hidden">Search</span>
            <input type="search" name="q" value="<?= portal_escape($q) ?>" placeholder="Search title or tags">
        </label>
        <label>
            <span class="visually-hidden">Type</span>
            <select name="type">
                <option value="">All types</option>
                <?php foreach (portal_activity_question_types() as $t): ?>
                    <option value="<?= portal_escape($t) ?>"<?= $typeFilter === $t ? ' selected' : '' ?>>
                        <?= portal_escape(portal_activity_question_type_label($t)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span class="visually-hidden">Visibility</span>
            <select name="visibility">
                <option value="">Any visibility</option>
                <?php foreach (['private', 'course', 'school'] as $v): ?>
                    <option value="<?= $v ?>"<?= $visFilter === $v ? ' selected' : '' ?>><?= portal_escape(ucfirst($v)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span class="visually-hidden">Collection</span>
            <select name="collection">
                <option value="">All collections</option>
                <?php foreach ($collectionRows as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= $collectionId === (int) $c['id'] ? ' selected' : '' ?>>
                        <?= portal_escape((string) $c['title']) ?> (<?= (int) $c['item_count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="button">Filter</button>
    </form>

    <div class="qb-layout">
        <aside class="qb-collections card-shell">
            <h2>Collections</h2>
            <ul class="qb-collection-list">
                <?php foreach ($collectionRows as $c): ?>
                    <li>
                        <a href="?<?= portal_escape(http_build_query(array_filter([
                            'activity_id' => $activityId ?: null,
                            'collection' => (int) $c['id'],
                            'q' => $q ?: null,
                            'type' => $typeFilter ?: null,
                            'visibility' => $visFilter ?: null,
                        ]))) ?>">
                            <?= portal_escape((string) $c['title']) ?>
                            <small><?= (int) $c['item_count'] ?></small>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="POST" class="qb-new-collection" data-qb-create-collection>
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="create_collection">
                <label>
                    <span>New collection</span>
                    <input type="text" name="title" required maxlength="200" placeholder="Collection name">
                </label>
                <button type="submit" class="button button--sm">Create</button>
            </form>
        </aside>

        <div class="qb-items">
            <?php if ($items === []): ?>
                <article class="card-shell">
                    <p class="eyebrow">Empty</p>
                    <h3>No questions found</h3>
                    <p>Save questions from the activity builder to reuse them here.</p>
                </article>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <article class="qb-item card-shell">
                        <div class="qb-item-head">
                            <span class="chip"><?= portal_escape(portal_activity_question_type_label((string) $item['question_type'])) ?></span>
                            <span class="chip"><?= portal_escape(ucfirst((string) $item['visibility'])) ?></span>
                        </div>
                        <h3><?= portal_escape((string) ($item['title'] ?: 'Untitled question')) ?></h3>
                        <?php if (trim((string) $item['tags']) !== ''): ?>
                            <p class="qb-tags"><?= portal_escape((string) $item['tags']) ?></p>
                        <?php endif; ?>
                        <div class="qb-item-actions">
                            <?php if ($activityId > 0): ?>
                                <form method="POST" class="qb-add-form">
                                    <?= portal_csrf_field() ?>
                                    <input type="hidden" name="action" value="add_to_activity">
                                    <input type="hidden" name="activity_id" value="<?= (int) $activityId ?>">
                                    <input type="hidden" name="bank_item_id" value="<?= (int) $item['id'] ?>">
                                    <button type="submit" class="button button--sm">Add to activity</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<style>
.question-bank-page { display:grid; gap:20px; }
.qb-header { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; align-items:flex-start; }
.qb-filters { display:flex; flex-wrap:wrap; gap:10px; align-items:end; }
.qb-filters input, .qb-filters select { min-height:40px; }
.qb-layout { display:grid; grid-template-columns: minmax(220px,280px) 1fr; gap:18px; }
.qb-collection-list { list-style:none; padding:0; margin:0 0 16px; display:grid; gap:8px; }
.qb-collection-list a { display:flex; justify-content:space-between; gap:8px; text-decoration:none; }
.qb-items { display:grid; gap:12px; }
.qb-item-head { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; }
.qb-item-actions { margin-top:12px; }
@media (max-width: 860px) { .qb-layout { grid-template-columns: 1fr; } }
</style>
<?php
$page_content = ob_get_clean();
require __DIR__ . '/../layout.php';
