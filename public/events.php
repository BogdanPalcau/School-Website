<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

portal_require_login();

$me = portal_current_user();
$db = portal_db();
$isAdmin = portal_is_admin();
$canCompose = portal_event_staff_can_compose();
$manageableCourses = portal_event_manageable_courses();
$flash = null;
$formErrors = [];
$formValues = [];

$view = (string) ($_GET['view'] ?? 'upcoming');
$view = $view === 'past' ? 'past' : 'upcoming';
$scope = (string) ($_GET['scope'] ?? 'all');
$scope = in_array($scope, ['all', 'school', 'courses'], true) ? $scope : 'all';
$eventId = (int) ($_GET['event'] ?? 0);
$editId = (int) ($_GET['edit'] ?? 0);

/**
 * @param array{0:string,1:string} $flashPair
 */
function events_flash_set(array $flashPair): void
{
    $_SESSION['events_flash'] = $flashPair;
}

function events_list_url(string $view = 'upcoming', string $scope = 'all'): string
{
    $q = [];
    if ($view === 'past') {
        $q['view'] = 'past';
    }
    if ($scope !== 'all') {
        $q['scope'] = $scope;
    }

    return $q === [] ? 'events.php' : ('events.php?' . http_build_query($q));
}

function events_datetime_local_value(string $raw): string
{
    $norm = portal_event_normalize_datetime($raw);
    if ($norm === '') {
        return '';
    }
    $ts = portal_db_timestamp($norm);

    return $ts === null ? '' : date('Y-m-d\TH:i', $ts);
}

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_verify_csrf()) {
        events_flash_set(['error', 'Your session expired. Please try that again.']);
        portal_redirect('events.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_event' && $canCompose) {
        $validated = portal_event_validate_payload($_POST);
        if (!$validated['ok']) {
            events_flash_set(['error', implode(' ', $validated['errors'])]);
            $_SESSION['events_form'] = [
                'values' => $_POST,
                'errors' => $validated['errors'],
                'mode' => 'create',
            ];
            portal_redirect('events.php#create-event');
        }
        $result = portal_event_create($validated['data'], (int) $me['id']);
        if (!$result['ok']) {
            events_flash_set(['error', $result['error']]);
            portal_redirect('events.php#create-event');
        }
        $created = portal_event_get($result['id'], false);
        if ($created && !empty($validated['data']['notify'])) {
            portal_event_send_notifications($created, 'new');
        }
        events_flash_set(['success', 'Event created.']);
        portal_redirect('events.php?event=' . $result['id']);
    }

    if ($action === 'update_event') {
        $id = (int) ($_POST['event_id'] ?? 0);
        $existing = portal_event_get($id, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            events_flash_set(['error', 'You are not allowed to edit this event.']);
            portal_redirect('events.php');
        }
        $validated = portal_event_validate_payload($_POST, true);
        if (!$validated['ok']) {
            events_flash_set(['error', implode(' ', $validated['errors'])]);
            $_SESSION['events_form'] = [
                'values' => $_POST,
                'errors' => $validated['errors'],
                'mode' => 'edit',
                'event_id' => $id,
            ];
            portal_redirect('events.php?event=' . $id . '&edit=1');
        }
        $result = portal_event_update($id, $validated['data']);
        if (!$result['ok']) {
            events_flash_set(['error', $result['error']]);
            portal_redirect('events.php?event=' . $id);
        }
        $updated = portal_event_get($id, false);
        if ($updated && portal_event_meaningful_changes($existing, $updated)) {
            portal_event_send_notifications($updated, 'updated');
        }
        events_flash_set(['success', 'Event updated.']);
        portal_redirect('events.php?event=' . $id);
    }

    if ($action === 'cancel_event') {
        $id = (int) ($_POST['event_id'] ?? 0);
        $existing = portal_event_get($id, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            events_flash_set(['error', 'You are not allowed to cancel this event.']);
            portal_redirect('events.php');
        }
        $result = portal_event_cancel($id);
        if (!$result['ok']) {
            events_flash_set(['error', $result['error']]);
            portal_redirect('events.php?event=' . $id);
        }
        $cancelled = portal_event_get($id, false);
        if ($cancelled) {
            portal_event_send_notifications($cancelled, 'cancelled');
        }
        events_flash_set(['success', 'Event cancelled. It remains visible in the list.']);
        portal_redirect('events.php?event=' . $id);
    }

    if ($action === 'delete_event') {
        $id = (int) ($_POST['event_id'] ?? 0);
        $confirm = trim((string) ($_POST['confirm_delete'] ?? ''));
        $existing = portal_event_get($id, false);
        if (!$existing || !portal_event_can_manage($existing)) {
            events_flash_set(['error', 'You are not allowed to delete this event.']);
            portal_redirect('events.php');
        }
        if (strcasecmp($confirm, 'DELETE') !== 0) {
            events_flash_set(['error', 'Type DELETE to permanently remove this event.']);
            portal_redirect('events.php?event=' . $id);
        }
        $result = portal_event_delete($id);
        if (!$result['ok']) {
            events_flash_set(['error', $result['error']]);
            portal_redirect('events.php?event=' . $id);
        }
        events_flash_set(['success', 'Event permanently deleted.']);
        portal_redirect('events.php');
    }

    portal_redirect('events.php');
}

