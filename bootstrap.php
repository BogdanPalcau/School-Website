<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Harden the session cookie: not readable by JS, sent same-site, and
    // marked Secure automatically when the request is served over HTTPS.
    $portalCookieSecure = (
        (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $portalCookieSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Utilities ─────────────────────────────────────────────────────────────────

if (!function_exists('portal_escape')) {
    function portal_escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('portal_is_safe_rich_text_href')) {
    function portal_is_safe_rich_text_href(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $href)) {
            return false;
        }

        $lower = strtolower($href);
        foreach (['javascript:', 'data:', 'vbscript:', 'file:'] as $blocked) {
            if (str_starts_with($lower, $blocked)) {
                return false;
            }
        }

        return (bool) preg_match('#^(https?:|mailto:)#i', $href);
    }
}

if (!function_exists('portal_rich_text_strip_tags')) {
    /** Tags removed entirely with all descendant content (never unwrapped). */
    function portal_rich_text_strip_tags(): array
    {
        return [
            'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math',
            'meta', 'link', 'base', 'form', 'input', 'button', 'textarea',
            'select', 'option',
        ];
    }
}

if (!function_exists('portal_sanitize_rich_text_element')) {
    /**
     * @param array<string, list<string>> $allowedTags
     * @param list<string> $allowedSpanClasses
     * @param list<string> $stripTags
     */
    function portal_sanitize_rich_text_element(
        DOMElement $element,
        array $allowedTags,
        array $allowedSpanClasses,
        array $stripTags
    ): void {
        $tag = strtolower($element->tagName);
        if (in_array($tag, $stripTags, true)) {
            $parent = $element->parentNode;
            if ($parent !== null) {
                $parent->removeChild($element);
            }
            return;
        }

        $children = [];
        foreach ($element->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                portal_sanitize_rich_text_element($child, $allowedTags, $allowedSpanClasses, $stripTags);
            }
        }

        if (!array_key_exists($tag, $allowedTags)) {
            $parent = $element->parentNode;
            if ($parent !== null) {
                while ($element->firstChild !== null) {
                    $parent->insertBefore($element->firstChild, $element);
                }
                $parent->removeChild($element);
            }
            return;
        }

        $allowedAttrs = $allowedTags[$tag];
        $removeAttrs = [];
        if ($element->hasAttributes()) {
            foreach ($element->attributes as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on')) {
                    $removeAttrs[] = $attr->name;
                    continue;
                }
                if (!in_array($name, $allowedAttrs, true)) {
                    $removeAttrs[] = $attr->name;
                    continue;
                }
                if ($name === 'class' && $tag === 'span') {
                    $classes = preg_split('/\s+/', trim($attr->value)) ?: [];
                    $classes = array_values(array_intersect($classes, $allowedSpanClasses));
                    if ($classes === []) {
                        $removeAttrs[] = $attr->name;
                    } else {
                        $element->setAttribute('class', implode(' ', $classes));
                    }
                }
                if ($name === 'href' && $tag === 'a' && !portal_is_safe_rich_text_href($attr->value)) {
                    $removeAttrs[] = $attr->name;
                }
            }
        }
        foreach ($removeAttrs as $name) {
            $element->removeAttribute($name);
        }

        if ($tag === 'a') {
            if (!$element->hasAttribute('href')) {
                $parent = $element->parentNode;
                if ($parent !== null) {
                    while ($element->firstChild !== null) {
                        $parent->insertBefore($element->firstChild, $element);
                    }
                    $parent->removeChild($element);
                }
                return;
            }
            // External http(s) links open in a new tab with tab-nabbing protection.
            // mailto: links keep a plain anchor (no target="_blank").
            $href = trim($element->getAttribute('href'));
            if (preg_match('#^https?://#i', $href)) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        }
    }
}

if (!function_exists('portal_sanitize_rich_text')) {
    /**
     * Allowlist HTML sanitizer for stored rich text (Quill output, announcements,
     * discussion posts). Strips dangerous tags/attributes instead of regex filtering.
     */
    function portal_sanitize_rich_text(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return portal_escape(strip_tags($body));
        }

        $allowedTags = [
            'p'          => [],
            'br'         => [],
            'strong'     => [],
            'b'          => [],
            'em'         => [],
            'i'          => [],
            'u'          => [],
            's'          => [],
            'h1'         => [],
            'h2'         => [],
            'h3'         => [],
            'ul'         => [],
            'ol'         => [],
            'li'         => [],
            'blockquote' => [],
            'span'       => ['class'],
            'a'          => ['href'],
        ];
        $allowedSpanClasses = ['ql-align-center', 'ql-align-right', 'ql-align-justify'];
        $stripTags = portal_rich_text_strip_tags();

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="portal-rich-root">' . $body . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $dom->getElementById('portal-rich-root');
        if ($root === null) {
            return portal_escape(strip_tags($body));
        }

        $rootChildren = [];
        foreach ($root->childNodes as $child) {
            $rootChildren[] = $child;
        }
        foreach ($rootChildren as $child) {
            if ($child instanceof DOMElement) {
                portal_sanitize_rich_text_element($child, $allowedTags, $allowedSpanClasses, $stripTags);
            }
        }

        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }

        if (portal_rich_text_contains_dangerous_markup($body)) {
            portal_log_security_event(
                'unsafe_rich_text_removed',
                'medium',
                'Blocked unsafe HTML in submitted content'
            );
        }

        return $clean;
    }
}

