<?php
declare(strict_types=1);

date_default_timezone_set('Europe/London');

// Load .env before any database path is resolved. portal_db_path() caches on
// first call, so PORTAL_DB_PATH must already be in the environment.
if (!function_exists('portal_load_env_file')) {
    function portal_load_env_file(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $path = __DIR__ . DIRECTORY_SEPARATOR . '.env';
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key === '' || getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
portal_load_env_file();

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

$portalComposerAutoload = __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($portalComposerAutoload)) {
    require_once $portalComposerAutoload;
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

if (!function_exists('portal_valid_external_url')) {
    function portal_valid_external_url(string $url): bool
    {
        if ($url === '' || strlen($url) > 500) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
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
     * @param list<string> $allowedClasses
     * @param list<string> $stripTags
     */
    function portal_sanitize_rich_text_element(
        DOMElement $element,
        array $allowedTags,
        array $allowedClasses,
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
                portal_sanitize_rich_text_element($child, $allowedTags, $allowedClasses, $stripTags);
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
                if ($name === 'class') {
                    $classes = preg_split('/\s+/', trim($attr->value)) ?: [];
                    $classes = array_values(array_intersect($classes, $allowedClasses));
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
            'p'          => ['class'],
            'br'         => [],
            'strong'     => [],
            'b'          => [],
            'em'         => [],
            'i'          => [],
            'u'          => [],
            's'          => [],
            'h1'         => ['class'],
            'h2'         => ['class'],
            'h3'         => ['class'],
            'ul'         => [],
            'ol'         => [],
            'li'         => ['class'],
            'blockquote' => ['class'],
            'span'       => ['class'],
            'a'          => ['href'],
        ];
        // Quill class-based formats that survive save/render.
        $allowedClasses = [
            'ql-align-center', 'ql-align-right', 'ql-align-justify',
            'ql-font-serif', 'ql-font-monospace',
            'ql-size-small', 'ql-size-large', 'ql-size-huge',
        ];
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
                portal_sanitize_rich_text_element($child, $allowedTags, $allowedClasses, $stripTags);
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
            'home'       => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-7h6v7"/>',
            'award'      => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
            'book-open'  => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
            'calendar'   => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
            'clock'      => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'megaphone'  => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
            'sparkles'   => '<path d="m12 3-1.9 5.8L4 11l6.1 2.2L12 19l1.9-5.8L20 11l-6.1-2.2L12 3z"/><path d="M5 3v4"/><path d="M3 5h4"/><path d="M19 17v4"/><path d="M17 19h4"/>',
            'bell'       => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'settings'   => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1A2 2 0 1 1 4.2 17l.1-.1A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.6-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.3 7A2 2 0 1 1 7.1 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1A2 2 0 1 1 19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.1a2 2 0 1 1 0 4H21a1.7 1.7 0 0 0-1.6 1z"/>',
            'log-out'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
            'user'       => '<path d="M19 21a7 7 0 0 0-14 0"/><circle cx="12" cy="8" r="4"/>',
            'lock'       => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'mail'       => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
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
            'search'        => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'chevron-down'  => '<polyline points="6 9 12 15 18 9"/>',
            'video'         => '<path d="m22 8.5-6 3.5 6 3.5v-7Z"/><rect x="2" y="6" width="14" height="12" rx="2"/>',
            'play'          => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'grip'          => '<circle cx="9" cy="5" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="19" r="1"/>',
            'download'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'edit'          => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'pin'           => '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
            'alert'         => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'menu'          => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
            'x'             => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'activity'      => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><path d="M8 7h8"/><path d="M8 11h6"/><path d="M8 15h4"/>',
            'check-circle'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
            'help-circle'   => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'list-ordered'  => '<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
            'layers'        => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'star'          => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'trophy'        => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22"/><path d="M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
            'flag'          => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
            'image'         => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
            'mic'           => '<path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="22"/>',
            'copy'          => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
            'eye'           => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
            'eye-off'       => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-8-10-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 8 10 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
            'save'          => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'history'       => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
            'chevron-up'    => '<polyline points="18 15 12 9 6 15"/>',
            'chevron-left'  => '<polyline points="15 18 9 12 15 6"/>',
            'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
            'more'          => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
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

if (!function_exists('portal_video_embed_providers')) {
    /**
     * Allowlist of legitimate video platforms that lesson videos may be embedded from.
     * Only these hosts are ever trusted enough to build an <iframe> src from — anything
     * else is rejected outright so teachers cannot (even accidentally) point students at
     * a lookalike or compromised domain. Keep this list short and only add platforms with
     * a stable, well-documented embed URL scheme.
     */
    function portal_video_embed_providers(): array
    {
        return [
            'youtube' => [
                'label' => 'YouTube',
                'hosts' => ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be'],
            ],
            'vimeo' => [
                'label' => 'Vimeo',
                'hosts' => ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'],
            ],
        ];
    }
}

if (!function_exists('portal_supported_video_source_hint')) {
    function portal_supported_video_source_hint(): string
    {
        return 'YouTube or Vimeo link';
    }
}

if (!function_exists('portal_parse_external_video_url')) {
    /**
     * Strictly validate a teacher-supplied video link against a small allowlist of
     * legitimate video platforms and extract a clean video ID from it.
     *
     * Security note: the returned `embed_url` is always rebuilt server-side from the
     * platform's own trusted embed domain plus a character-restricted ID — the raw
     * pasted URL (query strings, extra paths, redirects, etc.) is never forwarded to
     * the browser. This means an attacker cannot smuggle an arbitrary/malicious domain
     * into the page no matter what they paste; anything that doesn't match a known
     * platform's URL shape is rejected with `null`.
     *
     * @return array{provider:string,label:string,video_id:string,watch_url:string,embed_url:string,thumbnail_url:string}|null
     */
    function portal_parse_external_video_url(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }

        $providers = portal_video_embed_providers();

        if (in_array($host, $providers['youtube']['hosts'], true)) {
            $videoId = null;
            $path = (string) ($parts['path'] ?? '');
            $query = [];
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $query);
            }

            if ($host === 'youtu.be') {
                $videoId = trim($path, '/');
            } elseif (isset($query['v'])) {
                $videoId = (string) $query['v'];
            } elseif (preg_match('~^/(?:embed|shorts|live)/([^/?#]+)~', $path, $m)) {
                $videoId = $m[1];
            }

            if ($videoId === null || !preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId)) {
                return null;
            }

            return [
                'provider' => 'youtube',
                'label' => $providers['youtube']['label'],
                'video_id' => $videoId,
                'watch_url' => 'https://www.youtube.com/watch?v=' . $videoId,
                'embed_url' => 'https://www.youtube-nocookie.com/embed/' . $videoId,
                'thumbnail_url' => 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg',
            ];
        }

        if (in_array($host, $providers['vimeo']['hosts'], true)) {
            $path = (string) ($parts['path'] ?? '');
            $videoId = null;
            if (preg_match('#^/(?:video/)?(\d+)(?:/[a-zA-Z0-9]+)?/?$#', $path, $m)) {
                $videoId = $m[1];
            }

            if ($videoId === null || !preg_match('/^[0-9]{5,15}$/', $videoId)) {
                return null;
            }

            return [
                'provider' => 'vimeo',
                'label' => $providers['vimeo']['label'],
                'video_id' => $videoId,
                'watch_url' => 'https://vimeo.com/' . $videoId,
                'embed_url' => 'https://player.vimeo.com/video/' . $videoId,
                'thumbnail_url' => '',
            ];
        }

        return null;
    }
}

if (!function_exists('portal_db_timestamp')) {
    /**
     * Parse a SQLite datetime('now') value (UTC, no timezone suffix) into a unix timestamp.
     */
    function portal_db_timestamp(?string $raw): ?int
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $raw) === 1) {
            $ts = strtotime($raw);
            return $ts === false ? null : $ts;
        }

        // SQLite stores UTC as "YYYY-MM-DD HH:MM:SS" with no zone — treat as UTC.
        $normalized = str_replace(' ', 'T', $raw) . 'Z';
        $ts = strtotime($normalized);

        return $ts === false ? null : $ts;
    }
}