if (isset($_SESSION['events_flash'])) {
    $flash = $_SESSION['events_flash'];
    unset($_SESSION['events_flash']);
}

if (isset($_SESSION['events_form']) && is_array($_SESSION['events_form'])) {
    $formValues = is_array($_SESSION['events_form']['values'] ?? null) ? $_SESSION['events_form']['values'] : [];
    $formErrors = is_array($_SESSION['events_form']['errors'] ?? null) ? $_SESSION['events_form']['errors'] : [];
    if (!empty($_SESSION['events_form']['event_id'])) {
        $editId = (int) $_SESSION['events_form']['event_id'];
    }
    unset($_SESSION['events_form']);
}

$page_title = 'Events | ' . portal_school_name();
$active_page = 'events';
$page_eyebrow = 'School events';
$page_heading = 'Events';
$page_description = 'One-off assemblies, workshops, trips, and course activities — separate from your weekly class timetable.';

$detailEvent = null;
if ($eventId > 0) {
    $detailEvent = portal_event_get($eventId, true);
    if (!$detailEvent) {
        events_flash_set(['error', 'That event is not available.']);
        portal_redirect('events.php');
    }
    $page_heading = (string) $detailEvent['title'];
    $page_description = portal_event_format_full((string) $detailEvent['starts_at']);
}

// Scope drives everything on the list page: stats, featured, and groups.
$upcomingScoped = $eventId > 0 ? [] : portal_events_list('upcoming', $scope, 200);
$events = $eventId > 0 ? [] : (
    $view === 'upcoming' ? $upcomingScoped : portal_events_list('past', $scope, 200)
);
$featured = ($eventId > 0 || $view !== 'upcoming') ? null : portal_event_featured($upcomingScoped);
$stats = portal_event_summary_stats($upcomingScoped);
$groups = ($view === 'upcoming' && $eventId <= 0) ? portal_event_group_upcoming(
    array_values(array_filter(
        $events,
        static fn (array $e): bool => $featured === null || (int) $e['id'] !== (int) $featured['id']
    ))
) : null;

$scopeLabels = [
    'all' => 'All events',
    'school' => 'School-wide',
    'courses' => 'My modules',
];
$scopeLabel = $scopeLabels[$scope] ?? 'All events';
$listEmpty = $view === 'upcoming'
    ? ($featured === null && ($groups === null || (
        ($groups['today'] ?? []) === []
        && ($groups['this_week'] ?? []) === []
        && ($groups['later'] ?? []) === []
    )))
    : ($events === []);