if (!function_exists('portal_rich_text_contains_dangerous_markup')) {
    function portal_rich_text_contains_dangerous_markup(string $body): bool
    {
        if ($body === '') {
            return false;
        }

        $lower = strtolower($body);
        $needles = [
            '<script', '<iframe', '<style', '<object', '<embed', '<svg', '<math',
            '<form', '<input', '<button', '<textarea', '<meta', '<link', '<base',
            'javascript:', 'vbscript:', 'data:text/html', 'onerror=', 'onclick=',
            'onload=', 'onmouseover=', 'onfocus=',
        ];

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('portal_render_rich_text')) {
    function portal_render_rich_text(string $body): string
    {
        return portal_sanitize_rich_text($body);
    }
}

if (!function_exists('portal_school_name')) {
    function portal_school_name(): string
    {
        return 'Rangoon International Education Online';
    }
}

if (!function_exists('portal_school_short_name')) {
    function portal_school_short_name(): string
    {
        return 'RIEO';
    }
}

if (!function_exists('portal_icon')) {
    function portal_icon(string $name, string $class = 'icon'): string
    {
        $icons = [
            'book-open'  => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'calendar'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'megaphone'  => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'sparkles'   => '<path d="m12 3-1.9 5.8L4 11l6.1 2.2L12 19l1.9-5.8L20 11l-6.1-2.2L12 3z"/><path d="M5 3v4"/><path d="M3 5h4"/><path d="M19 17v4"/><path d="M17 19h4"/>',
            'settings'   => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 1 1 7.1 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1a2 2 0 1 1 0 4H21a1.7 1.7 0 0 0-1.6 1z"/>',
            'log-out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'user'       => '<path d="M19 21a7 7 0 0 0-14 0"/><circle cx="12" cy="8" r="4"/>',
            'lock'       => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'arrow-right'=> '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'folder'        => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'file'          => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><polyline points="14 2 14 8 20 8"/>',
            'presentation'  => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 18v3"/><path d="M8 9h8"/><path d="M8 13h5"/>',
            'link'          => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'upload'        => '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>',
            'plus'          => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'trash'         => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
            'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
            'video'         => '<path d="m22 8.5-6 3.5 6 3.5v-7Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
            'play'          => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'grip'          => '<circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'edit'          => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'pin'           => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
            'alert'         => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
        ];

        $body = $icons[$name] ?? $icons['book-open'];
        return '<svg class="' . portal_escape($class) . '" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }
}

if (!function_exists('portal_presentation_extensions')) {
    function portal_presentation_extensions(): array
    {
        return ['ppt', 'pptx', 'pps', 'ppsx', 'pot', 'potx', 'odp'];
    }
}

if (!function_exists('portal_supported_upload_extensions')) {
    function portal_supported_upload_extensions(): array
    {
        return array_merge(['doc', 'docx', 'xlsx', 'pdf', 'txt'], portal_presentation_extensions());
    }
}

if (!function_exists('portal_is_presentation_file')) {
    function portal_is_presentation_file(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, portal_presentation_extensions(), true);
    }
}

if (!function_exists('portal_supported_upload_hint')) {
    function portal_supported_upload_hint(): string
    {
        return '.doc .docx .xlsx .pdf .txt .ppt .pptx .pps .ppsx .pot .potx .odp';
    }
}

if (!function_exists('portal_video_extensions')) {
    function portal_video_extensions(): array
    {
        return ['mp4', 'webm', 'ogv', 'ogg', 'mov', 'm4v'];
    }
}

if (!function_exists('portal_supported_video_upload_hint')) {
    function portal_supported_video_upload_hint(): string
    {
        return '.mp4 .webm .mov .m4v .ogv - max 400 MB';
    }
}

if (!function_exists('portal_is_video_file')) {
    function portal_is_video_file(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, portal_video_extensions(), true);
    }
}

if (!function_exists('portal_video_mime_for_extension')) {
    function portal_video_mime_for_extension(string $ext): string
    {
        return match (strtolower($ext)) {
            'mp4', 'm4v' => 'video/mp4',
            'webm'       => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            'mov'        => 'video/quicktime',
            default      => 'application/octet-stream',
        };
    }
}

if (!function_exists('portal_format_video_timestamp')) {
    function portal_format_video_timestamp(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%d:%02d', $m, $s);
    }
}

if (!function_exists('portal_submission_deadline_info')) {
    /**
     * @return array{has_deadline: bool, text: string, state: string, passed: bool, timestamp?: int}
     */
    function portal_submission_deadline_info(string $deadlineRaw): array
    {
        if (trim($deadlineRaw) === '') {
            return [
                'has_deadline' => false,
                'text' => 'No deadline set',
                'state' => 'none',
                'passed' => false,
            ];
        }

        $ts = strtotime($deadlineRaw);
        if ($ts === false) {
            return [
                'has_deadline' => false,
                'text' => 'No deadline set',
                'state' => 'none',
                'passed' => false,
            ];
        }

        $passed = time() > $ts;
        $text = date('j M Y H:i', $ts);
        if ($passed) {
            return [
                'has_deadline' => true,
                'text' => $text,
                'state' => 'closed',
                'passed' => true,
                'timestamp' => $ts,
            ];
        }

        $hoursLeft = ($ts - time()) / 3600;

        return [
            'has_deadline' => true,
            'text' => $text,
            'state' => $hoursLeft <= 48 ? 'soon' : 'open',
            'passed' => false,
            'timestamp' => $ts,
        ];
    }
}

if (!function_exists('portal_render_submission_deadline')) {
    function portal_render_submission_deadline(string $deadlineRaw, string $modifier = ''): string
    {
        $info = portal_submission_deadline_info($deadlineRaw);
        $classes = 'sub-slot-deadline sub-slot-deadline--' . $info['state'];
        if ($modifier !== '') {
            $classes .= ' ' . $modifier;
        }

        ob_start();
        ?>
        <div class="<?= portal_escape($classes) ?>">
            <span class="sub-slot-deadline-icon"><?= portal_icon('clock', 'icon-xs') ?></span>
            <span class="sub-slot-deadline-body">
                <span class="sub-slot-deadline-label">Due date</span>
                <strong class="sub-slot-deadline-value"><?= portal_escape($info['text']) ?></strong>
            </span>
            <?php if ($info['passed']): ?>
                <span class="sub-slot-deadline-tag sub-slot-deadline-tag--closed">Closed</span>
            <?php elseif ($info['state'] === 'soon'): ?>
                <span class="sub-slot-deadline-tag sub-slot-deadline-tag--soon">Due soon</span>
            <?php endif; ?>
        </div>
        <?php
        return trim((string) ob_get_clean());
    }
}

// ── Database ──────────────────────────────────────────────────────────────────

if (!function_exists('portal_db_path')) {
    /**
     * Absolute path to the SQLite database file.
     * Override with PORTAL_DB_PATH to store the DB outside the web root, e.g.
     * PORTAL_DB_PATH=C:\xampp\schoolwebsite-data\portal.db
     */
    function portal_db_path(): string
    {
        static $path = null;
        if ($path !== null) {
            return $path;
        }

        $envPath = getenv('PORTAL_DB_PATH');
        if ($envPath !== false && trim($envPath) !== '') {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($envPath));
            return $path;
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'portal.db';
        return $path;
    }
}

