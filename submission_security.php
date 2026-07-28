<?php
declare(strict_types=1);

/**
 * Hardened student submission upload validation and receipt helpers.
 */

if (!function_exists('portal_submission_type_mismatch_message')) {
    function portal_submission_type_mismatch_message(): string
    {
        return 'File does not match the selected type.';
    }
}

if (!function_exists('portal_submission_upload_generic_message')) {
    function portal_submission_upload_generic_message(): string
    {
        return 'Upload failed. Please try again.';
    }
}

if (!function_exists('portal_submission_allowed_types')) {
    /** @return list<string> */
    function portal_submission_allowed_types(): array
    {
        return portal_supported_submission_extensions();
    }
}

if (!function_exists('portal_submission_type_labels')) {
    /** @return array<string, string> */
    function portal_submission_type_labels(): array
    {
        return [
            'pdf'  => 'PDF (.pdf)',
            'docx' => 'Word (.docx)',
            'doc'  => 'Word (.doc)',
            'txt'  => 'Text (.txt)',
            'png'  => 'Image PNG (.png)',
            'jpg'  => 'Image JPG (.jpg)',
            'jpeg' => 'Image JPEG (.jpeg)',
            'gif'  => 'Image GIF (.gif)',
            'webp' => 'Image WebP (.webp)',
        ];
    }
}

if (!function_exists('portal_submission_max_bytes')) {
    function portal_submission_max_bytes(): int
    {
        return 40 * 1024 * 1024;
    }
}

if (!function_exists('portal_dangerous_filename_suffixes')) {
    /** @return list<string> */
    function portal_dangerous_filename_suffixes(): array
    {
        return [
            'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
            'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'jsx', 'mjs',
            'html', 'htm', 'shtml', 'svg', 'svgz', 'hta', 'jar', 'war',
            'sh', 'bash', 'ps1', 'vbs', 'wsf', 'cgi', 'pl', 'py', 'rb',
        ];
    }
}

if (!function_exists('portal_validate_submission_filename')) {
    /**
     * @return array{ok: bool, display_name?: string, extension?: string, reason?: string}
     */
    function portal_validate_submission_filename(string $originalName, string $declaredType): array
    {
        $declaredType = strtolower(trim($declaredType));
        if ($originalName === '' || str_contains($originalName, "\0")) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $originalName)) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }
        if (str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }

        $base = basename(str_replace(["\0"], '', $originalName));
        $base = trim($base);
        if ($base === '' || str_starts_with($base, '.') || preg_match('/[. ]$/', $base)) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }
        if (strlen($base) > 180) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }

        // Reject Unicode directionality / weird separators used in spoofing.
        if (preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $base)) {
            return ['ok' => false, 'reason' => 'unsafe_filename'];
        }

        $parts = explode('.', $base);
        if (count($parts) < 2) {
            return ['ok' => false, 'reason' => 'type_mismatch'];
        }

        $finalExt = strtolower((string) array_pop($parts));
        if ($finalExt === '' || $finalExt !== $declaredType) {
            return ['ok' => false, 'reason' => 'type_mismatch'];
        }

        $dangerous = portal_dangerous_filename_suffixes();
        foreach ($parts as $segment) {
            $seg = strtolower($segment);
            if ($seg !== '' && in_array($seg, $dangerous, true)) {
                return ['ok' => false, 'reason' => 'unsafe_filename'];
            }
        }

        // Also reject final.ext when middle pieces look like double extensions of allowlisted+dangerous.
        // e.g. work.pdf.php already caught; work.docx.exe caught.

        return [
            'ok' => true,
            'display_name' => $base,
            'extension' => $finalExt,
        ];
    }
}

if (!function_exists('portal_upload_mime_ok')) {
    function portal_upload_mime_ok(string $tmpPath, string $ext): bool
    {
        $ext = strtolower($ext);
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return false;
        }
        if (!class_exists('finfo')) {
            return false;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        if ($mime === '' || $mime === 'application/octet-stream') {
            // octet-stream is too vague for fail-open; treat as fail for submissions
            // except we still map explicitly below — reject here if empty.
            if ($mime === '') {
                return false;
            }
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
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $zip,
                'application/x-zip-compressed',
            ],
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

        if (!isset($allowed[$ext])) {
            return false;
        }

        // DOC must be identifiable — octet-stream alone is not enough.
        if ($ext === 'doc' && $mime === 'application/octet-stream') {
            return false;
        }

        return in_array($mime, $allowed[$ext], true);
    }
}