$editingEvent = null;
if ($detailEvent && $editId > 0 && portal_event_can_manage($detailEvent)) {
    $editingEvent = $detailEvent;
}

$extras = [
    ['title' => 'Device check', 'detail' => 'Test your audio and camera before live sessions so you are ready to join on time.'],
    ['title' => 'Timetable vs events', 'detail' => 'Weekly lessons stay on the Timetable. This page is only for dated, one-off activities.'],
    ['title' => 'Parent evenings', 'detail' => 'Parent evening details also appear in the school bulletin when published.'],
];

/**
 * @param array<string, mixed> $event
 * @return array{scope_html:string,time:string,place:string,cancelled:bool}
 */
function events_card_meta(array $event): array
{
    $cid = isset($event['course_id']) && $event['course_id'] !== null && $event['course_id'] !== ''
        ? (int) $event['course_id']
        : 0;
    if ($cid > 0) {
        $title = (string) ($event['course_title'] ?? 'Course');
        $accent = (string) ($event['course_accent'] ?? '');
        $swatch = $accent !== ''
            ? '<span class="dash-accent" style="background:' . portal_escape($accent) . '" aria-hidden="true"></span>'
            : '';
        $scopeHtml = $swatch . '<span>' . portal_escape($title) . '</span>';
    } else {
        $scopeHtml = '<span class="ev-scope-pill">School-wide</span>';
    }

    return [
        'scope_html' => $scopeHtml,
        'time' => portal_event_format_time_range($event),
        'place' => portal_event_place_label($event),
        'cancelled' => (string) ($event['status'] ?? '') === 'cancelled',
    ];
}

ob_start();
?>
<?php if ($flash): ?>
<div class="admin-flash <?= $flash[0] === 'success' ? 'success' : 'error' ?>"><?= portal_escape($flash[1]) ?></div>
<?php endif; ?>

<?php if ($detailEvent): ?>
<?php
    $canManageDetail = portal_event_can_manage($detailEvent);
    $chips = portal_event_chip_parts($detailEvent);
    $isCancelled = (string) ($detailEvent['status'] ?? '') === 'cancelled';
    $onlineUrl = trim((string) ($detailEvent['online_url'] ?? ''));
    $showOnline = $onlineUrl !== '' && portal_valid_external_url($onlineUrl);
    $cid = isset($detailEvent['course_id']) && $detailEvent['course_id'] !== null && $detailEvent['course_id'] !== ''
        ? (int) $detailEvent['course_id']
        : 0;
    $detailMeta = events_card_meta($detailEvent);