if (!function_exists('portal_relative_time')) {
    function portal_relative_time(?string $raw): string
    {
        $ts = portal_db_timestamp($raw);
        if ($ts === null) {
            return trim((string) $raw);
        }

        $diff = time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return $m . ' min ago';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 7 * 86400) {
            $d = (int) floor($diff / 86400);
            return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
        }

        return date('j M Y', $ts);
    }
}

if (!function_exists('portal_wait_label')) {
    function portal_wait_label(?string $raw): string
    {
        $ts = portal_db_timestamp($raw);
        if ($ts === null) {
            return '';
        }

        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return 'Waiting just now';
        }
        if ($diff < 3600) {
            $m = max(1, (int) floor($diff / 60));
            return 'Waiting ' . $m . ' min';
        }
        if ($diff < 86400) {
            $h = max(1, (int) floor($diff / 3600));
            return 'Waiting ' . $h . ' hour' . ($h === 1 ? '' : 's');
        }
        $d = max(1, (int) floor($diff / 86400));
        return 'Waiting ' . $d . ' day' . ($d === 1 ? '' : 's');
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
                'text' => 'No deadline',
                'state' => 'none',
                'passed' => false,
            ];
        }

        $ts = strtotime($deadlineRaw);
        if ($ts === false) {
            return [
                'has_deadline' => false,
                'text' => 'No deadline',
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
                <span class="sub-slot-deadline-label"><?= $info['has_deadline'] ? 'Due date' : 'Deadline' ?></span>
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

if (!function_exists('portal_normalize_submission_weight')) {
    function portal_normalize_submission_weight(mixed $raw, float $default = 100.0): float
    {
        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!is_numeric($raw)) {
            return $default;
        }

        return max(0.0, min(100.0, round((float) $raw, 2)));
    }
}

if (!function_exists('portal_format_submission_weight')) {
    function portal_format_submission_weight(mixed $raw, bool $withSuffix = true): string
    {
        $weight = portal_normalize_submission_weight($raw);
        $text = rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.');

        return $withSuffix ? $text . '%' : $text;
    }
}

if (!function_exists('portal_course_gradebook_weight_total')) {
    /**
     * Sum of gradebook weights already committed on a course.
     * Counts gradebook activities and submission slots (each 0–100).
     *
     * @param array{exclude_activity_id?:int,exclude_item_id?:int} $opts
     */
    function portal_course_gradebook_weight_total(int $courseId, array $opts = []): float
    {
        if ($courseId <= 0) {
            return 0.0;
        }

        $excludeActivityId = (int) ($opts['exclude_activity_id'] ?? 0);
        $excludeItemId = (int) ($opts['exclude_item_id'] ?? 0);
        $db = portal_db();

        $actSql = 'SELECT COALESCE(SUM(grade_weight), 0)
                   FROM course_activities
                   WHERE course_id = ? AND include_in_gradebook = 1';
        $actParams = [$courseId];
        if ($excludeActivityId > 0) {
            $actSql .= ' AND id <> ?';
            $actParams[] = $excludeActivityId;
        }
        $actStmt = $db->prepare($actSql);
        $actStmt->execute($actParams);
        $activityTotal = (float) $actStmt->fetchColumn();

        $itemSql = "SELECT COALESCE(SUM(submission_weight), 0)
                    FROM course_folder_items
                    WHERE course_id = ? AND type = 'submission'";
        $itemParams = [$courseId];
        if ($excludeItemId > 0) {
            $itemSql .= ' AND id <> ?';
            $itemParams[] = $excludeItemId;
        }
        $itemStmt = $db->prepare($itemSql);
        $itemStmt->execute($itemParams);
        $submissionTotal = (float) $itemStmt->fetchColumn();

        return round($activityTotal + $submissionTotal, 2);
    }
}

if (!function_exists('portal_course_gradebook_weight_fits')) {
    /**
     * Whether adding/replacing a weight keeps the course gradebook at or under 100%.
     *
     * @param array{exclude_activity_id?:int,exclude_item_id?:int} $opts
     * @return array{ok:bool,error?:string,used:float,remaining:float,proposed:float}
     */
    function portal_course_gradebook_weight_fits(int $courseId, float $proposedWeight, array $opts = []): array
    {
        $proposed = portal_normalize_submission_weight($proposedWeight, 0.0);
        $used = portal_course_gradebook_weight_total($courseId, $opts);
        $remaining = round(max(0.0, 100.0 - $used), 2);
        if ($proposed > $remaining + 0.0001) {
            return [
                'ok' => false,
                'error' => 'Gradebook weights for this course cannot add up to more than 100%. '
                    . portal_format_submission_weight($used) . ' already allocated; '
                    . portal_format_submission_weight($remaining) . ' remaining.',
                'used' => $used,
                'remaining' => $remaining,
                'proposed' => $proposed,
            ];
        }

        return [
            'ok' => true,
            'used' => $used,
            'remaining' => $remaining,
            'proposed' => $proposed,
        ];
    }
}

if (!function_exists('portal_weighted_grade_average')) {
    function portal_weighted_grade_average(array $grades): ?int
    {
        $weightedTotal = 0.0;
        $weightTotal = 0.0;

        foreach ($grades as $grade) {
            if (($grade['score'] ?? null) === null || trim((string) ($grade['marked_at'] ?? '')) === '') {
                continue;
            }

            $weight = portal_normalize_submission_weight($grade['submission_weight'] ?? 100);
            if ($weight <= 0.0) {
                continue;
            }

            $weightedTotal += (float) $grade['score'] * $weight;
            $weightTotal += $weight;
        }

        if ($weightTotal <= 0.0) {
            return null;
        }

        return (int) round($weightedTotal / $weightTotal);
    }
}

if (!function_exists('portal_submission_is_marked')) {
    function portal_submission_is_marked(array $row): bool
    {
        $score = $row['score'] ?? null;
        return $score !== null && $score !== '' && trim((string) ($row['marked_at'] ?? '')) !== '';
    }
}

if (!function_exists('portal_submission_grades_released')) {
    function portal_submission_grades_released(array $row): bool
    {
        return trim((string) ($row['grades_released_at'] ?? '')) !== '';
    }
}

if (!function_exists('portal_notify_file_grade_released')) {
    function portal_notify_file_grade_released(
        int $studentId,
        int $courseId,
        string $courseSlug,
        string $slotTitle,
        int $score,
        int $submissionId
    ): void {
        if ($studentId <= 0 || !function_exists('portal_notify_grade_returned')) {
            return;
        }
        $title = trim($slotTitle) !== '' ? $slotTitle : 'Assignment';
        $gradeLink = 'course.php?course=' . rawurlencode($courseSlug) . '&section=gradebook';
        portal_notify_grade_returned(
            $studentId,
            $courseId,
            'Grade returned: ' . $title,
            'You scored ' . $score . '% on ' . $title . '.',
            $gradeLink,
            'submission:' . $submissionId . ':' . $score
        );
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
            portal_redirect('dashboard.php');
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
            return 'This session';
        }

        // Legacy formatted strings from older logins.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $value;
        }

        $ts = portal_db_timestamp($value) ?? strtotime($value);
        if ($ts === false || $ts === null) {
            return $value;
        }

        return date('l j F Y \a\t H:i', $ts);
    }
}