if (!function_exists('portal_submission_signature_ok')) {
    /**
     * Lightweight format checks that supplement finfo.
     * @return array{ok: bool, reason?: string}
     */
    function portal_submission_signature_ok(string $tmpPath, string $declaredType): array
    {
        $declaredType = strtolower($declaredType);
        if (!is_file($tmpPath) || !is_readable($tmpPath)) {
            return ['ok' => false, 'reason' => 'signature_mismatch'];
        }

        $fh = fopen($tmpPath, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'reason' => 'signature_mismatch'];
        }
        $header = (string) fread($fh, 64);
        fclose($fh);

        if ($declaredType === 'pdf') {
            if (!str_starts_with($header, '%PDF-')) {
                return ['ok' => false, 'reason' => 'signature_mismatch'];
            }
            return ['ok' => true];
        }

        if (in_array($declaredType, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            if (!function_exists('getimagesize')) {
                return ['ok' => false, 'reason' => 'signature_mismatch'];
            }
            $info = @getimagesize($tmpPath);
            if ($info === false || empty($info[2])) {
                return ['ok' => false, 'reason' => 'signature_mismatch'];
            }
            $map = [
                'png'  => IMAGETYPE_PNG,
                'jpg'  => IMAGETYPE_JPEG,
                'jpeg' => IMAGETYPE_JPEG,
                'gif'  => IMAGETYPE_GIF,
                'webp' => defined('IMAGETYPE_WEBP') ? IMAGETYPE_WEBP : 18,
            ];
            $expected = $map[$declaredType] ?? null;
            if ($expected === null || (int) $info[2] !== (int) $expected) {
                return ['ok' => false, 'reason' => 'signature_mismatch'];
            }
            return ['ok' => true];
        }

        if ($declaredType === 'txt') {
            $raw = (string) file_get_contents($tmpPath, false, null, 0, 65536);
            if (str_contains($raw, "\0")) {
                return ['ok' => false, 'reason' => 'signature_mismatch'];
            }
            // Heuristic: too many non-text bytes → binary
            $len = strlen($raw);
            if ($len > 0) {
                $nonText = preg_match_all('/[^\x09\x0A\x0D\x20-\x7E\xC2-\xF4]/', $raw) ?: 0;
                if (($nonText / $len) > 0.30) {
                    return ['ok' => false, 'reason' => 'signature_mismatch'];
                }
            }
            return ['ok' => true];
        }

        if ($declaredType === 'docx') {
            return portal_docx_structure_ok($tmpPath);
        }

        if ($declaredType === 'doc') {
            // Rely on MIME fail-closed; OLE signature is variable.
            return ['ok' => true];
        }

        return ['ok' => false, 'reason' => 'signature_mismatch'];
    }
}

if (!function_exists('portal_docx_structure_ok')) {
    /**
     * Validate OOXML structure without extracting to disk.
     * @return array{ok: bool, reason?: string}
     */
    function portal_docx_structure_ok(string $tmpPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'reason' => 'mime_unavailable'];
        }

        $zip = new ZipArchive();
        $opened = @$zip->open($tmpPath);
        if ($opened !== true) {
            return ['ok' => false, 'reason' => 'invalid_docx_structure'];
        }

        $required = [
            '[Content_Types].xml',
            '_rels/.rels',
            'word/document.xml',
        ];
        foreach ($required as $need) {
            if ($zip->locateName($need) === false) {
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }
        }

        $maxEntries = 2500;
        $maxUncompressed = 80 * 1024 * 1024; // 80 MB uncompressed total
        $maxRatio = 100.0;
        $entryCount = $zip->numFiles;
        if ($entryCount <= 0 || $entryCount > $maxEntries) {
            $zip->close();
            return ['ok' => false, 'reason' => 'archive_limit_exceeded'];
        }

        $totalUncompressed = 0;
        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }
            $name = (string) ($stat['name'] ?? '');
            if ($name === '' || str_contains($name, "\0")) {
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }
            // Path traversal / absolute paths
            $norm = str_replace('\\', '/', $name);
            if (str_starts_with($norm, '/') || preg_match('#^[A-Za-z]:/#', $norm) || str_contains($norm, '../')) {
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }

            // Encrypted entries (ZipCrypto / WinZip AES often set encryption bit)
            $encryption = (int) ($stat['encryption_method'] ?? 0);
            if ($encryption !== 0) {
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }
            // Fallback flag check when encryption_method absent
            if (isset($stat['flags']) && (((int) $stat['flags']) & 0x1) === 0x1) {
                // bit 0 = encrypted in traditional ZIP
                // Many DOCX are not encrypted; reject if set.
                $zip->close();
                return ['ok' => false, 'reason' => 'invalid_docx_structure'];
            }

            $comp = (int) ($stat['comp_size'] ?? 0);
            $uncomp = (int) ($stat['size'] ?? 0);
            if ($uncomp < 0 || $comp < 0) {
                $zip->close();
                return ['ok' => false, 'reason' => 'archive_limit_exceeded'];
            }
            $totalUncompressed += $uncomp;
            if ($totalUncompressed > $maxUncompressed) {
                $zip->close();
                return ['ok' => false, 'reason' => 'archive_limit_exceeded'];
            }
            if ($comp > 0 && $uncomp > 0) {
                $ratio = $uncomp / max(1, $comp);
                if ($ratio > $maxRatio && $uncomp > 1024 * 1024) {
                    $zip->close();
                    return ['ok' => false, 'reason' => 'archive_limit_exceeded'];
                }
            }
        }

        $zip->close();
        return ['ok' => true];
    }
}