?>
<section class="events-layout events-layout--detail" id="event-detail">
    <div class="stack">
        <p class="ev-back"><a class="inline-action" href="<?= portal_escape(events_list_url()) ?>">← Back to events</a></p>

        <?php if ($isCancelled): ?>
        <div class="admin-flash error" role="status">This event has been cancelled.</div>
        <?php endif; ?>

        <article class="ev-hero<?= $isCancelled ? ' ev-hero--cancelled' : '' ?><?= !empty($detailEvent['important']) ? ' ev-hero--important' : '' ?>">
            <div class="ev-hero-header">
                <p class="eyebrow"><?= $cid > 0 ? 'Course event' : 'School-wide event' ?><?= !empty($detailEvent['important']) ? ' · Important' : '' ?></p>
                <span class="chip"><?= $isCancelled ? 'Cancelled' : 'Scheduled' ?></span>
            </div>
            <div class="ev-hero-body">
                <div class="ev-hero-date">
                    <strong><?= portal_escape($chips['day']) ?></strong>
                    <span><?= portal_escape($chips['month']) ?></span>
                </div>
                <div class="ev-hero-text">
                    <h3 class="ev-hero-title"><?= portal_escape((string) $detailEvent['title']) ?></h3>
                    <p><?= portal_escape((string) $detailEvent['summary']) ?></p>
                </div>
            </div>
            <div class="ev-hero-meta">
                <span><?= portal_escape($detailMeta['time']) ?></span>
                <span><?= portal_escape($detailMeta['place']) ?></span>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Details</p>
                    <h3 class="card-title">When and where</h3>
                </div>
            </div>
            <dl class="ev-detail-dl">
                <div>
                    <dt>Date</dt>
                    <dd>
                        <?= portal_escape(portal_event_format_full((string) $detailEvent['starts_at'])) ?>
                        <span class="ev-relative">(<?= portal_escape(portal_relative_time((string) $detailEvent['starts_at'])) ?>)</span>
                    </dd>
                </div>
                <?php if (trim((string) ($detailEvent['ends_at'] ?? '')) !== ''): ?>
                <div>
                    <dt>Ends</dt>
                    <dd><?= portal_escape(portal_event_format_full((string) $detailEvent['ends_at'])) ?></dd>
                </div>
                <?php endif; ?>
                <div>
                    <dt>Scope</dt>
                    <dd class="ev-meta-row"><?= $detailMeta['scope_html'] ?>
                        <?php if ($cid > 0 && !empty($detailEvent['course_slug'])): ?>
                            · <a class="inline-action" href="course.php?course=<?= portal_escape((string) $detailEvent['course_slug']) ?>&amp;section=calendar">Open course</a>
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>Location</dt>
                    <dd><?= portal_escape($detailMeta['place']) ?></dd>
                </div>
                <?php if ($showOnline): ?>
                <div>
                    <dt>Online link</dt>
                    <dd><a class="inline-action" href="<?= portal_escape($onlineUrl) ?>" target="_blank" rel="noopener noreferrer">Join online session</a></dd>
                </div>
                <?php endif; ?>
                <div>
                    <dt>Organiser</dt>
                    <dd><?= portal_escape((string) ($detailEvent['organiser_name'] ?? 'Staff')) ?></dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd><?= $isCancelled ? 'Cancelled' : 'Scheduled' ?></dd>
                </div>
            </dl>
            <?php if (trim((string) ($detailEvent['description'] ?? '')) !== ''): ?>
            <div class="ev-detail-body">
                <h4>Description</h4>
                <div class="rich-content"><?= portal_render_rich_text((string) $detailEvent['description']) ?></div>
            </div>
            <?php endif; ?>
        </article>

        <?php if ($canManageDetail): ?>
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Manage</p>
                    <h3 class="card-title">Staff actions</h3>
                </div>
            </div>
            <div class="button-row ev-manage-actions">
                <a class="button button-secondary" href="events.php?event=<?= (int) $detailEvent['id'] ?>&amp;edit=1#edit-event">Edit</a>
                <?php if (!$isCancelled): ?>
                <form method="POST" onsubmit="return confirm('Cancel this event? It will stay visible as cancelled.');">
                    <?= portal_csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_event">
                    <input type="hidden" name="event_id" value="<?= (int) $detailEvent['id'] ?>">
                    <button type="submit" class="button button-secondary">Cancel event</button>
                </form>
                <?php endif; ?>
            </div>

            <details class="folder-admin-panel" id="delete-event">
                <summary class="folder-admin-trigger"><span>Permanently delete</span></summary>
                <form method="POST" class="folder-admin-form">
                    <?= portal_csrf_field() ?>
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="<?= (int) $detailEvent['id'] ?>">
                    <p class="folder-form-hint">Only for mistakes or duplicates. Prefer Cancel to keep history. Type <strong>DELETE</strong> to confirm.</p>
                    <label class="folder-form-label">
                        <span>Confirmation</span>
                        <input type="text" name="confirm_delete" required autocomplete="off" placeholder="DELETE">
                    </label>
                    <button type="submit" class="button button-secondary">Delete permanently</button>
                </form>
            </details>
        </article>

        <?php if ($editingEvent || ($formValues && $editId === (int) $detailEvent['id'])): ?>
        <?php
            $fv = $formValues !== [] ? $formValues : [
                'title' => $detailEvent['title'],
                'summary' => $detailEvent['summary'],
                'description' => $detailEvent['description'],
                'starts_at' => events_datetime_local_value((string) $detailEvent['starts_at']),
                'ends_at' => events_datetime_local_value((string) ($detailEvent['ends_at'] ?? '')),
                'location' => $detailEvent['location'],
                'online_url' => $detailEvent['online_url'],
                'scope' => $cid > 0 ? 'course' : 'school',
                'course_id' => $cid,
                'important' => !empty($detailEvent['important']) ? '1' : '',
            ];
        ?>
        <details class="folder-admin-panel" id="edit-event" open>
            <summary class="folder-admin-trigger"><span>Edit event</span></summary>
            <?php if ($formErrors): ?>
            <div class="admin-flash error"><?= portal_escape(implode(' ', $formErrors)) ?></div>
            <?php endif; ?>
            <form method="POST" class="folder-admin-form">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="update_event">
                <input type="hidden" name="event_id" value="<?= (int) $detailEvent['id'] ?>">
                <?php require __DIR__ . '/../partials/event_form_fields.php'; ?>
                <button type="submit" class="button">Save changes</button>
            </form>
        </details>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>