if (!function_exists('portal_password_validate')) {
    /**
     * @return string Empty string when valid, otherwise an error message.
     */
    function portal_password_validate(string $password): string
    {
        if (strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return 'Password must include at least one letter and one number.';
        }

        return '';
    }
}

if (!function_exists('portal_year_group_options')) {
    /** @return list<string> */
    function portal_year_group_options(): array
    {
        return [
            'Year 7', 'Year 8', 'Year 9', 'Year 10', 'Year 11', 'Year 12', 'Year 13',
            'Foundation', 'Other',
        ];
    }
}

if (!function_exists('portal_user_preferences')) {
    /**
     * @return array{notify_grades:int,notify_qa:int,notify_announcements:int,notify_events:int,notify_deadlines:int}
     */
    function portal_user_preferences(int $userId): array
    {
        $defaults = [
            'notify_grades'        => 1,
            'notify_qa'            => 1,
            'notify_announcements' => 1,
            'notify_events'        => 1,
            'notify_deadlines'     => 1,
        ];
        if ($userId <= 0) {
            return $defaults;
        }

        try {
            $stmt = portal_db()->prepare(
                "SELECT notify_grades, notify_qa, notify_announcements, notify_events, notify_deadlines
                 FROM user_preferences WHERE user_id = ? LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                return $defaults;
            }

            return [
                'notify_grades'        => (int) $row['notify_grades'] === 1 ? 1 : 0,
                'notify_qa'            => (int) $row['notify_qa'] === 1 ? 1 : 0,
                'notify_announcements' => (int) $row['notify_announcements'] === 1 ? 1 : 0,
                'notify_events'        => (int) ($row['notify_events'] ?? 1) === 1 ? 1 : 0,
                'notify_deadlines'     => (int) ($row['notify_deadlines'] ?? 1) === 1 ? 1 : 0,
            ];
        } catch (\Throwable $e) {
            // Older DBs may lack newer preference columns until migration runs.
            try {
                $stmt = portal_db()->prepare(
                    "SELECT notify_grades, notify_qa, notify_announcements
                     FROM user_preferences WHERE user_id = ? LIMIT 1"
                );
                $stmt->execute([$userId]);
                $row = $stmt->fetch();
                if (!$row) {
                    return $defaults;
                }

                return [
                    'notify_grades'        => (int) $row['notify_grades'] === 1 ? 1 : 0,
                    'notify_qa'            => (int) $row['notify_qa'] === 1 ? 1 : 0,
                    'notify_announcements' => (int) $row['notify_announcements'] === 1 ? 1 : 0,
                    'notify_events'        => 1,
                    'notify_deadlines'     => 1,
                ];
            } catch (\Throwable $e2) {
                return $defaults;
            }
        }
    }
}