if (!function_exists('portal_validate_submission_upload')) {
    /**
     * Orchestrate PHP upload + type + MIME + signature checks.
     *
     * @param array<string, mixed>|null $fileField $_FILES entry
     * @return array{
     *   ok: bool,
     *   public_message?: string,
     *   reason?: string,
     *   display_name?: string,
     *   extension?: string,
     *   tmp_path?: string,
     *   size?: int,
     *   declared_type?: string
     * }
     */
    function portal_validate_submission_upload(?array $fileField, string $declaredType): array
    {
        $mismatch = portal_submission_type_mismatch_message();
        $generic = portal_submission_upload_generic_message();
        $declaredType = strtolower(trim($declaredType));

        if ($declaredType === '') {
            return ['ok' => false, 'public_message' => $mismatch, 'reason' => 'missing_type'];
        }
        if (!in_array($declaredType, portal_submission_allowed_types(), true)) {
            return ['ok' => false, 'public_message' => $mismatch, 'reason' => 'invalid_type'];
        }

        if ($fileField === null || $fileField === []) {
            return ['ok' => false, 'public_message' => $generic, 'reason' => 'upload_error'];
        }

        $error = (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'public_message' => $generic, 'reason' => 'upload_error'];
        }

        $tmpPath = (string) ($fileField['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return ['ok' => false, 'public_message' => $generic, 'reason' => 'not_uploaded_file'];
        }

        $size = @filesize($tmpPath);
        if ($size === false) {
            return ['ok' => false, 'public_message' => $generic, 'reason' => 'upload_error'];
        }
        $size = (int) $size;
        if ($size <= 0) {
            return ['ok' => false, 'public_message' => $generic, 'reason' => 'empty_file'];
        }
        if ($size > portal_submission_max_bytes()) {
            return [
                'ok' => false,
                'public_message' => 'File is too large. Maximum allowed size is 40 MB.',
                'reason' => 'size_exceeded',
            ];
        }

        $nameCheck = portal_validate_submission_filename((string) ($fileField['name'] ?? ''), $declaredType);
        if (!$nameCheck['ok']) {
            $reason = (string) ($nameCheck['reason'] ?? 'unsafe_filename');
            $public = in_array($reason, ['type_mismatch', 'unsafe_filename'], true) ? $mismatch : $generic;
            if ($reason === 'unsafe_filename') {
                $public = $mismatch;
            }
            return ['ok' => false, 'public_message' => $public, 'reason' => $reason];
        }

        if (!class_exists('finfo')) {
            return ['ok' => false, 'public_message' => $mismatch, 'reason' => 'mime_unavailable'];
        }
        if (!portal_upload_mime_ok($tmpPath, $declaredType)) {
            return ['ok' => false, 'public_message' => $mismatch, 'reason' => 'mime_mismatch'];
        }

        $sig = portal_submission_signature_ok($tmpPath, $declaredType);
        if (!$sig['ok']) {
            return [
                'ok' => false,
                'public_message' => $mismatch,
                'reason' => (string) ($sig['reason'] ?? 'signature_mismatch'),
            ];
        }

        return [
            'ok' => true,
            'display_name' => (string) $nameCheck['display_name'],
            'extension' => (string) $nameCheck['extension'],
            'tmp_path' => $tmpPath,
            'size' => $size,
            'declared_type' => $declaredType,
        ];
    }
}

if (!function_exists('portal_integrity_receipt_number')) {
    function portal_integrity_receipt_number(int $courseId = 0, int $itemId = 0, int $userId = 0): string
    {
        unset($courseId, $itemId, $userId);
        return 'RIEO-' . strtoupper(bin2hex(random_bytes(16)));
    }
}