<section class="events-layout" id="featured-events">
    <div class="stack ev-main">

        <article class="card-shell ev-filter-card">
            <div class="ev-filter-row">
                <div class="ev-filter-group">
                    <span class="ev-filter-label">When</span>
                    <div class="ev-filter-chips" role="tablist" aria-label="Time">
                        <a class="chip<?= $view === 'upcoming' ? ' is-active' : '' ?>" href="<?= portal_escape(events_list_url('upcoming', $scope)) ?>"<?= $view === 'upcoming' ? ' aria-current="page"' : '' ?>>Upcoming</a>
                        <a class="chip<?= $view === 'past' ? ' is-active' : '' ?>" href="<?= portal_escape(events_list_url('past', $scope)) ?>"<?= $view === 'past' ? ' aria-current="page"' : '' ?>>Past</a>
                    </div>
                </div>
                <div class="ev-filter-group">
                    <span class="ev-filter-label">Scope</span>
                    <div class="ev-filter-chips" role="group" aria-label="Scope">
                        <a class="chip<?= $scope === 'all' ? ' is-active' : '' ?>" href="<?= portal_escape(events_list_url($view, 'all')) ?>"<?= $scope === 'all' ? ' aria-current="page"' : '' ?>>All</a>
                        <a class="chip<?= $scope === 'school' ? ' is-active' : '' ?>" href="<?= portal_escape(events_list_url($view, 'school')) ?>"<?= $scope === 'school' ? ' aria-current="page"' : '' ?>>School-wide</a>
                        <a class="chip<?= $scope === 'courses' ? ' is-active' : '' ?>" href="<?= portal_escape(events_list_url($view, 'courses')) ?>"<?= $scope === 'courses' ? ' aria-current="page"' : '' ?>>My modules</a>
                    </div>
                </div>
            </div>
            <?php if (!$listEmpty): ?>
            <p class="ev-context" aria-live="polite">
                Showing <strong><?= portal_escape($scopeLabel) ?></strong>
                <?php if ($view === 'upcoming' && $stats['next']): ?>
                    · Next: <a href="events.php?event=<?= (int) $stats['next']['id'] ?>"><?= portal_escape((string) $stats['next']['title']) ?></a>
                    <span class="ev-context-muted">(<?= portal_escape(portal_relative_time((string) $stats['next']['starts_at'])) ?>)</span>
                    <?php if ((int) $stats['this_week'] > 0): ?>
                        · <strong><?= (int) $stats['this_week'] ?></strong> this week
                    <?php endif; ?>
                <?php elseif ($view === 'past'): ?>
                    · <strong><?= count($events) ?></strong> result<?= count($events) !== 1 ? 's' : '' ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
        </article>

        <?php if ($canCompose && ($isAdmin || $manageableCourses !== [])): ?>
        <details class="folder-admin-panel" id="create-event"<?= $formValues && empty($editId) ? ' open' : '' ?>>
            <summary class="folder-admin-trigger">
                <?= portal_icon('plus', 'icon-sm') ?>
                <span>Create event</span>
            </summary>
            <?php if ($formErrors && empty($editId)): ?>
            <div class="admin-flash error"><?= portal_escape(implode(' ', $formErrors)) ?></div>
            <?php endif; ?>
            <form method="POST" class="folder-admin-form">
                <?= portal_csrf_field() ?>
                <input type="hidden" name="action" value="create_event">
                <?php
                    $fv = $formValues !== [] && empty($editId) ? $formValues : [
                        'scope' => $isAdmin ? 'school' : 'course',
                        'course_id' => $manageableCourses[0]['id'] ?? 0,
                    ];
                    require __DIR__ . '/../partials/event_form_fields.php';
                ?>
                <button type="submit" class="button">Create event</button>
            </form>
        </details>
        <?php endif; ?>

        <?php if ($view === 'upcoming' && $featured): ?>
        <?php
            $fChips = portal_event_chip_parts($featured);
            $fMeta = events_card_meta($featured);
        ?>
        <a class="ev-hero-link" href="events.php?event=<?= (int) $featured['id'] ?>">
            <article class="ev-hero<?= !empty($featured['important']) ? ' ev-hero--important' : '' ?>">
                <div class="ev-hero-header">
                    <p class="eyebrow">Next up<?= $scope !== 'all' ? ' · ' . portal_escape($scopeLabel) : '' ?></p>
                    <span class="ev-hero-cta">View details</span>
                </div>
                <div class="ev-hero-body">
                    <div class="ev-hero-date">
                        <strong><?= portal_escape($fChips['day']) ?></strong>
                        <span><?= portal_escape($fChips['month']) ?></span>
                    </div>
                    <div class="ev-hero-text">
                        <h3 class="ev-hero-title"><?= portal_escape((string) $featured['title']) ?></h3>
                        <p><?= portal_escape((string) $featured['summary']) ?></p>
                    </div>
                </div>
                <div class="ev-hero-meta">
                    <span class="ev-meta-row"><?= $fMeta['scope_html'] ?></span>
                    <span><?= portal_escape($fMeta['time']) ?></span>
                    <span><?= portal_escape($fMeta['place']) ?></span>
                </div>
            </article>
        </a>
        <?php endif; ?>

        <?php if ($listEmpty): ?>
            <article class="card-shell ev-empty">
                <h3 class="card-title"><?= $view === 'past' ? 'No past events here' : 'Nothing coming up' ?></h3>
                <p class="dash-empty">
                    <?php if ($scope !== 'all'): ?>
                        No <?= $view === 'past' ? 'past' : 'upcoming' ?> events in <strong><?= portal_escape($scopeLabel) ?></strong>.
                    <?php else: ?>
                        There are no <?= $view === 'past' ? 'past' : 'upcoming' ?> events to show yet.
                    <?php endif; ?>
                </p>
                <div class="ev-empty-actions">
                    <?php if ($scope !== 'all'): ?>
                    <a class="button button-secondary" href="<?= portal_escape(events_list_url($view, 'all')) ?>">Show all events</a>
                    <?php endif; ?>
                    <?php if ($view === 'past'): ?>
                    <a class="button button-secondary" href="<?= portal_escape(events_list_url('upcoming', $scope)) ?>">Back to upcoming</a>
                    <?php endif; ?>
                    <?php if ($canCompose && ($isAdmin || $manageableCourses !== [])): ?>
                    <a class="button" href="#create-event">Create an event</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php elseif ($view === 'upcoming' && $groups): ?>
            <?php
            $sections = [
                'today' => 'Today',
                'this_week' => 'This week',
                'later' => 'Later',
            ];
            foreach ($sections as $key => $label):
                $items = $groups[$key] ?? [];
                if ($items === []) {
                    continue;
                }
            ?>
            <article class="card-shell ev-section">
                <div class="section-head">
                    <div>
                        <p class="eyebrow"><?= portal_escape($scopeLabel) ?></p>
                        <h3 class="card-title"><?= portal_escape($label) ?></h3>
                    </div>
                    <span class="chip"><?= count($items) ?></span>
                </div>
                <div class="ev-list">
                    <?php foreach ($items as $event): ?>
                    <?php
                        $chips = portal_event_chip_parts($event);
                        $meta = events_card_meta($event);
                    ?>
                    <a class="ev-item<?= $meta['cancelled'] ? ' ev-item--cancelled' : '' ?>" href="events.php?event=<?= (int) $event['id'] ?>">
                        <div class="ev-date">
                            <strong><?= portal_escape($chips['day']) ?></strong>
                            <span><?= portal_escape($chips['month']) ?></span>
                        </div>
                        <div class="ev-body">
                            <div class="ev-item-top">
                                <h3><?= portal_escape((string) $event['title']) ?></h3>
                                <?php if ($meta['cancelled']): ?>
                                <span class="ev-status-tag">Cancelled</span>
                                <?php endif; ?>
                            </div>
                            <p class="ev-meta-row"><?= $meta['scope_html'] ?></p>
                            <p class="event-location"><?= portal_escape($meta['time']) ?> · <?= portal_escape($meta['place']) ?></p>
                            <p><?= portal_escape((string) $event['summary']) ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </article>
            <?php endforeach; ?>
        <?php elseif ($view === 'past'): ?>
            <article class="card-shell ev-section">
                <div class="section-head">
                    <div>
                        <p class="eyebrow"><?= portal_escape($scopeLabel) ?></p>
                        <h3 class="card-title">Past events</h3>
                    </div>
                    <span class="chip"><?= count($events) ?></span>
                </div>
                <div class="ev-list">
                    <?php foreach ($events as $event): ?>
                    <?php
                        $chips = portal_event_chip_parts($event);
                        $meta = events_card_meta($event);
                    ?>
                    <a class="ev-item<?= $meta['cancelled'] ? ' ev-item--cancelled' : '' ?>" href="events.php?event=<?= (int) $event['id'] ?>">
                        <div class="ev-date">
                            <strong><?= portal_escape($chips['day']) ?></strong>
                            <span><?= portal_escape($chips['month']) ?></span>
                        </div>
                        <div class="ev-body">
                            <div class="ev-item-top">
                                <h3><?= portal_escape((string) $event['title']) ?></h3>
                                <?php if ($meta['cancelled']): ?>
                                <span class="ev-status-tag">Cancelled</span>
                                <?php endif; ?>
                            </div>
                            <p class="ev-meta-row"><?= $meta['scope_html'] ?></p>
                            <p class="event-location"><?= portal_escape($meta['time']) ?> · <?= portal_escape($meta['place']) ?></p>
                            <p><?= portal_escape((string) $event['summary']) ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endif; ?>

    </div>

    <aside class="stack ev-side">
        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Tips</p>
                    <h3 class="card-title">Before you attend</h3>
                </div>
            </div>
            <div class="ev-notes">
                <?php foreach ($extras as $i => $extra): ?>
                <div class="ev-note">
                    <span class="ev-note-num"><?= $i + 1 ?></span>
                    <div>
                        <strong><?= portal_escape($extra['title']) ?></strong>
                        <p><?= portal_escape($extra['detail']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="card-shell">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Next step</p>
                    <h3 class="card-title">Plan your week</h3>
                </div>
            </div>
            <article class="schedule-note">
                <div>
                    <h3>Use the timetable</h3>
                    <p>Check your live class schedule before planning around an event.</p>
                </div>
                <a class="inline-action" href="timetable.php#week-view">Open timetable</a>
            </article>
        </article>
    </aside>
</section>
<?php endif; ?>
<?php
$page_content = ob_get_clean();

require __DIR__ . '/../layout.php';