if (!function_exists('portal_document_root')) {
    function portal_document_root(): string
    {
        $root = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($root === '') {
            return '';
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists('portal_db_is_in_webroot')) {
    /** True when the SQLite file lives under the web-served app tree. */
    function portal_db_is_in_webroot(): bool
    {
        $dbPath = portal_db_path();
        $comparePath = is_file($dbPath) ? $dbPath : dirname($dbPath);
        $realDb = realpath($comparePath);
        if ($realDb === false) {
            return false;
        }

        $realApp = realpath(__DIR__);
        if ($realApp !== false) {
            $appPrefix = rtrim(strtolower($realApp), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with(strtolower($realDb), $appPrefix)) {
                return true;
            }
        }

        $docRoot = portal_document_root();
        if ($docRoot === '') {
            return false;
        }

        $realDoc = realpath($docRoot);
        if ($realDoc === false) {
            return false;
        }

        $docPrefix = rtrim(strtolower($realDoc), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $target = strtolower($realDb);

        return $target === strtolower($realDoc) || str_starts_with($target, $docPrefix);
    }
}

if (!function_exists('portal_db_security_warning')) {
    /** Human-readable warning when the DB is still web-accessible. */
    function portal_db_security_warning(): ?string
    {
        if (!portal_db_is_in_webroot()) {
            return null;
        }

        return 'Security warning: the SQLite database is stored inside the web root at '
            . portal_db_path()
            . '. Move it outside htdocs using PORTAL_DB_PATH, or confirm Apache .htaccess '
            . 'rules return 403 for /database/portal.db before going to production.';
    }
}

if (!function_exists('portal_db')) {
    function portal_db(): PDO
    {
        static $pdo = null;

        if ($pdo === null) {
            $path = portal_db_path();
            $dir  = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }
}

// ── Users ─────────────────────────────────────────────────────────────────────

if (!function_exists('portal_find_user')) {
    function portal_find_user(string $identifier): ?array
    {
        $needle = strtolower(trim($identifier));
        $stmt   = portal_db()->prepare(
            "SELECT * FROM users WHERE LOWER(username) = ? OR LOWER(email) = ? LIMIT 1"
        );
        $stmt->execute([$needle, $needle]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('portal_find_user_by_id')) {
    function portal_find_user_by_id(int $id): ?array
    {
        $stmt = portal_db()->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}

if (!function_exists('portal_all_users')) {
    function portal_all_users(): array
    {
        return portal_db()
            ->query("SELECT * FROM users ORDER BY role ASC, name ASC")
            ->fetchAll();
    }
}

if (!function_exists('portal_default_student')) {
    function portal_default_student(): array
    {
        return [
            'id'        => 0,
            'name'      => 'Student',
            'year'      => 'Year group',
            'programme' => 'Student pathway',
            'initials'  => 'ST',
            'role'      => 'student',
        ];
    }
}

// ── Session / Auth ────────────────────────────────────────────────────────────

if (!function_exists('portal_is_logged_in')) {
    function portal_is_logged_in(): bool
    {
        return isset($_SESSION['portal_user']) && is_array($_SESSION['portal_user']);
    }
}

if (!function_exists('portal_current_user')) {
    function portal_current_user(): array
    {
        if (!portal_is_logged_in()) {
            return portal_default_student();
        }

        return $_SESSION['portal_user'];
    }
}

if (!function_exists('portal_current_user_role')) {
    function portal_current_user_role(): string
    {
        $user = portal_current_user();

        // Always prefer the role from the database when we have a user id.
        // This avoids stale session values showing the wrong account type.
        if (isset($user['id']) && (int) $user['id'] > 0) {
            $stmt = portal_db()->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([(int) $user['id']]);
            $dbRole = (string) ($stmt->fetchColumn() ?: '');

            if ($dbRole !== '') {
                $_SESSION['portal_user']['role'] = $dbRole;
                return $dbRole;
            }
        }

        if (isset($user['role']) && $user['role'] !== '') {
            return $user['role'];
        }

        return 'student';
    }
}

if (!function_exists('portal_is_admin')) {
    function portal_is_admin(): bool
    {
        return in_array(portal_current_user_role(), ['admin', 'owner'], true);
    }
}

if (!function_exists('portal_is_owner')) {
    function portal_is_owner(): bool
    {
        return portal_current_user_role() === 'owner';
    }
}

if (!function_exists('portal_require_admin')) {
    function portal_require_admin(): void
    {
        portal_require_login();

        if (!portal_is_admin()) {
            portal_log_security_event(
                'unauthorised_admin_access',
                'high',
                'Blocked access to admin panel'
            );
            portal_redirect('courses.php');
        }
    }
}

if (!function_exists('portal_enrolled_course_ids')) {
    function portal_enrolled_course_ids(int $user_id): array
    {
        $stmt = portal_db()->prepare(
            "SELECT course_id FROM enrollments WHERE user_id = ?"
        );
        $stmt->execute([$user_id]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('portal_login_time_text')) {
    function portal_login_time_text(): string
    {
        $value = $_SESSION['portal_login_at'] ?? '';

        if (!is_string($value) || $value === '') {
            return 'Today';
        }

        return $value;
    }
}

if (!function_exists('portal_redirect')) {
    function portal_redirect(string $location): never
    {
        header('Location: ' . $location);
        exit;
    }
}

if (!function_exists('portal_store_intended_path')) {
    function portal_store_intended_path(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';

        if ($requestUri !== '' && !str_contains($requestUri, '/login.php')) {
            $_SESSION['portal_intended_path'] = $requestUri;
        }
    }
}

if (!function_exists('portal_consume_intended_path')) {
    function portal_consume_intended_path(): string
    {
        $default = 'courses.php';
        $target  = $_SESSION['portal_intended_path'] ?? $default;
        unset($_SESSION['portal_intended_path']);

        if (!is_string($target) || $target === '' || str_contains($target, '://') || str_starts_with($target, '//')) {
            return $default;
        }

        return $target;
    }
}

if (!function_exists('portal_require_login')) {
    function portal_require_login(): void
    {
        if (!portal_is_logged_in()) {
            portal_store_intended_path();
            portal_redirect('login.php');
        }
    }
}

if (!function_exists('portal_attempt_login')) {
    function portal_attempt_login(string $identifier, string $password): bool
    {
        $user = portal_find_user($identifier);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['portal_user'] = [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'name'      => $user['name'],
            'year'      => $user['year'],
            'programme' => $user['programme'],
            'initials'  => $user['initials'],
            'role'      => $user['role'],
        ];
        $_SESSION['portal_login_at'] = date('l j F Y \a\t H:i');

        return true;
    }
}

if (!function_exists('portal_logout')) {
    function portal_logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }

        session_destroy();
    }
}

// ── CSRF protection helpers ───────────────────────────────────────────────────

if (!function_exists('portal_csrf_token')) {
    function portal_csrf_token(): string
    {
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('portal_csrf_field')) {
    function portal_csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . portal_escape(portal_csrf_token()) . '">';
    }
}

if (!function_exists('portal_verify_csrf')) {
    function portal_verify_csrf(): bool
    {
        $token = $_POST['_token'] ?? '';
        $valid = is_string($token)
            && $token !== ''
            && !empty($_SESSION['_csrf'])
            && hash_equals((string) $_SESSION['_csrf'], $token);

        if (
            !$valid
            && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST'
        ) {
            portal_log_security_event(
                'csrf_failed',
                'high',
                'Invalid or missing security token on form submission'
            );
        }

        return $valid;
    }
}

// ── Course access control (enrollment / management) ───────────────────────────

if (!function_exists('portal_can_access_course')) {
    function portal_can_access_course(int $courseId): bool
    {
        if ($courseId <= 0 || !portal_is_logged_in()) {
            return false;
        }
        // Admins, owners, and teachers assigned to the course can always enter.
        if (portal_can_manage_course($courseId)) {
            return true;
        }
        // Otherwise the user must be enrolled.
        $user = portal_current_user();
        $stmt = portal_db()->prepare(
            "SELECT 1 FROM enrollments WHERE user_id = ? AND course_id = ? LIMIT 1"
        );
        $stmt->execute([(int) $user['id'], $courseId]);
        return (bool) $stmt->fetchColumn();
    }
}

// ── Login brute-force throttling (per client IP) ──────────────────────────────

if (!function_exists('portal_client_ip')) {
    function portal_client_ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return $ip !== '' ? $ip : 'unknown';
    }
}

if (!function_exists('portal_login_is_locked')) {
    function portal_login_is_locked(string $ip, int $maxAttempts = 8, int $windowSeconds = 900): bool
    {
        try {
            $stmt = portal_db()->prepare(
                "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?"
            );
            $stmt->execute([$ip, time() - $windowSeconds]);
            return (int) $stmt->fetchColumn() >= $maxAttempts;
        } catch (\PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('portal_login_record_failure')) {
    function portal_login_record_failure(string $ip): void
    {
        try {
            portal_db()
                ->prepare("INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)")
                ->execute([$ip, time()]);
        } catch (\PDOException $e) {}
    }
}

if (!function_exists('portal_login_clear_attempts')) {
    function portal_login_clear_attempts(string $ip): void
    {
        try {
            portal_db()
                ->prepare("DELETE FROM login_attempts WHERE ip = ?")
                ->execute([$ip]);
        } catch (\PDOException $e) {}
    }
}

// ── Security event logging ─────────────────────────────────────────────────────

if (!function_exists('portal_show_developer_security')) {
    function portal_show_developer_security(): bool
    {
        $flag = getenv('PORTAL_SHOW_DEVELOPER_SECURITY');

        return $flag !== false && trim((string) $flag) === '1';
    }
}

if (!function_exists('portal_security_request_route')) {
    function portal_security_request_route(): string
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return 'unknown';
        }

        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($query === '') {
            return $script;
        }

        return $script . '?' . substr($query, 0, 120);
    }
}

if (!function_exists('portal_security_sanitize_details')) {
    function portal_security_sanitize_details(string $details): string
    {
        $details = trim($details);
        if ($details === '') {
            return '';
        }

        $details = preg_replace('/[A-Z]:\\\\[^\s]+/i', '[path]', $details) ?? $details;
        $details = preg_replace('#/[a-z0-9_./-]+#i', '[path]', $details) ?? $details;

        return substr($details, 0, 500);
    }
}

if (!function_exists('portal_log_security_event')) {
  /**
   * @param 'info'|'low'|'medium'|'high' $severity
   */
    function portal_log_security_event(
        string $eventType,
        string $severity = 'info',
        string $details = '',
        ?int $userId = null
    ): void {
        try {
            $allowedSeverity = ['info', 'low', 'medium', 'high'];
            if (!in_array($severity, $allowedSeverity, true)) {
                $severity = 'info';
            }

            $username = '';
            if ($userId === null && portal_is_logged_in()) {
                $user = portal_current_user();
                $userId = (int) ($user['id'] ?? 0);
                $username = (string) ($user['username'] ?? '');
            } elseif ($userId !== null && $userId > 0) {
                $found = portal_find_user_by_id($userId);
                $username = $found ? (string) $found['username'] : '';
            }

            $ip = portal_client_ip();
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $route = portal_security_request_route();
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $details = portal_security_sanitize_details($details);

            portal_db()->prepare("
                INSERT INTO security_events
                    (event_type, severity, user_id, username, ip_address, user_agent, route, method, details)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                substr($eventType, 0, 64),
                $severity,
                $userId !== null && $userId > 0 ? $userId : null,
                substr($username, 0, 80),
                substr($ip, 0, 64),
                $ua,
                substr($route, 0, 200),
                substr($method, 0, 10),
                $details,
            ]);
        } catch (\Throwable $e) {
            // Never break the app if logging fails.
        }
    }
}

if (!function_exists('portal_log_blocked_upload')) {
    function portal_log_blocked_upload(string $reason): void
    {
        $summary = 'Rejected upload';
        $reason = trim($reason);
        if ($reason !== '') {
            if (str_contains($reason, 'does not match')) {
                $summary = 'Rejected upload: invalid file content';
            } elseif (str_contains($reason, 'Unsupported file type')) {
                $summary = 'Rejected upload: invalid file type';
            } elseif (str_contains($reason, 'too large')) {
                $summary = 'Rejected upload: file too large';
            } elseif (str_contains($reason, 'blocked the upload')) {
                $summary = 'Rejected upload: blocked by server';
            } else {
                $summary = 'Rejected upload: ' . substr($reason, 0, 120);
            }
        }

        portal_log_security_event('blocked_upload', 'medium', $summary);
    }
}

if (!function_exists('portal_security_period_sql')) {
    function portal_security_period_sql(string $period): string
    {
        return match ($period) {
            '7d'  => "datetime('now', '-7 days')",
            '30d' => "datetime('now', '-30 days')",
            default => "datetime('now', '-1 day')",
        };
    }
}

if (!function_exists('portal_security_event_type_label')) {
    function portal_security_event_type_label(string $eventType): string
    {
        return match ($eventType) {
            'failed_login'               => 'Failed login',
            'login_throttled'            => 'Login throttled',
            'csrf_failed'                => 'CSRF blocked',
            'unauthorised_admin_access'  => 'Admin access blocked',
            'unauthorised_course_access' => 'Course access blocked',
            'forbidden_download'         => 'Download blocked',
            'blocked_upload'             => 'Upload blocked',
            'unsafe_rich_text_removed'   => 'Unsafe content removed',
            'role_changed'               => 'Role changed',
            'user_deleted'               => 'User deleted',
            'course_archived'            => 'Course archived',
            'course_restored'            => 'Course restored',
            default                      => ucwords(str_replace('_', ' ', $eventType)),
        };
    }
}

if (!function_exists('portal_security_dashboard_stats')) {
    /**
     * @return array<string, int>
     */
    function portal_security_dashboard_stats(string $period = '24h'): array
    {
        try {
            $since = portal_security_period_sql($period);
            $pdo = portal_db();

            $countSince = static function (string $extraWhere = '') use ($pdo, $since): int {
                $sql = "SELECT COUNT(*) FROM security_events WHERE created_at >= {$since}";
                if ($extraWhere !== '') {
                    $sql .= ' AND ' . $extraWhere;
                }

                return (int) $pdo->query($sql)->fetchColumn();
            };

            return [
                'active_alerts'      => (int) $pdo->query(
                    "SELECT COUNT(*) FROM security_events WHERE reviewed = 0 AND severity IN ('medium', 'high')"
                )->fetchColumn(),
                'failed_logins'      => $countSince("event_type = 'failed_login'"),
                'blocked_access'     => $countSince("event_type IN ('unauthorised_admin_access', 'unauthorised_course_access', 'forbidden_download')"),
                'blocked_uploads'    => $countSince("event_type = 'blocked_upload'"),
                'unsafe_content'     => $countSince("event_type = 'unsafe_rich_text_removed'"),
                'admin_actions'      => $countSince("event_type IN ('role_changed', 'user_deleted', 'course_archived', 'course_restored')"),
                'csrf_failures'      => $countSince("event_type = 'csrf_failed'"),
            ];
        } catch (\Throwable $e) {
            return [
                'active_alerts'   => 0,
                'failed_logins'   => 0,
                'blocked_access'  => 0,
                'blocked_uploads' => 0,
                'unsafe_content'  => 0,
                'admin_actions'   => 0,
                'csrf_failures'   => 0,
            ];
        }
    }
}

if (!function_exists('portal_security_events_filtered')) {
    /**
     * @return list<array<string, mixed>>
     */
    function portal_security_events_filtered(
        string $period = '24h',
        string $reviewed = 'all',
        string $severity = 'all',
        string $eventType = 'all',
        int $limit = 100
    ): array {
        try {
            $since = portal_security_period_sql($period);
            $where = ["created_at >= {$since}"];
            $params = [];

            if ($reviewed === 'unreviewed') {
                $where[] = 'reviewed = 0';
            } elseif ($reviewed === 'reviewed') {
                $where[] = 'reviewed = 1';
            }

            if ($severity !== 'all' && in_array($severity, ['info', 'low', 'medium', 'high'], true)) {
                $where[] = 'severity = ?';
                $params[] = $severity;
            }

            if ($eventType !== 'all' && $eventType !== '') {
                $where[] = 'event_type = ?';
                $params[] = $eventType;
            }

            $sql = 'SELECT * FROM security_events WHERE ' . implode(' AND ', $where)
                . ' ORDER BY created_at DESC LIMIT ' . max(1, min($limit, 250));

            $stmt = portal_db()->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('portal_mark_security_event_reviewed')) {
    function portal_mark_security_event_reviewed(int $eventId, int $reviewerId): bool
    {
        if ($eventId <= 0 || $reviewerId <= 0) {
            return false;
        }

        try {
            $stmt = portal_db()->prepare("
                UPDATE security_events
                SET reviewed = 1, reviewed_at = datetime('now'), reviewed_by = ?
                WHERE id = ?
            ");
            $stmt->execute([$reviewerId, $eventId]);

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_mark_security_events_reviewed_by_severity')) {
    /**
     * @param list<string> $severities
     */
    function portal_mark_security_events_reviewed_by_severity(array $severities, int $reviewerId): int
    {
        if ($reviewerId <= 0 || $severities === []) {
            return 0;
        }

        $allowed = array_values(array_intersect($severities, ['info', 'low', 'medium', 'high']));
        if ($allowed === []) {
            return 0;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($allowed), '?'));
            $params = array_merge([$reviewerId], $allowed);
            $stmt = portal_db()->prepare("
                UPDATE security_events
                SET reviewed = 1, reviewed_at = datetime('now'), reviewed_by = ?
                WHERE reviewed = 0 AND severity IN ({$placeholders})
            ");
            $stmt->execute($params);

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('portal_system_needs_developer_review')) {
    function portal_system_needs_developer_review(): bool
    {
        return portal_db_is_in_webroot() || portal_db_security_warning() !== null;
    }
}

// ── Upload content-type validation ────────────────────────────────────────────

if (!function_exists('portal_upload_mime_ok')) {
    function portal_upload_mime_ok(string $tmpPath, string $ext): bool
    {
        // If we cannot inspect the file, fall back to the extension whitelist
        // plus the upload-directory .htaccess that blocks script execution.
        if ($tmpPath === '' || !is_file($tmpPath) || !class_exists('finfo')) {
            return true;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        if ($mime === '') {
            return true;
        }

        $zip = 'application/zip';
        $ole = ['application/x-ole-storage', 'application/vnd.ms-office', 'application/CDFV2'];

        $allowed = [
            'pdf'  => ['application/pdf'],
            'txt'  => ['text/plain', 'text/csv', 'application/csv'],
            'png'  => ['image/png'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'doc'  => array_merge(['application/msword'], $ole),
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', $zip],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $zip],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', $zip],
            'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', $zip],
            'potx' => ['application/vnd.openxmlformats-officedocument.presentationml.template', $zip],
            'odp'  => ['application/vnd.oasis.opendocument.presentation', $zip],
            'ppt'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'pps'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'pot'  => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'mp4'  => ['video/mp4'],
            'm4v'  => ['video/mp4', 'video/x-m4v'],
            'webm' => ['video/webm'],
            'ogv'  => ['video/ogg', 'application/ogg'],
            'ogg'  => ['video/ogg', 'application/ogg'],
            'mov'  => ['video/quicktime'],
        ];

        // Unknown extension: extension whitelist already handled this elsewhere.
        if (!isset($allowed[$ext])) {
            return true;
        }

        return in_array($mime, $allowed[$ext], true);
    }
}

// ── Protect sensitive directories from direct web access ──────────────────────

if (!function_exists('portal_protect_sensitive_paths')) {
    function portal_protect_sensitive_paths(): void
    {
        $deny = "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

        $uploadsDeny = $deny
            . "\n<IfModule mod_php.c>\n    php_admin_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php7.c>\n    php_admin_flag engine off\n</IfModule>\n"
            . "<IfModule mod_php8.c>\n    php_admin_flag engine off\n</IfModule>\n"
            . "\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n"
            . "RemoveType .php .phtml .php3 .php4 .php5 .php7 .phar\n";

        $protectedDirs = [
            __DIR__ . DIRECTORY_SEPARATOR . 'database' => $deny,
            __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'integrity_references' => $deny,
            __DIR__ . DIRECTORY_SEPARATOR . 'uploads' => $uploadsDeny,
            __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'cache' => $uploadsDeny,
            __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'submissions' => $uploadsDeny,
            __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'courses' => $uploadsDeny,
        ];

        foreach ($protectedDirs as $dir => $content) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (!is_dir($dir)) {
                continue;
            }
            $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!is_file($htaccess) || trim((string) file_get_contents($htaccess)) !== trim($content)) {
                @file_put_contents($htaccess, $content);
            }
        }

        $rootHtaccess = __DIR__ . DIRECTORY_SEPARATOR . '.htaccess';
        if (is_file($rootHtaccess)) {
            $rootContents = (string) file_get_contents($rootHtaccess);
            $rewriteBlock = <<<'HTACCESS'

# Block direct HTTP access to sensitive folders (defence in depth).
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule ^database(?:/|$) - [F,L,NC]
RewriteRule ^uploads(?:/|$) - [F,L,NC]
</IfModule>
HTACCESS;
            if (!str_contains($rootContents, 'RewriteRule ^database(?:/|$)')) {
                @file_put_contents($rootHtaccess, rtrim($rootContents) . $rewriteBlock . "\n");
            }
        }
    }
}

// ── Auto-initialise database on first run ─────────────────────────────────────
if (!file_exists(portal_db_path())) {
    require_once __DIR__ . '/db_init.php';
}

// ── Schema migrations (idempotent — safe to run on every request) ─────────────
if (!function_exists('portal_run_migrations')) {
    function portal_run_migrations(): void
    {
        $db = portal_db();

        // ── Add teacher role to users table if not already present ────────────
        $tableSQL = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn() ?: '');
        if ($tableSQL !== '' && strpos($tableSQL, "'teacher'") === false) {
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->exec("
                CREATE TABLE IF NOT EXISTS _users_new (
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
            $db->exec("INSERT INTO _users_new SELECT * FROM users");
            $db->exec("DROP TABLE users");
            $db->exec("ALTER TABLE _users_new RENAME TO users");
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // ── Add supervisor role to users table if not already present ─────────
        // Supervisors are course-level staff (see portal_is_course_staff()):
        // higher than a teacher but never full admins/owners. They only ever
        // gain access through course_teachers assignments, never site-wide.
        $tableSQL = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn() ?: '');
        if ($tableSQL !== '' && strpos($tableSQL, "'supervisor'") === false) {
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->exec("
                CREATE TABLE IF NOT EXISTS _users_new (
                    id            INTEGER PRIMARY KEY AUTOINCREMENT,
                    username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                    email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                    password_hash TEXT    NOT NULL,
                    name          TEXT    NOT NULL,
                    year          TEXT    NOT NULL DEFAULT 'Year 11',
                    programme     TEXT    NOT NULL DEFAULT 'General',
                    initials      TEXT    NOT NULL DEFAULT 'ST',
                    role          TEXT    NOT NULL DEFAULT 'student'
                                          CHECK(role IN ('owner','admin','supervisor','teacher','student')),
                    created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $db->exec("INSERT INTO _users_new SELECT * FROM users");
            $db->exec("DROP TABLE users");
            $db->exec("ALTER TABLE _users_new RENAME TO users");
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // ── Course teachers (junction: assigned course staff → courses) ────────
        // Stores teacher accounts assigned to courses. assignment_role is
        // course-level: 'teacher' or 'supervisor' (Course Supervisor).
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_teachers (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                assigned_at TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(course_id, user_id)
            )
        ");

        $ctCols = array_column($db->query("PRAGMA table_info(course_teachers)")->fetchAll(), 'name');
        if (!in_array('assignment_role', $ctCols, true)) {
            $db->exec("ALTER TABLE course_teachers ADD COLUMN assignment_role TEXT NOT NULL DEFAULT 'teacher'");
        }

        // Legacy: global role 'supervisor' → teacher + course-level supervisor assignment
        try {
            $legacySupervisorIds = $db->query(
                "SELECT id FROM users WHERE role = 'supervisor'"
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($legacySupervisorIds !== []) {
                $markSupervisor = $db->prepare(
                    "UPDATE course_teachers SET assignment_role = 'supervisor' WHERE user_id = ?"
                );
                foreach ($legacySupervisorIds as $legacyId) {
                    $markSupervisor->execute([(int) $legacyId]);
                }
                $db->exec("UPDATE users SET role = 'teacher' WHERE role = 'supervisor'");
            }
        } catch (\PDOException $e) {
            // Non-fatal during migration.
        }

        // Remove supervisor from global users.role CHECK (course-level only now)
        $usersTableSQL = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='users'"
        )->fetchColumn() ?: '');
        if ($usersTableSQL !== '' && str_contains($usersTableSQL, "'supervisor'")) {
            $db->exec('PRAGMA foreign_keys = OFF');
            $db->exec("
                CREATE TABLE IF NOT EXISTS _users_role_fix (
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
            $db->exec("INSERT INTO _users_role_fix SELECT * FROM users");
            $db->exec('DROP TABLE users');
            $db->exec('ALTER TABLE _users_role_fix RENAME TO users');
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // ── Course announcements (writable by assigned teachers and admins) ────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_announcements (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                body        TEXT    NOT NULL DEFAULT '',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Site-wide (major) announcements — admin/owner only ──────────────────
        $db->exec("
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
        $db->exec("CREATE INDEX IF NOT EXISTS idx_site_announcements_pinned ON site_announcements(pinned, created_at)");

        // ── Course folders and items ───────────────────────────────────────────
        $db->exec("
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
        $folderCols = array_column($db->query("PRAGMA table_info(course_folders)")->fetchAll(), 'name');
        if (!in_array('locked', $folderCols, true)) {
            $db->exec("ALTER TABLE course_folders ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
        }
        $db->exec("
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
                sort_order  INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Add file_path / file_name to existing course_folder_items if absent ──
        $cols = array_column($db->query("PRAGMA table_info(course_folder_items)")->fetchAll(), 'name');
        if (!in_array('file_path', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN file_path TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('file_name', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN file_name TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('submission_deadline', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_deadline TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('submission_ai_detection', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_ai_detection INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('submission_max_attempts', $cols, true)) {
            // 0 = unlimited resubmissions; otherwise the max number of submit attempts allowed.
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_max_attempts INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('allow_download', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN allow_download TINYINT(1) NOT NULL DEFAULT 0");
        }

        // ── Item-level lock flag (kept in sync with course.php's own migration) ──
        if (!in_array('locked', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN locked INTEGER NOT NULL DEFAULT 0");
        }

        // ── Allow 'video' folder items (lesson videos) ─────────────────────────
        // Rebuilds the table to widen the type CHECK constraint. All existing
        // columns are preserved dynamically so no item data (e.g. lock state,
        // download permission) is lost in the process.
        $itemsTableSql = (string) ($db->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='course_folder_items'"
        )->fetchColumn() ?: '');
        if ($itemsTableSql !== '' && strpos($itemsTableSql, "'video'") === false) {
            $db->exec('PRAGMA foreign_keys = OFF');
            $existingCols = array_column($db->query("PRAGMA table_info(course_folder_items)")->fetchAll(), 'name');
            $db->exec("
                CREATE TABLE _course_folder_items_new (
                    id          INTEGER PRIMARY KEY AUTOINCREMENT,
                    folder_id   INTEGER NOT NULL REFERENCES course_folders(id) ON DELETE CASCADE,
                    course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                    type        TEXT    NOT NULL DEFAULT 'document'
                                        CHECK(type IN ('document','link','submission','video')),
                    title       TEXT    NOT NULL,
                    description TEXT    NOT NULL DEFAULT '',
                    url         TEXT    NOT NULL DEFAULT '',
                    file_path   TEXT    NOT NULL DEFAULT '',
                    file_name   TEXT    NOT NULL DEFAULT '',
                    allow_download INTEGER NOT NULL DEFAULT 0,
                    locked      INTEGER NOT NULL DEFAULT 0,
                    submission_deadline TEXT NOT NULL DEFAULT '',
                    submission_ai_detection INTEGER NOT NULL DEFAULT 0,
                    submission_max_attempts INTEGER NOT NULL DEFAULT 0,
                    sort_order  INTEGER NOT NULL DEFAULT 0,
                    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $newCols = [
                'id', 'folder_id', 'course_id', 'type', 'title', 'description', 'url',
                'file_path', 'file_name', 'allow_download', 'locked', 'submission_deadline',
                'submission_ai_detection', 'submission_max_attempts', 'sort_order', 'created_at',
            ];
            $selectExprs = array_map(
                static fn (string $c): string => in_array($c, $existingCols, true) ? $c : "0 AS $c",
                $newCols
            );
            $db->exec("
                INSERT INTO _course_folder_items_new (" . implode(', ', $newCols) . ")
                SELECT " . implode(', ', $selectExprs) . "
                FROM course_folder_items
            ");
            $db->exec('DROP TABLE course_folder_items');
            $db->exec('ALTER TABLE _course_folder_items_new RENAME TO course_folder_items');
            $db->exec('PRAGMA foreign_keys = ON');
        }

        // ── Class schedule ────────────────────────────────────────────────────
        $db->exec("
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

        // ── Discussion forum ──────────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_discussion_topics (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                body        TEXT    NOT NULL DEFAULT '',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_discussion_replies (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                topic_id    INTEGER NOT NULL REFERENCES course_discussion_topics(id) ON DELETE CASCADE,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                body        TEXT    NOT NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // ── Lesson video Q&A ─────────────────────────────────────────────────────
        // Questions asked under a lesson video. They are private to the asker and
        // the course's teaching staff until a teacher/admin answers them, at which
        // point the Q&A pair becomes visible to every student on the course.
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_video_questions (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id     INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                question    TEXT    NOT NULL,
                answer      TEXT    NOT NULL DEFAULT '',
                answered_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
                answered_at TEXT    NOT NULL DEFAULT '',
                video_seconds INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_video_questions_item ON course_video_questions(item_id, created_at)");
        $vqCols = array_column($db->query("PRAGMA table_info(course_video_questions)")->fetchAll(), 'name');
        if (!in_array('video_seconds', $vqCols, true)) {
            $db->exec("ALTER TABLE course_video_questions ADD COLUMN video_seconds INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('pinned', $vqCols, true)) {
            $db->exec("ALTER TABLE course_video_questions ADD COLUMN pinned INTEGER NOT NULL DEFAULT 0");
        }
        if (!in_array('is_public', $vqCols, true)) {
            // 1 = visible to whole class once answered; 0 = private reply to asker only
            $db->exec("ALTER TABLE course_video_questions ADD COLUMN is_public INTEGER NOT NULL DEFAULT 1");
        }

        // Lesson notes on video items + watch progress + personal notifications
        $itemColsForNotes = array_column($db->query("PRAGMA table_info(course_folder_items)")->fetchAll(), 'name');
        if (!in_array('lesson_notes', $itemColsForNotes, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN lesson_notes TEXT NOT NULL DEFAULT ''");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS course_video_progress (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id          INTEGER NOT NULL REFERENCES course_folder_items(id) ON DELETE CASCADE,
                user_id          INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                position_seconds INTEGER NOT NULL DEFAULT 0,
                updated_at       TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(item_id, user_id)
            )
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS portal_notifications (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                course_id   INTEGER NOT NULL DEFAULT 0,
                type        TEXT    NOT NULL DEFAULT 'lesson_answer',
                title       TEXT    NOT NULL,
                body        TEXT    NOT NULL DEFAULT '',
                link        TEXT    NOT NULL DEFAULT '',
                read_at     TEXT    NOT NULL DEFAULT '',
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_portal_notifications_user ON portal_notifications(user_id, created_at)");

        // ── Groups ────────────────────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_groups (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                title       TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                max_members INTEGER NOT NULL DEFAULT 0,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_group_members (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id  INTEGER NOT NULL REFERENCES course_groups(id) ON DELETE CASCADE,
                user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                joined_at TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(group_id, user_id)
            )
        ");

        // ── Submission review annotations (Turnitin-style comments) ────────────
        $db->exec("
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
        $db->exec("CREATE INDEX IF NOT EXISTS idx_submission_annotations ON course_submission_annotations(submission_id)");

        // ── Login attempt log (brute-force throttling, per IP) ─────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at INTEGER NOT NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)");

        // ── Security activity log ──────────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS security_events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type  TEXT    NOT NULL,
                severity    TEXT    NOT NULL DEFAULT 'info'
                                CHECK(severity IN ('info', 'low', 'medium', 'high')),
                user_id     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                username    TEXT    NOT NULL DEFAULT '',
                ip_address  TEXT    NOT NULL DEFAULT '',
                user_agent  TEXT    NOT NULL DEFAULT '',
                route       TEXT    NOT NULL DEFAULT '',
                method      TEXT    NOT NULL DEFAULT '',
                details     TEXT    NOT NULL DEFAULT '',
                reviewed    INTEGER NOT NULL DEFAULT 0,
                reviewed_at TEXT    NOT NULL DEFAULT '',
                reviewed_by INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_security_events_created ON security_events(created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_security_events_reviewed ON security_events(reviewed, severity)");

        // ── Announcement read tracking ─────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS announcement_reads (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id         INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                announcement_id INTEGER NOT NULL REFERENCES course_announcements(id) ON DELETE CASCADE,
                read_at         TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, announcement_id)
            )
        ");

        // ── Course tab visibility settings ────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS course_tab_settings (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                tab_key     TEXT    NOT NULL,
                enabled     INTEGER NOT NULL DEFAULT 1,
                UNIQUE(course_id, tab_key)
            )
        ");

        // ── Student submission files ───────────────────────────────────────────
        $db->exec("
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
        $db->exec("
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
        $db->exec("
            CREATE TABLE IF NOT EXISTS integrity_eula_acceptances (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                version     TEXT NOT NULL,
                accepted_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, version)
            )
        ");
        $db->exec("
            CREATE TABLE IF NOT EXISTS integrity_sentence_index (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                sentence_hash    TEXT    NOT NULL,
                sentence_preview TEXT    NOT NULL DEFAULT '',
                source_type      TEXT    NOT NULL,
                source_id        INTEGER NOT NULL,
                source_label     TEXT    NOT NULL DEFAULT '',
                course_id        INTEGER,
                indexed_at       TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_integrity_sentence_hash ON integrity_sentence_index(sentence_hash)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_integrity_sentence_source ON integrity_sentence_index(source_type, source_id)');
        $submissionCols = array_column($db->query("PRAGMA table_info(course_submissions)")->fetchAll(), 'name');
        $submissionAdds = [
            'score'         => "ALTER TABLE course_submissions ADD COLUMN score INTEGER",
            'feedback'      => "ALTER TABLE course_submissions ADD COLUMN feedback TEXT NOT NULL DEFAULT ''",
            'marked_at'     => "ALTER TABLE course_submissions ADD COLUMN marked_at TEXT NOT NULL DEFAULT ''",
            'marked_by'     => "ALTER TABLE course_submissions ADD COLUMN marked_by INTEGER REFERENCES users(id) ON DELETE SET NULL",
            'ai_status'     => "ALTER TABLE course_submissions ADD COLUMN ai_status TEXT NOT NULL DEFAULT ''",
            'ai_score'      => "ALTER TABLE course_submissions ADD COLUMN ai_score REAL",
            'ai_report'     => "ALTER TABLE course_submissions ADD COLUMN ai_report TEXT NOT NULL DEFAULT ''",
            'ai_checked_at' => "ALTER TABLE course_submissions ADD COLUMN ai_checked_at TEXT NOT NULL DEFAULT ''",
            'receipt_number' => "ALTER TABLE course_submissions ADD COLUMN receipt_number TEXT NOT NULL DEFAULT ''",
            'file_sha256' => "ALTER TABLE course_submissions ADD COLUMN file_sha256 TEXT NOT NULL DEFAULT ''",
            'submission_text' => "ALTER TABLE course_submissions ADD COLUMN submission_text TEXT NOT NULL DEFAULT ''",
            'text_word_count' => "ALTER TABLE course_submissions ADD COLUMN text_word_count INTEGER NOT NULL DEFAULT 0",
            'similarity_status' => "ALTER TABLE course_submissions ADD COLUMN similarity_status TEXT NOT NULL DEFAULT ''",
            'similarity_score' => "ALTER TABLE course_submissions ADD COLUMN similarity_score REAL",
            'similarity_report' => "ALTER TABLE course_submissions ADD COLUMN similarity_report TEXT NOT NULL DEFAULT ''",
            'similarity_checked_at' => "ALTER TABLE course_submissions ADD COLUMN similarity_checked_at TEXT NOT NULL DEFAULT ''",
            'process_edit_seconds' => "ALTER TABLE course_submissions ADD COLUMN process_edit_seconds INTEGER NOT NULL DEFAULT 0",
            'process_paste_events' => "ALTER TABLE course_submissions ADD COLUMN process_paste_events INTEGER NOT NULL DEFAULT 0",
            'process_pasted_chars' => "ALTER TABLE course_submissions ADD COLUMN process_pasted_chars INTEGER NOT NULL DEFAULT 0",
            'eula_accepted_at' => "ALTER TABLE course_submissions ADD COLUMN eula_accepted_at TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($submissionAdds as $col => $sql) {
            if (!in_array($col, $submissionCols, true)) {
                $db->exec($sql);
            }
        }

        // ── Site-wide settings (admin) ─────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS portal_site_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT NOT NULL DEFAULT '',
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $courseCols = array_column($db->query("PRAGMA table_info(courses)")->fetchAll(), 'name');
        if (!in_array('external_ai_detection', $courseCols, true)) {
            $db->exec("ALTER TABLE courses ADD COLUMN external_ai_detection INTEGER NOT NULL DEFAULT 0");
        }
    }
}

portal_run_migrations();
portal_protect_sensitive_paths();

// ── Teacher / course-manager permission helpers ───────────────────────────────

if (!function_exists('portal_is_teacher')) {
    /** Global teacher account (not the same as course-level assignment role). */
    function portal_is_teacher(): bool
    {
        return portal_current_user_role() === 'teacher';
    }
}

if (!function_exists('portal_valid_assignment_role')) {
    function portal_valid_assignment_role(string $role): bool
    {
        return in_array($role, ['teacher', 'supervisor'], true);
    }
}

if (!function_exists('portal_course_assignment_role')) {
    /**
     * Course-level staff assignment for a user on a module.
     *
     * @return 'teacher'|'supervisor'|null
     */
    function portal_course_assignment_role(int $courseId, ?int $userId = null): ?string
    {
        if ($courseId <= 0) {
            return null;
        }

        if ($userId === null) {
            if (!portal_is_logged_in()) {
                return null;
            }
            $userId = (int) portal_current_user()['id'];
        }

        if ($userId <= 0) {
            return null;
        }

        $stmt = portal_db()->prepare(
            'SELECT assignment_role FROM course_teachers WHERE course_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$courseId, $userId]);
        $role = $stmt->fetchColumn();
        if ($role === false) {
            return null;
        }

        $role = (string) $role;

        return portal_valid_assignment_role($role) ? $role : 'teacher';
    }
}

if (!function_exists('portal_is_course_teacher')) {
    function portal_is_course_teacher(int $courseId, ?int $userId = null): bool
    {
        return portal_course_assignment_role($courseId, $userId) === 'teacher';
    }
}

if (!function_exists('portal_is_course_supervisor')) {
    function portal_is_course_supervisor(int $courseId, ?int $userId = null): bool
    {
        return portal_course_assignment_role($courseId, $userId) === 'supervisor';
    }
}

if (!function_exists('portal_course_assignment_role_label')) {
    function portal_course_assignment_role_label(string $assignmentRole): string
    {
        return $assignmentRole === 'supervisor' ? 'Course Supervisor' : 'Teacher';
    }
}

if (!function_exists('portal_is_supervisor')) {
    /**
     * @deprecated Supervisor is a course-level assignment, not a global role.
     *             Use portal_is_course_supervisor($courseId) instead.
     */
    function portal_is_supervisor(): bool
    {
        return false;
    }
}

if (!function_exists('portal_is_course_staff')) {
    /** True for global teacher accounts (may hold course-level teacher or supervisor assignments). */
    function portal_is_course_staff(): bool
    {
        return portal_is_teacher();
    }
}

if (!function_exists('portal_assigned_course_ids')) {
    function portal_assigned_course_ids(): array
    {
        if (!portal_is_logged_in()) {
            return [];
        }
        $user = portal_current_user();
        $stmt = portal_db()->prepare(
            "SELECT course_id FROM course_teachers WHERE user_id = ?"
        );
        $stmt->execute([(int) $user['id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('portal_my_announcement_course_ids')) {
    /**
     * Courses whose announcements should show up on the Communication page for
     * the current user: enrolled courses for students, plus assigned courses
     * for teachers/supervisors. Admins/owners see every module for oversight.
     *
     * @return int[]
     */
    function portal_my_announcement_course_ids(): array
    {
        if (!portal_is_logged_in()) {
            return [];
        }
        if (portal_is_admin()) {
            return array_map('intval', portal_db()->query("SELECT id FROM courses")->fetchAll(PDO::FETCH_COLUMN));
        }
        $user = portal_current_user();
        $ids = portal_enrolled_course_ids((int) $user['id']);
        if (portal_is_course_staff()) {
            $ids = array_merge($ids, portal_assigned_course_ids());
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }
}

if (!function_exists('portal_uploads_base')) {
    function portal_uploads_base(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    }
}

if (!function_exists('portal_enabled_tab_keys')) {
    function portal_enabled_tab_keys(int $courseId): ?array
    {
        $db      = portal_db();
        $cntStmt = $db->prepare("SELECT COUNT(*) FROM course_tab_settings WHERE course_id = ?");
        $cntStmt->execute([$courseId]);
        if ((int) $cntStmt->fetchColumn() === 0) {
            return null; // no settings saved → show all tabs
        }
        $stmt = $db->prepare("SELECT tab_key FROM course_tab_settings WHERE course_id = ? AND enabled = 1");
        $stmt->execute([$courseId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('portal_is_fetch_request')) {
    function portal_is_fetch_request(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';
    }
}

if (!function_exists('portal_json_response')) {
    function portal_json_response(array $payload, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('portal_can_manage_course')) {
    // owner/admin manage every course; teacher accounts manage only courses
    // they are assigned to (as Teacher or Course Supervisor on that module).
    function portal_can_manage_course(int $courseId): bool
    {
        if (portal_is_admin()) {
            return true;
        }

        return portal_course_assignment_role($courseId) !== null;
    }
}

require_once __DIR__ . '/integrity.php';