if (!function_exists('portal_normalize_receipt_number')) {
    function portal_normalize_receipt_number(string $receipt): string
    {
        return strtoupper(trim($receipt));
    }
}

if (!function_exists('portal_receipt_format_ok')) {
    function portal_receipt_format_ok(string $normalized): bool
    {
        return (bool) preg_match('/^RIEO-[A-F0-9]{32}$/', $normalized);
    }
}

if (!function_exists('portal_mask_receipt_number')) {
    function portal_mask_receipt_number(string $receipt): string
    {
        $n = portal_normalize_receipt_number($receipt);
        if (strlen($n) < 12) {
            return 'RIEO-********';
        }
        return substr($n, 0, 9) . '…' . substr($n, -4);
    }
}

if (!function_exists('portal_generate_unique_receipt_number')) {
    function portal_generate_unique_receipt_number(PDO $db, int $maxAttempts = 8): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $candidate = portal_integrity_receipt_number();
            $stmt = $db->prepare('SELECT 1 FROM course_submissions WHERE receipt_number = ? LIMIT 1');
            $stmt->execute([$candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
        }
        // Extremely unlikely fallback
        return 'RIEO-' . strtoupper(bin2hex(random_bytes(16)));
    }
}

if (!function_exists('portal_find_submission_by_receipt')) {
    /**
     * Exact receipt lookup with joined display fields. No filesystem paths.
     * @return array<string, mixed>|null
     */
    function portal_find_submission_by_receipt(string $receipt): ?array
    {
        $normalized = portal_normalize_receipt_number($receipt);
        if (!portal_receipt_format_ok($normalized)) {
            return null;
        }

        $stmt = portal_db()->prepare(
            "SELECT cs.id, cs.item_id, cs.course_id, cs.user_id, cs.filename, cs.filesize,
                    cs.submitted_at, cs.score, cs.receipt_number, cs.file_sha256,
                    cs.declared_file_type,
                    u.name AS student_name, u.username AS student_username,
                    c.title AS course_title, c.slug AS course_slug,
                    cfi.title AS assignment_title
             FROM course_submissions cs
             JOIN users u ON u.id = cs.user_id
             JOIN courses c ON c.id = cs.course_id
             LEFT JOIN course_folder_items cfi ON cfi.id = cs.item_id
             WHERE cs.receipt_number = ?
             LIMIT 1"
        );
        $stmt->execute([$normalized]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('portal_receipt_lookup_rate_limited')) {
    /**
     * Rate-limit admin receipt lookups by user id and IP.
     * Uses login_attempts-style table receipt_lookup_attempts.
     */
    function portal_receipt_lookup_rate_limited(int $userId, string $ip, int $maxAttempts = 30, int $windowSeconds = 3600): bool
    {
        try {
            $db = portal_db();
            $since = date('Y-m-d H:i:s', time() - $windowSeconds);
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM receipt_lookup_attempts
                 WHERE attempted_at > ?
                   AND (user_id = ? OR ip = ?)"
            );
            $stmt->execute([$since, $userId, $ip]);
            return ((int) $stmt->fetchColumn()) >= $maxAttempts;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('portal_receipt_lookup_record_attempt')) {
    function portal_receipt_lookup_record_attempt(int $userId, string $ip): void
    {
        try {
            portal_db()->prepare(
                "INSERT INTO receipt_lookup_attempts (user_id, ip, attempted_at) VALUES (?,?,datetime('now'))"
            )->execute([$userId, substr($ip, 0, 64)]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

if (!function_exists('portal_log_blocked_upload_reason')) {
    function portal_log_blocked_upload_reason(string $internalReason, string $detail = ''): void
    {
        $summary = 'Rejected upload: ' . substr($internalReason, 0, 64);
        if ($detail !== '') {
            $summary .= ' | ' . substr($detail, 0, 80);
        }
        portal_log_blocked_upload($summary);
    }
}

if (!function_exists('portal_submissions_storage_dir')) {
    /**
     * Absolute directory for a submission file. Prefer PORTAL_UPLOADS_PATH outside web root.
     */
    function portal_submissions_storage_dir(int $itemId, int $userId): string
    {
        return portal_uploads_base()
            . DIRECTORY_SEPARATOR . 'submissions'
            . DIRECTORY_SEPARATOR . $itemId
            . DIRECTORY_SEPARATOR . $userId;
    }
}

if (!function_exists('portal_new_submission_storage_name')) {
    function portal_new_submission_storage_name(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/', '', $extension) ?? '');
        return bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
    }
}
