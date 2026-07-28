<?php
declare(strict_types=1);

/**
 * Isolated, allow-listed user interface preferences.
 *
 * Keeping these settings in their own table prevents appearance choices from
 * becoming coupled to notification preferences or course/assessment data.
 */

if (!function_exists('portal_customization_defaults')) {
    /** @return array<string, int|string|list<int>> */
    function portal_customization_defaults(): array
    {
        return [
            'theme'              => 'light',
            'accent'             => 'crimson',
            'text_size'          => 'standard',
            'density'            => 'comfortable',
            'font_style'         => 'standard',
            'line_spacing'       => 'standard',
            'corner_style'       => 'standard',
            'background_style'   => 'standard',
            'page_width'         => 'standard',
            'sidebar_style'      => 'standard',
            'course_view'        => 'list',
            'dashboard_focus'    => 'priorities',
            'reduced_motion'     => 0,
            'high_contrast'      => 0,
            'show_continue'      => 1,
            'show_quick_access'  => 1,
            'show_bulletin'      => 1,
            'favorite_course_ids' => [],
        ];
    }
}

if (!function_exists('portal_customization_schema')) {
    function portal_customization_schema(): void
    {
        static $ready = false;
        if ($ready) {
            return;
        }

        portal_db()->exec("
            CREATE TABLE IF NOT EXISTS user_customizations (
                user_id              INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                theme                TEXT NOT NULL DEFAULT 'light',
                accent               TEXT NOT NULL DEFAULT 'crimson',
                text_size            TEXT NOT NULL DEFAULT 'standard',
                density              TEXT NOT NULL DEFAULT 'comfortable',
                font_style           TEXT NOT NULL DEFAULT 'standard',
                line_spacing         TEXT NOT NULL DEFAULT 'standard',
                corner_style         TEXT NOT NULL DEFAULT 'standard',
                background_style     TEXT NOT NULL DEFAULT 'standard',
                page_width           TEXT NOT NULL DEFAULT 'standard',
                sidebar_style        TEXT NOT NULL DEFAULT 'standard',
                course_view          TEXT NOT NULL DEFAULT 'list',
                dashboard_focus      TEXT NOT NULL DEFAULT 'priorities',
                reduced_motion       INTEGER NOT NULL DEFAULT 0,
                high_contrast        INTEGER NOT NULL DEFAULT 0,
                show_continue        INTEGER NOT NULL DEFAULT 1,
                show_quick_access    INTEGER NOT NULL DEFAULT 1,
                show_bulletin        INTEGER NOT NULL DEFAULT 1,
                favorite_course_ids  TEXT NOT NULL DEFAULT '[]',
                updated_at           TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        // Add newer visual controls for databases created by an earlier
        // version of the customization module.
        $columns = array_column(
            portal_db()->query('PRAGMA table_info(user_customizations)')->fetchAll(),
            'name'
        );
        $additions = [
            'font_style'       => "ALTER TABLE user_customizations ADD COLUMN font_style TEXT NOT NULL DEFAULT 'standard'",
            'line_spacing'      => "ALTER TABLE user_customizations ADD COLUMN line_spacing TEXT NOT NULL DEFAULT 'standard'",
            'corner_style'     => "ALTER TABLE user_customizations ADD COLUMN corner_style TEXT NOT NULL DEFAULT 'standard'",
            'background_style' => "ALTER TABLE user_customizations ADD COLUMN background_style TEXT NOT NULL DEFAULT 'standard'",
            'page_width'       => "ALTER TABLE user_customizations ADD COLUMN page_width TEXT NOT NULL DEFAULT 'standard'",
            'sidebar_style'    => "ALTER TABLE user_customizations ADD COLUMN sidebar_style TEXT NOT NULL DEFAULT 'standard'",
        ];
        foreach ($additions as $column => $sql) {
            if (!in_array($column, $columns, true)) {
                portal_db()->exec($sql);
            }
        }
        $ready = true;
    }
}

if (!function_exists('portal_customization_validate')) {
    /**
     * @param array<string, mixed> $values
     * @return array<string, int|string|list<int>>
     */
    function portal_customization_validate(array $values): array
    {
        $defaults = portal_customization_defaults();
        $allowed = [
            'theme'           => ['light'],
            'accent'          => [
                'crimson',
                'ocean',
                'forest',
                'violet',
                'teal',
                'amber',
                'berry',
                'slate',
                'coral',
                'indigo',
                'cyan',
                'olive',
            ],
            'text_size'       => ['standard', 'comfortable', 'large'],
            'density'         => ['compact', 'comfortable'],
            'font_style'      => [
                'standard',
                'modern',
                'system',
                'clear',
                'friendly',
                'classic',
                'bookish',
            ],
            'line_spacing'    => ['standard', 'relaxed'],
            'corner_style'    => ['standard', 'rounded'],
            'background_style'=> ['standard', 'glow', 'plain'],
            'page_width'      => ['standard'],
            'sidebar_style'   => ['standard', 'deep'],
            'course_view'     => ['list', 'grid'],
            'dashboard_focus' => ['schedule', 'priorities'],
        ];

        $clean = $defaults;
        foreach ($allowed as $key => $options) {
            $candidate = (string) ($values[$key] ?? $defaults[$key]);
            $clean[$key] = in_array($candidate, $options, true) ? $candidate : $defaults[$key];
        }

        foreach (['reduced_motion', 'high_contrast', 'show_continue', 'show_quick_access', 'show_bulletin'] as $key) {
            $clean[$key] = !empty($values[$key]) ? 1 : 0;
        }

        $favoriteIds = $values['favorite_course_ids'] ?? [];
        if (is_string($favoriteIds)) {
            $decoded = json_decode($favoriteIds, true);
            $favoriteIds = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($favoriteIds)) {
            $favoriteIds = [];
        }
        $favoriteIds = array_values(array_unique(array_filter(
            array_map('intval', $favoriteIds),
            static fn(int $id): bool => $id > 0
        )));
        $clean['favorite_course_ids'] = array_slice($favoriteIds, 0, 50);

        return $clean;
    }
}

if (!function_exists('portal_customization_preferences')) {
    /** @return array<string, int|string|list<int>> */
    function portal_customization_preferences(int $userId): array
    {
        $defaults = portal_customization_defaults();
        if ($userId <= 0) {
            return $defaults;
        }

        try {
            portal_customization_schema();
            $stmt = portal_db()->prepare('SELECT * FROM user_customizations WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            return $row ? portal_customization_validate($row) : $defaults;
        } catch (\Throwable $e) {
            return $defaults;
        }
    }
}

if (!function_exists('portal_save_customization_preferences')) {
    /** @param array<string, mixed> $values */
    function portal_save_customization_preferences(int $userId, array $values): void
    {
        if ($userId <= 0) {
            return;
        }

        portal_customization_schema();
        $clean = portal_customization_validate($values);
        portal_db()->prepare(
            "INSERT INTO user_customizations (
                user_id, theme, accent, text_size, density, font_style,
                line_spacing, corner_style, background_style, page_width, sidebar_style,
                course_view, dashboard_focus, reduced_motion, high_contrast,
                show_continue, show_quick_access, show_bulletin,
                favorite_course_ids, updated_at
             ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
             ON CONFLICT(user_id) DO UPDATE SET
                theme = excluded.theme,
                accent = excluded.accent,
                text_size = excluded.text_size,
                density = excluded.density,
                font_style = excluded.font_style,
                line_spacing = excluded.line_spacing,
                corner_style = excluded.corner_style,
                background_style = excluded.background_style,
                page_width = excluded.page_width,
                sidebar_style = excluded.sidebar_style,
                course_view = excluded.course_view,
                dashboard_focus = excluded.dashboard_focus,
                reduced_motion = excluded.reduced_motion,
                high_contrast = excluded.high_contrast,
                show_continue = excluded.show_continue,
                show_quick_access = excluded.show_quick_access,
                show_bulletin = excluded.show_bulletin,
                favorite_course_ids = excluded.favorite_course_ids,
                updated_at = datetime('now')"
        )->execute([
            $userId,
            $clean['theme'],
            $clean['accent'],
            $clean['text_size'],
            $clean['density'],
            $clean['font_style'],
            $clean['line_spacing'],
            $clean['corner_style'],
            $clean['background_style'],
            $clean['page_width'],
            $clean['sidebar_style'],
            $clean['course_view'],
            $clean['dashboard_focus'],
            $clean['reduced_motion'],
            $clean['high_contrast'],
            $clean['show_continue'],
            $clean['show_quick_access'],
            $clean['show_bulletin'],
            json_encode($clean['favorite_course_ids']),
        ]);
    }
}

if (!function_exists('portal_toggle_favorite_course')) {
    function portal_toggle_favorite_course(int $userId, int $courseId): bool
    {
        $prefs = portal_customization_preferences($userId);
        $ids = $prefs['favorite_course_ids'];
        if (!is_array($ids)) {
            $ids = [];
        }

        $index = array_search($courseId, $ids, true);
        if ($index === false) {
            $ids[] = $courseId;
            $favorite = true;
        } else {
            unset($ids[$index]);
            $favorite = false;
        }
        $prefs['favorite_course_ids'] = array_values($ids);
        portal_save_customization_preferences($userId, $prefs);
        return $favorite;
    }
}