if (!function_exists('portal_save_user_preferences')) {
    function portal_save_user_preferences(int $userId, array $prefs): void
    {
        if ($userId <= 0) {
            return;
        }

        $current = portal_user_preferences($userId);
        $flag = static function (array $prefs, array $current, string $key): int {
            if (!array_key_exists($key, $prefs)) {
                return !empty($current[$key]) ? 1 : 0;
            }

            return !empty($prefs[$key]) ? 1 : 0;
        };

        portal_db()->prepare(
            "INSERT INTO user_preferences (user_id, notify_grades, notify_qa, notify_announcements, notify_events, notify_deadlines, updated_at)
             VALUES (?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(user_id) DO UPDATE SET
                notify_grades = excluded.notify_grades,
                notify_qa = excluded.notify_qa,
                notify_announcements = excluded.notify_announcements,
                notify_events = excluded.notify_events,
                notify_deadlines = excluded.notify_deadlines,
                updated_at = datetime('now')"
        )->execute([
            $userId,
            $flag($prefs, $current, 'notify_grades'),
            $flag($prefs, $current, 'notify_qa'),
            $flag($prefs, $current, 'notify_announcements'),
            $flag($prefs, $current, 'notify_events'),
            $flag($prefs, $current, 'notify_deadlines'),
        ]);
    }
}

if (!function_exists('portal_notify_user')) {
    function portal_notify_user(
        int $userId,
        string $type,
        string $title,
        string $body = '',
        string $link = '',
        int $courseId = 0
    ): bool {
        if ($userId <= 0 || trim($title) === '') {
            return false;
        }

        $prefs = portal_user_preferences($userId);
        $prefKey = match ($type) {
            'lesson_answer', 'qa', 'discussion_reply', 'discussion' => 'notify_qa',
            'grade', 'grades' => 'notify_grades',
            'announcement', 'announcements' => 'notify_announcements',
            'event' => 'notify_events',
            'deadline', 'deadlines' => 'notify_deadlines',
            default => null,
        };
        if ($prefKey !== null && empty($prefs[$prefKey])) {
            return false;
        }

        try {
            portal_db()->prepare(
                "INSERT INTO portal_notifications (user_id, course_id, type, title, body, link)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $userId,
                $courseId,
                $type,
                substr($title, 0, 200),
                substr($body, 0, 500),
                substr($link, 0, 400),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_notifications_unsend')) {
    /**
     * Remove inbox notifications that point at a resource that was deleted.
     *
     * @param string      $link   Exact link value stored on the notification
     * @param bool        $prefix Also match links that start with $link (e.g. topic + &reply=)
     * @param string|null $type   Optional type filter (event, announcement, …)
     */
    function portal_notifications_unsend(string $link, bool $prefix = false, ?string $type = null): int
    {
        $link = trim($link);
        if ($link === '') {
            return 0;
        }

        $params = [];
        if ($prefix) {
            $sql = 'DELETE FROM portal_notifications WHERE (link = ? OR link LIKE ? ESCAPE \'\\\')';
            $params[] = $link;
            $params[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $link) . '%';
        } else {
            $sql = 'DELETE FROM portal_notifications WHERE link = ?';
            $params[] = $link;
        }

        if ($type !== null && $type !== '') {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        try {
            $stmt = portal_db()->prepare($sql);
            $stmt->execute($params);

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
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
        $default = 'dashboard.php';
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

        $sessionUser = portal_current_user();
        $uid = (int) ($sessionUser['id'] ?? 0);
        if ($uid > 0) {
            $fresh = portal_find_user_by_id($uid);
            if ($fresh === null || portal_user_is_banned($fresh)) {
                portal_logout();
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    session_start();
                }
                $_SESSION['login_flash'] = ['error', 'This account has been banned. Contact an administrator if you think this is a mistake.'];
                portal_redirect('login.php');
            }
            $_SESSION['portal_user']['account_status'] = portal_user_account_status($fresh);
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

        if (portal_user_is_banned($user)) {
            portal_log_security_event(
                'failed_login',
                'medium',
                'Banned account sign-in blocked: ' . substr((string) $user['username'], 0, 80),
                (int) $user['id']
            );
            return false;
        }

        session_regenerate_id(true);

        $_SESSION['portal_user'] = [
            'id'        => (int) $user['id'],
            'username'  => $user['username'],
            'email'     => $user['email'],
            'name'      => $user['name'],
            'year'      => $user['year'],
            'initials'  => $user['initials'],
            'role'      => $user['role'],
            'account_status' => portal_user_account_status($user),
        ];
        $_SESSION['portal_login_at'] = gmdate('Y-m-d H:i:s');

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
    /**
     * Returns REMOTE_ADDR by default. Only trusts X-Forwarded-For when the
     * immediate peer IP is listed in the trusted_proxies site setting.
     */
    function portal_client_ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote === '') {
            return 'unknown';
        }

        $trustedRaw = '';
        if (function_exists('portal_site_setting_get')) {
            $trustedRaw = trim((string) portal_site_setting_get('trusted_proxies', ''));
        }
        if ($trustedRaw === '') {
            return $remote;
        }

        $trusted = array_values(array_filter(array_map('trim', explode(',', $trustedRaw))));
        if ($trusted === [] || !portal_ip_matches_trusted_list($remote, $trusted)) {
            return $remote;
        }

        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($forwarded === '') {
            $forwarded = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        }
        if ($forwarded === '') {
            return $remote;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $forwarded))));
        $candidate = $parts[0] ?? '';
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }

        return $remote;
    }
}

if (!function_exists('portal_ip_matches_trusted_list')) {
    /**
     * @param list<string> $trusted
     */
    function portal_ip_matches_trusted_list(string $ip, array $trusted): bool
    {
        foreach ($trusted as $entry) {
            if ($entry === '') {
                continue;
            }
            if (str_contains($entry, '/')) {
                if (portal_ip_in_cidr($ip, $entry)) {
                    return true;
                }
                continue;
            }
            if (strcasecmp($ip, $entry) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('portal_ip_in_cidr')) {
    function portal_ip_in_cidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($subnet === null || $mask === null) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || !filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = $mask === 0 ? 0 : (-1 << (32 - $mask));

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
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
        ?int $userId = null,
        ?string $usernameOverride = null
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

            if ($usernameOverride !== null) {
                $username = substr(trim($usernameOverride), 0, 80);
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
            'user_updated'               => 'User updated',
            'course_archived'            => 'Course archived',
            'course_restored'            => 'Course restored',
            'grade_changed'              => 'Grade changed',
            'profile_updated'            => 'Profile updated',
            'password_changed'           => 'Password changed',
            'account_status_changed'     => 'Account status changed',
            'invite_created'             => 'Student invite created',
            'invite_revoked'             => 'Student invite revoked',
            'invite_accepted'            => 'Student invite accepted',
            'invite_accept_failed'       => 'Student invite accept failed',
            'invite_email_sent'          => 'Student invite email sent',
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
                'admin_actions'      => $countSince("event_type IN ('role_changed', 'user_deleted', 'user_updated', 'course_archived', 'course_restored', 'account_status_changed')"),
                'csrf_failures'      => $countSince("event_type = 'csrf_failed'"),
                'grade_changes'      => $countSince("event_type = 'grade_changed'"),
                'profile_changes'    => $countSince("event_type IN ('profile_updated', 'password_changed')"),
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
                'grade_changes'   => 0,
                'profile_changes' => 0,
            ];
        }
    }
}

if (!function_exists('portal_security_events_filtered')) {
    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    function portal_security_events_filtered(
        string $period = '24h',
        string $reviewed = 'all',
        string $severity = 'all',
        string $eventType = 'all',
        string $ip = '',
        int $limit = 100,
        array $ids = []
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

            $ip = trim($ip);
            if ($ip !== '') {
                $where[] = 'ip_address = ?';
                $params[] = substr($ip, 0, 64);
            }

            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
            if ($ids !== []) {
                $ids = array_slice($ids, 0, 500);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "id IN ({$placeholders})";
                foreach ($ids as $id) {
                    $params[] = $id;
                }
            }

            $limit = max(1, min($limit, 500));
            $sql = 'SELECT * FROM security_events WHERE ' . implode(' AND ', $where)
                . ' ORDER BY created_at DESC LIMIT ' . $limit;

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

if (!function_exists('portal_mark_security_events_reviewed_bulk')) {
    /**
     * @param list<int|string> $ids
     */
    function portal_mark_security_events_reviewed_bulk(array $ids, int $reviewerId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || $reviewerId <= 0) {
            return 0;
        }

        $ids = array_slice($ids, 0, 500);

        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = portal_db()->prepare("
                UPDATE security_events
                SET reviewed = 1, reviewed_at = datetime('now'), reviewed_by = ?
                WHERE id IN ({$placeholders}) AND reviewed = 0
            ");
            $stmt->execute(array_merge([$reviewerId], $ids));

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('portal_security_ip_summary')) {
    /**
     * @return list<array<string, mixed>>
     */
    function portal_security_ip_summary(string $period = '24h', int $limit = 15): array
    {
        try {
            $since = portal_security_period_sql($period);
            $limit = max(1, min($limit, 50));
            $stmt = portal_db()->prepare("
                SELECT
                    ip_address,
                    COUNT(*) AS event_count,
                    COUNT(DISTINCT event_type) AS distinct_event_types,
                    COUNT(DISTINCT NULLIF(username, '')) AS distinct_usernames,
                    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS high_count,
                    SUM(CASE WHEN severity = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                    SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) AS unreviewed_count,
                    MAX(created_at) AS last_seen
                FROM security_events
                WHERE created_at >= {$since}
                  AND ip_address != ''
                  AND ip_address != 'unknown'
                GROUP BY ip_address
                ORDER BY event_count DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('portal_security_parse_event_time')) {
    function portal_security_parse_event_time(string $createdAt): int
    {
        $ts = strtotime($createdAt . ' UTC');
        if ($ts === false) {
            $ts = strtotime($createdAt);
        }

        return $ts === false ? 0 : $ts;
    }
}

if (!function_exists('portal_security_detect_incidents')) {
    /**
     * First-party heuristic incident detection over existing security_events rows.
     *
     * @return list<array{
     *   key: string,
     *   label: string,
     *   summary: string,
     *   severity: string,
     *   ip: string,
     *   username: string,
     *   event_ids: list<int>,
     *   window: string
     * }>
     */
    function portal_security_detect_incidents(string $period = '24h'): array
    {
        try {
            $since = portal_security_period_sql($period);
            $stmt = portal_db()->query("
                SELECT id, event_type, severity, username, ip_address, created_at
                FROM security_events
                WHERE created_at >= {$since}
                ORDER BY created_at ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            return [];
        }

        $incidents = [];
        $seenKeys = [];

        $failedByIp = [];
        $failedByUser = [];
        $throttleByIp = [];
        $probeByIp = [];
        $probeTypes = [
            'csrf_failed',
            'unauthorised_admin_access',
            'unauthorised_course_access',
            'forbidden_download',
            'blocked_upload',
        ];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $type = (string) ($row['event_type'] ?? '');
            $ip = trim((string) ($row['ip_address'] ?? ''));
            $username = trim((string) ($row['username'] ?? ''));
            $ts = portal_security_parse_event_time((string) ($row['created_at'] ?? ''));
            if ($id <= 0 || $ts <= 0) {
                continue;
            }

            if ($type === 'failed_login' && $ip !== '' && $ip !== 'unknown') {
                $failedByIp[$ip][] = ['id' => $id, 'username' => $username, 'ts' => $ts];
            }
            if ($type === 'failed_login' && $username !== '') {
                $failedByUser[$username][] = ['id' => $id, 'ip' => $ip, 'ts' => $ts];
            }
            if ($type === 'login_throttled' && $ip !== '' && $ip !== 'unknown') {
                $throttleByIp[$ip][] = ['id' => $id, 'ts' => $ts];
            }
            if (in_array($type, $probeTypes, true) && $ip !== '' && $ip !== 'unknown') {
                $probeByIp[$ip][] = ['id' => $id, 'type' => $type, 'ts' => $ts];
            }
        }

        // Credential stuffing: one IP, ≥5 failed_login, ≥3 usernames, 60-minute window.
        foreach ($failedByIp as $ip => $events) {
            $n = count($events);
            for ($i = 0; $i < $n; $i++) {
                $windowIds = [];
                $usernames = [];
                for ($j = $i; $j < $n; $j++) {
                    if ($events[$j]['ts'] - $events[$i]['ts'] > 3600) {
                        break;
                    }
                    $windowIds[] = (int) $events[$j]['id'];
                    $u = (string) $events[$j]['username'];
                    if ($u !== '') {
                        $usernames[$u] = true;
                    }
                }
                if (count($windowIds) >= 5 && count($usernames) >= 3) {
                    $key = 'stuffing:' . $ip . ':' . $windowIds[0];
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $incidents[] = [
                            'key' => $key,
                            'label' => 'Possible credential stuffing',
                            'summary' => 'IP ' . $ip . ' failed ' . count($windowIds)
                                . ' logins across ' . count($usernames) . ' accounts within 60 minutes.',
                            'severity' => 'high',
                            'ip' => $ip,
                            'username' => '',
                            'event_ids' => $windowIds,
                            'window' => '60 minutes',
                        ];
                    }
                    break;
                }
            }
        }

        // Account targeting: one username, failed logins from ≥3 IPs within 60 minutes.
        foreach ($failedByUser as $username => $events) {
            $n = count($events);
            for ($i = 0; $i < $n; $i++) {
                $windowIds = [];
                $ips = [];
                for ($j = $i; $j < $n; $j++) {
                    if ($events[$j]['ts'] - $events[$i]['ts'] > 3600) {
                        break;
                    }
                    $windowIds[] = (int) $events[$j]['id'];
                    $ip = (string) $events[$j]['ip'];
                    if ($ip !== '' && $ip !== 'unknown') {
                        $ips[$ip] = true;
                    }
                }
                if (count($ips) >= 3) {
                    $key = 'targeting:' . strtolower($username) . ':' . $windowIds[0];
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $incidents[] = [
                            'key' => $key,
                            'label' => 'Possible account targeting',
                            'summary' => 'Account "' . $username . '" saw failed logins from '
                                . count($ips) . ' different IPs within 60 minutes.',
                            'severity' => 'high',
                            'ip' => '',
                            'username' => $username,
                            'event_ids' => $windowIds,
                            'window' => '60 minutes',
                        ];
                    }
                    break;
                }
            }
        }

        // Repeated lockouts: same IP triggers login_throttled more than once in 24h.
        foreach ($throttleByIp as $ip => $events) {
            if (count($events) < 2) {
                continue;
            }
            $ids = array_map(static fn(array $e): int => (int) $e['id'], $events);
            $key = 'lockouts:' . $ip;
            if (isset($seenKeys[$key])) {
                continue;
            }
            $seenKeys[$key] = true;
            $incidents[] = [
                'key' => $key,
                'label' => 'Repeated lockouts',
                'summary' => 'IP ' . $ip . ' was throttled ' . count($events)
                    . ' times in the selected period.',
                'severity' => 'medium',
                'ip' => $ip,
                'username' => '',
                'event_ids' => $ids,
                'window' => '24 hours',
            ];
        }

        // Multi-vector probing: ≥3 distinct blocked/denied types within 30 minutes.
        foreach ($probeByIp as $ip => $events) {
            $n = count($events);
            for ($i = 0; $i < $n; $i++) {
                $windowIds = [];
                $types = [];
                for ($j = $i; $j < $n; $j++) {
                    if ($events[$j]['ts'] - $events[$i]['ts'] > 1800) {
                        break;
                    }
                    $windowIds[] = (int) $events[$j]['id'];
                    $types[(string) $events[$j]['type']] = true;
                }
                if (count($types) >= 3) {
                    $key = 'probe:' . $ip . ':' . $windowIds[0];
                    if (!isset($seenKeys[$key])) {
                        $seenKeys[$key] = true;
                        $incidents[] = [
                            'key' => $key,
                            'label' => 'Multi-vector probing',
                            'summary' => 'IP ' . $ip . ' hit ' . count($types)
                                . ' different blocked/denied event types within 30 minutes.',
                            'severity' => 'high',
                            'ip' => $ip,
                            'username' => '',
                            'event_ids' => $windowIds,
                            'window' => '30 minutes',
                        ];
                    }
                    break;
                }
            }
        }

        usort(
            $incidents,
            static function (array $a, array $b): int {
                $rank = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
                return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
            }
        );

        return $incidents;
    }
}

if (!function_exists('portal_account_statuses')) {
    /**
     * @return list<string>
     */
    function portal_account_statuses(): array
    {
        return ['active', 'muted', 'restricted', 'banned'];
    }
}

if (!function_exists('portal_account_status_label')) {
    function portal_account_status_label(string $status): string
    {
        return match ($status) {
            'muted' => 'Muted',
            'restricted' => 'Restricted',
            'banned' => 'Banned',
            default => 'Active',
        };
    }
}

if (!function_exists('portal_user_account_status')) {
    function portal_user_account_status(?array $user): string
    {
        if ($user === null) {
            return 'active';
        }
        $status = strtolower(trim((string) ($user['account_status'] ?? 'active')));
        return in_array($status, portal_account_statuses(), true) ? $status : 'active';
    }
}

if (!function_exists('portal_user_is_banned')) {
    function portal_user_is_banned(?array $user): bool
    {
        return portal_user_account_status($user) === 'banned';
    }
}

if (!function_exists('portal_user_is_muted')) {
    function portal_user_is_muted(?array $user): bool
    {
        return in_array(portal_user_account_status($user), ['muted', 'banned'], true);
    }
}

if (!function_exists('portal_user_is_restricted')) {
    function portal_user_is_restricted(?array $user): bool
    {
        return in_array(portal_user_account_status($user), ['restricted', 'banned'], true);
    }
}

if (!function_exists('portal_set_user_account_status')) {
    function portal_set_user_account_status(int $userId, string $status, int $actorId, string $reason = ''): array
    {
        if ($userId <= 0 || !in_array($status, portal_account_statuses(), true)) {
            return ['ok' => false, 'error' => 'Invalid account status.'];
        }
        $target = portal_find_user_by_id($userId);
        if ($target === null) {
            return ['ok' => false, 'error' => 'User not found.'];
        }
        if ((string) ($target['role'] ?? '') === 'owner') {
            return ['ok' => false, 'error' => 'Owner accounts cannot be restricted this way.'];
        }
        if ($userId === $actorId && $status !== 'active') {
            return ['ok' => false, 'error' => 'You cannot restrict your own account.'];
        }

        // Mirror security panel can_act / delete rules: only the owner may
        // mute, restrict, ban, or reactivate peer admin accounts. Without this,
        // any admin can POST security_account_action and lock out another admin.
        $actor = portal_find_user_by_id($actorId);
        $actorRole = (string) ($actor['role'] ?? '');
        if ((string) ($target['role'] ?? '') === 'admin' && $actorRole !== 'owner') {
            return ['ok' => false, 'error' => 'Only the owner can change admin account status.'];
        }

        try {
            portal_db()->prepare('UPDATE users SET account_status = ? WHERE id = ?')
                ->execute([$status, $userId]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not update account status.'];
        }

        $detail = 'Set ' . substr((string) $target['username'], 0, 60) . ' to '
            . portal_account_status_label($status);
        if (trim($reason) !== '') {
            $detail .= ' — ' . substr(trim($reason), 0, 160);
        }
        portal_log_security_event('account_status_changed', 'medium', $detail, $actorId);

        return ['ok' => true, 'status' => $status];
    }
}

if (!function_exists('portal_security_user_profile')) {
    /**
     * Snapshot used by the Security Activity in-page profile panel.
     *
     * @return array<string, mixed>|null
     */
    function portal_security_user_profile(int $userId, string $period = '24h', int $recentLimit = 8): ?array
    {
        $user = portal_find_user_by_id($userId);
        if ($user === null) {
            return null;
        }

        $recentLimit = max(1, min($recentLimit, 20));
        $status = portal_user_account_status($user);
        $courseIds = function_exists('portal_enrolled_course_ids')
            ? portal_enrolled_course_ids($userId)
            : [];
        $enrollmentCount = count($courseIds);

        $recent = [];
        $eventCounts = ['total' => 0, 'unreviewed' => 0, 'high' => 0];
        try {
            $since = portal_security_period_sql($period);
            $countStmt = portal_db()->prepare("
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN reviewed = 0 THEN 1 ELSE 0 END) AS unreviewed,
                    SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) AS high_count
                FROM security_events
                WHERE user_id = ? AND created_at >= {$since}
            ");
            $countStmt->execute([$userId]);
            $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $eventCounts = [
                'total' => (int) ($counts['total'] ?? 0),
                'unreviewed' => (int) ($counts['unreviewed'] ?? 0),
                'high' => (int) ($counts['high_count'] ?? 0),
            ];

            $recentStmt = portal_db()->prepare("
                SELECT id, event_type, severity, details, ip_address, reviewed, created_at
                FROM security_events
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $recentStmt->execute([$userId, $recentLimit]);
            $recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Keep the profile usable even if event queries fail.
        }

        return [
            'id' => (int) $user['id'],
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'role' => (string) ($user['role'] ?? 'student'),
            'year' => (string) ($user['year'] ?? ''),
            'programme' => (string) ($user['programme'] ?? ''),
            'initials' => (string) ($user['initials'] ?? ''),
            'account_status' => $status,
            'account_status_label' => portal_account_status_label($status),
            'enrollment_count' => $enrollmentCount,
            'event_counts' => $eventCounts,
            'recent_events' => $recent,
        ];
    }
}

if (!function_exists('portal_system_needs_developer_review')) {
    function portal_system_needs_developer_review(): bool
    {
        return portal_db_is_in_webroot() || portal_db_security_warning() !== null;
    }
}

// ── Upload content-type validation ────────────────────────────────────────────

// portal_upload_mime_ok() is defined in submission_security.php (fail-closed).

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
        // Global supervisors were retired; do not re-add the CHECK value on every request.
        $shouldReaddSupervisorRole = false;
        if ($shouldReaddSupervisorRole && $tableSQL !== '' && strpos($tableSQL, "'supervisor'") === false) {
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

        // Teachers were sometimes given course access via enrollments (student table).
        // Courses page / manage rights use course_teachers — migrate those rows.
        try {
            $db->exec("
                INSERT OR IGNORE INTO course_teachers (course_id, user_id, assignment_role)
                SELECT e.course_id, e.user_id, 'teacher'
                FROM enrollments e
                INNER JOIN users u ON u.id = e.user_id
                WHERE u.role = 'teacher'
            ");
            $db->exec("
                DELETE FROM enrollments
                WHERE user_id IN (SELECT id FROM users WHERE role = 'teacher')
            ");
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
        if (!in_array('submission_weight', $cols, true)) {
            $db->exec("ALTER TABLE course_folder_items ADD COLUMN submission_weight REAL NOT NULL DEFAULT 100");
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
                    submission_weight REAL NOT NULL DEFAULT 100,
                    sort_order  INTEGER NOT NULL DEFAULT 0,
                    created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
                )
            ");
            $newCols = [
                'id', 'folder_id', 'course_id', 'type', 'title', 'description', 'url',
                'file_path', 'file_name', 'allow_download', 'locked', 'submission_deadline',
                'submission_ai_detection', 'submission_max_attempts', 'submission_weight', 'sort_order', 'created_at',
            ];
            $selectExprs = array_map(
                static function (string $c) use ($existingCols): string {
                    if (in_array($c, $existingCols, true)) {
                        return $c;
                    }

                    return $c === 'submission_weight' ? '100 AS submission_weight' : "0 AS $c";
                },
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

        // ── School / course events (one-off dated activities) ─────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                course_id   INTEGER NULL REFERENCES courses(id) ON DELETE CASCADE,
                created_by  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT NOT NULL,
                summary     TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                starts_at   TEXT NOT NULL,
                ends_at     TEXT NOT NULL DEFAULT '',
                location    TEXT NOT NULL DEFAULT '',
                online_url  TEXT NOT NULL DEFAULT '',
                important   INTEGER NOT NULL DEFAULT 0,
                status      TEXT NOT NULL DEFAULT 'scheduled'
                            CHECK(status IN ('scheduled', 'cancelled')),
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_events_starts_at ON events(starts_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_events_course_id ON events(course_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_events_status ON events(status)");

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

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_preferences (
                user_id              INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                notify_grades        INTEGER NOT NULL DEFAULT 1,
                notify_qa            INTEGER NOT NULL DEFAULT 1,
                notify_announcements INTEGER NOT NULL DEFAULT 1,
                notify_events        INTEGER NOT NULL DEFAULT 1,
                updated_at           TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $prefCols = array_column($db->query("PRAGMA table_info(user_preferences)")->fetchAll(), 'name');
        if (!in_array('notify_events', $prefCols, true)) {
            $db->exec("ALTER TABLE user_preferences ADD COLUMN notify_events INTEGER NOT NULL DEFAULT 1");
        }
        if (!in_array('notify_deadlines', $prefCols, true)) {
            $db->exec("ALTER TABLE user_preferences ADD COLUMN notify_deadlines INTEGER NOT NULL DEFAULT 1");
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS portal_mail_sent (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                kind     TEXT    NOT NULL,
                ref_key  TEXT    NOT NULL,
                sent_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                UNIQUE(user_id, kind, ref_key)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_portal_mail_sent_kind ON portal_mail_sent(kind, sent_at)");

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

        // ── Password reset tokens (self-service via email) ─────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token_hash    TEXT    NOT NULL UNIQUE,
                expires_at    INTEGER NOT NULL,
                used_at       INTEGER NULL,
                requested_ip  TEXT    NOT NULL DEFAULT '',
                created_at    INTEGER NOT NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_hash ON password_reset_tokens(token_hash)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_tokens_user ON password_reset_tokens(user_id, created_at)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS password_reset_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at INTEGER NOT NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_password_reset_attempts_ip ON password_reset_attempts(ip, attempted_at)");

        // ── Student invites (admin/owner email-bound signup links) ─────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS student_invites (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                email             TEXT    NOT NULL COLLATE NOCASE,
                token_hash        TEXT    NOT NULL UNIQUE,
                course_id         INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                locked_name       TEXT    NOT NULL DEFAULT '',
                locked_year       TEXT    NOT NULL DEFAULT '',
                invited_by        INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                expires_at        INTEGER NOT NULL,
                used_at           INTEGER NULL,
                revoked_at        INTEGER NULL,
                created_at        INTEGER NOT NULL,
                created_ip        TEXT    NOT NULL DEFAULT '',
                accepted_ip       TEXT    NOT NULL DEFAULT '',
                accepted_user_id  INTEGER NULL REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_student_invites_email ON student_invites(email, created_at)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_student_invites_pending ON student_invites(expires_at, used_at, revoked_at)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS student_invite_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at INTEGER NOT NULL
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_student_invite_attempts_ip ON student_invite_attempts(ip, attempted_at)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS student_invite_courses (
                invite_id INTEGER NOT NULL REFERENCES student_invites(id) ON DELETE CASCADE,
                course_id INTEGER NOT NULL REFERENCES courses(id) ON DELETE CASCADE,
                PRIMARY KEY (invite_id, course_id)
            )
        ");
        // Backfill junction rows for invites created before multi-course support.
        try {
            $db->exec(
                "INSERT OR IGNORE INTO student_invite_courses (invite_id, course_id)
                 SELECT id, course_id FROM student_invites WHERE course_id > 0"
            );
        } catch (\Throwable $e) {
            // ignore
        }

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
        $db->exec("CREATE INDEX IF NOT EXISTS idx_security_events_ip ON security_events(ip_address, created_at)");

        try {
            $userCols = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            if (!in_array('account_status', $userCols, true)) {
                $db->exec("ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'");
            }
        } catch (\Throwable $e) {
            // Ignore if users table is unavailable during early bootstrap.
        }

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
            'grade_seen_at' => "ALTER TABLE course_submissions ADD COLUMN grade_seen_at TEXT NOT NULL DEFAULT ''",
            'declared_file_type' => "ALTER TABLE course_submissions ADD COLUMN declared_file_type TEXT",
            'grades_released_at' => "ALTER TABLE course_submissions ADD COLUMN grades_released_at TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($submissionAdds as $col => $sql) {
            if (!in_array($col, $submissionCols, true)) {
                $db->exec($sql);
                if ($col === 'grades_released_at') {
                    // One-time: marks that already existed stay visible to students.
                    $db->exec(
                        "UPDATE course_submissions
                         SET grades_released_at = marked_at
                         WHERE (grades_released_at IS NULL OR trim(grades_released_at) = '')
                           AND marked_at IS NOT NULL AND trim(marked_at) != ''
                           AND score IS NOT NULL"
                    );
                }
            }
        }

        // Unique receipts for recovery lookup. Backfill blank legacy receipts first.
        try {
            $db->exec("UPDATE course_submissions SET receipt_number = 'LEGACY-' || id || '-' || lower(hex(randomblob(8))) WHERE receipt_number IS NULL OR trim(receipt_number) = ''");
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_submissions_receipt_unique ON course_submissions(receipt_number)');
        } catch (\Throwable $e) {
            // leave without unique index if still conflicted
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS receipt_lookup_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                ip TEXT NOT NULL DEFAULT '',
                attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_receipt_lookup_attempts ON receipt_lookup_attempts(user_id, ip, attempted_at)');

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
        if (!in_array('opens_at', $courseCols, true)) {
            $db->exec("ALTER TABLE courses ADD COLUMN opens_at TEXT NOT NULL DEFAULT ''");
        }
        if (!in_array('archives_at', $courseCols, true)) {
            $db->exec("ALTER TABLE courses ADD COLUMN archives_at TEXT NOT NULL DEFAULT ''");
        }
    }
}

portal_run_migrations();
require_once __DIR__ . '/activity.php';
if (function_exists('portal_activity_run_migrations')) {
    portal_activity_run_migrations();
}
portal_protect_sensitive_paths();

// ── Teacher / course-manager permission helpers ───────────────────────────────

if (!function_exists('portal_is_teacher')) {
    /**
     * Teaching staff by global role: owner, admin, and teacher.
     * Not the same as a course-level assignment role (see portal_is_course_teacher).
     */
    function portal_is_teacher(): bool
    {
        return in_array(portal_current_user_role(), ['owner', 'admin', 'teacher'], true);
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
    /** True for owner, admin, and teacher accounts (staff teaching capabilities). */
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

if (!function_exists('portal_assigned_course_ids_for_user')) {
    /** @return int[] */
    function portal_assigned_course_ids_for_user(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $stmt = portal_db()->prepare(
            'SELECT course_id FROM course_teachers WHERE user_id = ?'
        );
        $stmt->execute([$userId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('portal_user_course_access_ids')) {
    /**
     * Course IDs a user should appear against in admin "course access" UI:
     * enrollments for students, course_teachers for teachers.
     * Admins/owners see every course in the catalog separately.
     *
     * @return int[]
     */
    function portal_user_course_access_ids(int $userId): array
    {
        $user = portal_find_user_by_id($userId);
        if ($user === null) {
            return [];
        }

        $role = (string) ($user['role'] ?? 'student');
        if ($role === 'teacher') {
            return portal_assigned_course_ids_for_user($userId);
        }

        return portal_enrolled_course_ids($userId);
    }
}

if (!function_exists('portal_set_user_course_access')) {
    /**
     * Replace a user's module access. Teachers are stored in course_teachers;
     * students in enrollments. Clears the other table so counts stay correct.
     *
     * @param int[] $courseIds
     */
    function portal_set_user_course_access(int $userId, array $courseIds): bool
    {
        $user = portal_find_user_by_id($userId);
        if ($user === null) {
            return false;
        }

        $courseIds = array_values(array_unique(array_filter(
            array_map('intval', $courseIds),
            static fn(int $id): bool => $id > 0
        )));

        $pdo = portal_db();
        $role = (string) ($user['role'] ?? 'student');

        if ($role === 'teacher') {
            $pdo->prepare('DELETE FROM course_teachers WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$userId]);
            $ins = $pdo->prepare(
                "INSERT OR IGNORE INTO course_teachers (course_id, user_id, assignment_role)
                 VALUES (?, ?, 'teacher')"
            );
            foreach ($courseIds as $cid) {
                $ins->execute([$cid, $userId]);
            }

            return true;
        }

        if (in_array($role, ['owner', 'admin'], true)) {
            // Site-wide staff already see every module; keep optional enrollments
            // for calendar/comms edge cases without creating teacher assignments.
            $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$userId]);
            $ins = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
            foreach ($courseIds as $cid) {
                $ins->execute([$userId, $cid]);
            }

            return true;
        }

        $pdo->prepare('DELETE FROM enrollments WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM course_teachers WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT OR IGNORE INTO enrollments (user_id, course_id) VALUES (?, ?)');
        foreach ($courseIds as $cid) {
            $ins->execute([$userId, $cid]);
        }

        return true;
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
        $env = getenv('PORTAL_UPLOADS_PATH');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), "\\/");
        }

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

if (!function_exists('portal_folder_item_content_locked')) {
    /**
     * True when an enrolled non-manager must not open this material because the
     * item or its parent folder is locked. Expects item row keys `locked` and,
     * when joined, `folder_locked`.
     */
    function portal_folder_item_content_locked(array $item): bool
    {
        return !empty($item['locked']) || !empty($item['folder_locked']);
    }
}

if (!function_exists('portal_folder_item_lock_state')) {
    /**
     * @return array{id:int,locked:mixed,folder_locked:mixed}|null
     */
    function portal_folder_item_lock_state(int $itemId): ?array
    {
        if ($itemId <= 0) {
            return null;
        }

        $stmt = portal_db()->prepare(
            "SELECT cfi.id, cfi.locked, COALESCE(cf.locked, 0) AS folder_locked
             FROM course_folder_items cfi
             LEFT JOIN course_folders cf ON cf.id = cfi.folder_id
             WHERE cfi.id = ?
             LIMIT 1"
        );
        $stmt->execute([$itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

if (!function_exists('portal_activity_content_locked')) {
    /**
     * True when the activity's linked course item (or its folder) is locked.
     */
    function portal_activity_content_locked(array $activity): bool
    {
        $itemId = (int) ($activity['course_item_id'] ?? 0);
        $state = portal_folder_item_lock_state($itemId);
        if ($state === null) {
            return false;
        }

        return portal_folder_item_content_locked($state);
    }
}

require_once __DIR__ . '/integrity.php';
require_once __DIR__ . '/submission_security.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notification_mail.php';
require_once __DIR__ . '/invite.php';
// activity.php is loaded earlier so migrations run with portal_run_migrations()

// ── Password reset (self-service email) ───────────────────────────────────────

if (!function_exists('portal_app_secret')) {
    function portal_app_secret(): string
    {
        static $secret = null;
        if ($secret !== null) {
            return $secret;
        }

        $env = getenv('PORTAL_APP_SECRET');
        if (is_string($env) && trim($env) !== '') {
            $secret = trim($env);
            return $secret;
        }

        $path = function_exists('portal_db_path') ? portal_db_path() : (__DIR__ . '/database/portal.db');
        $secret = hash('sha256', 'portal-app|' . $path);
        return $secret;
    }
}

if (!function_exists('portal_configured_base_url')) {
    /**
     * Explicit public origin from PORTAL_BASE_URL, or empty when unset.
     * Password-reset mail must use this only — never HTTP_HOST.
     */
    function portal_configured_base_url(): string
    {
        $configured = getenv('PORTAL_BASE_URL');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return '';
    }
}

if (!function_exists('portal_base_url')) {
    function portal_base_url(): string
    {
        $configured = portal_configured_base_url();
        if ($configured !== '') {
            return $configured;
        }

        // Dev/local fallback for non-security link building only. Password reset
        // refuses to send mail when PORTAL_BASE_URL is unset (see below).
        $https = (
            (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        );
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return rtrim($scheme . '://' . $host . $dir, '/');
    }
}

if (!function_exists('portal_password_reset_hash')) {
    function portal_password_reset_hash(string $token): string
    {
        return hash_hmac('sha256', $token, portal_app_secret());
    }
}

if (!function_exists('portal_password_reset_is_locked')) {
    function portal_password_reset_is_locked(string $ip, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        try {
            $stmt = portal_db()->prepare(
                'SELECT COUNT(*) FROM password_reset_attempts WHERE ip = ? AND attempted_at > ?'
            );
            $stmt->execute([$ip, time() - $windowSeconds]);
            return (int) $stmt->fetchColumn() >= $maxAttempts;
        } catch (\PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('portal_password_reset_record_attempt')) {
    function portal_password_reset_record_attempt(string $ip): void
    {
        try {
            portal_db()
                ->prepare('INSERT INTO password_reset_attempts (ip, attempted_at) VALUES (?, ?)')
                ->execute([$ip, time()]);
        } catch (\PDOException $e) {
        }
    }
}

if (!function_exists('portal_password_reset_find_valid')) {
    function portal_password_reset_find_valid(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) < 32) {
            return null;
        }

        try {
            $stmt = portal_db()->prepare(
                'SELECT t.*, u.email AS user_email, u.name AS user_name
                 FROM password_reset_tokens t
                 INNER JOIN users u ON u.id = t.user_id
                 WHERE t.token_hash = ?
                 LIMIT 1'
            );
            $stmt->execute([portal_password_reset_hash($token)]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            if ($row['used_at'] !== null && $row['used_at'] !== '') {
                return null;
            }
            if ((int) $row['expires_at'] < time()) {
                return null;
            }

            return $row;
        } catch (\PDOException $e) {
            return null;
        }
    }
}

if (!function_exists('portal_password_reset_request')) {
    /**
     * Always returns without revealing whether the email exists.
     * Callers should show a neutral success message.
     */
    function portal_password_reset_request(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        portal_password_reset_record_attempt($ip);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            portal_log_security_event('password_reset_requested', 'info', 'Reset requested with invalid email');
            return;
        }

        $user = portal_find_user($email);
        if ($user === null || strtolower((string) ($user['email'] ?? '')) !== $email) {
            portal_log_security_event('password_reset_requested', 'info', 'Reset requested for unknown email');
            return;
        }

        $userId = (int) $user['id'];
        portal_log_security_event(
            'password_reset_requested',
            'info',
            'Reset requested for user id ' . $userId,
            $userId
        );

        if (!portal_mail_configured()) {
            portal_log_security_event(
                'password_reset_failed',
                'medium',
                'Reset mail skipped: SMTP not configured',
                $userId
            );
            return;
        }

        $baseUrl = portal_configured_base_url();
        if ($baseUrl === '') {
            // Fail closed: never email a reset link built from HTTP_HOST.
            portal_log_security_event(
                'password_reset_failed',
                'high',
                'Reset mail skipped: PORTAL_BASE_URL not configured',
                $userId
            );
            return;
        }

        $db = portal_db();
        $now = time();
        $token = bin2hex(random_bytes(32));
        $tokenHash = portal_password_reset_hash($token);

        try {
            $db->prepare(
                'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL'
            )->execute([$now, $userId]);

            $db->prepare(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, requested_ip, created_at)
                 VALUES (?, ?, ?, NULL, ?, ?)'
            )->execute([$userId, $tokenHash, $now + 3600, $ip, $now]);
        } catch (\PDOException $e) {
            portal_log_security_event(
                'password_reset_failed',
                'medium',
                'Could not store reset token',
                $userId
            );
            return;
        }

        $resetUrl = $baseUrl . '/reset-password.php?token=' . urlencode($token);
        $school = portal_school_name();
        $name = trim((string) ($user['name'] ?? '')) ?: 'there';
        $subject = 'Reset your ' . $school . ' password';
        $safeName = portal_escape($name);
        $safeSchool = portal_escape($school);
        $safeUrl = portal_escape($resetUrl);
        $html = '<p>Hi ' . $safeName . ',</p>'
            . '<p>We received a request to reset your ' . $safeSchool . ' portal password.</p>'
            . '<p><a href="' . $safeUrl . '">Choose a new password</a></p>'
            . '<p>This link expires in 60 minutes. If you did not ask for a reset, you can ignore this email.</p>';
        $text = "Hi {$name},\n\n"
            . "We received a request to reset your {$school} portal password.\n\n"
            . "Open this link to choose a new password:\n{$resetUrl}\n\n"
            . "This link expires in 60 minutes. If you did not ask for a reset, you can ignore this email.\n";

        if (!portal_mail_send((string) $user['email'], $subject, $html, $text)) {
            portal_log_security_event(
                'password_reset_failed',
                'medium',
                'Reset mail could not be sent',
                $userId
            );
        }
    }
}

if (!function_exists('portal_password_reset_consume')) {
    /**
     * @return string|null Error message, or null on success.
     */
    function portal_password_reset_consume(string $token, string $newPassword): ?string
    {
        $ruleError = portal_password_validate($newPassword);
        if ($ruleError !== '') {
            return $ruleError;
        }

        $row = portal_password_reset_find_valid($token);
        if ($row === null) {
            portal_log_security_event('password_reset_failed', 'medium', 'Invalid or expired reset token');
            return 'This reset link is invalid or has expired. Request a new one.';
        }

        $userId = (int) $row['user_id'];
        $tokenId = (int) $row['id'];
        $now = time();
        $db = portal_db();

        try {
            $db->beginTransaction();
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            $db->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE id = ?')
                ->execute([$now, $tokenId]);
            $db->prepare(
                'UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL AND id != ?'
            )->execute([$now, $userId, $tokenId]);
            $db->commit();
        } catch (\PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            portal_log_security_event(
                'password_reset_failed',
                'high',
                'Could not update password after reset',
                $userId
            );
            return 'Could not update your password. Please try again.';
        }

        portal_log_security_event(
            'password_reset_completed',
            'medium',
            'Password reset completed',
            $userId
        );

        return null;
    }
}
