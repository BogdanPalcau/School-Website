<?php
declare(strict_types=1);

/**
 * Academic integrity helpers: text extraction, similarity, and optional ZeroGPT checks.
 */

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

if (!function_exists('portal_soffice_converter')) {
    function portal_soffice_converter(): ?string
    {
        $envPath = trim((string) getenv('PORTAL_SOFFICE_PATH'));
        $candidates = [];
        if ($envPath !== '') {
            $candidates[] = $envPath;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (array_filter([getenv('ProgramFiles') ?: '', getenv('ProgramFiles(x86)') ?: '']) as $base) {
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'LibreOffice' . DIRECTORY_SEPARATOR . 'program' . DIRECTORY_SEPARATOR . 'soffice.com';
                $candidates[] = $base . DIRECTORY_SEPARATOR . 'LibreOffice' . DIRECTORY_SEPARATOR . 'program' . DIRECTORY_SEPARATOR . 'soffice.exe';
            }
        } else {
            $candidates[] = '/usr/bin/soffice';
            $candidates[] = '/usr/local/bin/soffice';
            $candidates[] = '/usr/bin/libreoffice';
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('portal_extract_docx_text')) {
    function portal_extract_docx_text(string $absPath): string
    {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($absPath) !== true) {
            return '';
        }

        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('#^word/(document|header\d+|footer\d+|footnotes|endnotes)\.xml$#', $name)) {
                continue;
            }
            $xml = (string) $zip->getFromName($name);
            if ($xml === '') {
                continue;
            }
            $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
            $parts[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $zip->close();

        return trim(implode("\n", $parts));
    }
}

if (!function_exists('portal_extract_pdf_text')) {
    function portal_extract_pdf_text(string $absPath): string
    {
        $raw = (string) @file_get_contents($absPath);
        if ($raw === '') {
            return '';
        }

        $chunks = [];

        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/s', $raw, $parenMatches)) {
            foreach ($parenMatches[0] as $chunk) {
                $chunk = substr($chunk, 1, -1);
                $chunk = preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $chunk) ?? $chunk;
                $chunk = preg_replace('/\\\\[0-7]{1,3}/', ' ', $chunk) ?? $chunk;
                if (trim($chunk) !== '') {
                    $chunks[] = $chunk;
                }
            }
        }

        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $raw, $tjMatches)) {
            foreach ($tjMatches[0] as $match) {
                if (preg_match('/\((?:\\\\.|[^\\\\)])*\)/s', $match, $inner)) {
                    $chunk = substr($inner[0], 1, -1);
                    $chunk = preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $chunk) ?? $chunk;
                    if (trim($chunk) !== '') {
                        $chunks[] = $chunk;
                    }
                }
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $chunks)) ?? '');
    }
}

if (!function_exists('portal_extract_text_via_soffice')) {
    function portal_extract_text_via_soffice(string $absPath): string
    {
        $converter = portal_soffice_converter();
        if ($converter === null || !function_exists('exec') || !is_file($absPath)) {
            return '';
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-text-' . bin2hex(random_bytes(6));
        if (!mkdir($tmpDir, 0755, true)) {
            return '';
        }

        $converterArg = preg_match('/[\\\\\\/]/', $converter) ? escapeshellarg($converter) : $converter;
        $cmd = $converterArg
            . ' --headless --nologo --nofirststartwizard --convert-to txt:Text --outdir '
            . escapeshellarg($tmpDir) . ' ' . escapeshellarg($absPath) . ' 2>&1';

        $output = [];
        $code = 1;
        exec($cmd, $output, $code);

        $expected = $tmpDir . DIRECTORY_SEPARATOR . pathinfo($absPath, PATHINFO_FILENAME) . '.txt';
        $generated = is_file($expected) ? $expected : (glob($tmpDir . DIRECTORY_SEPARATOR . '*.txt')[0] ?? null);
        $text = '';
        if ($code === 0 && $generated !== null && is_file($generated)) {
            $text = trim((string) file_get_contents($generated));
        }

        foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($tmpDir);

        return $text;
    }
}

if (!function_exists('portal_extract_submission_text')) {
    function portal_extract_submission_text(string $absPath, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $text = '';

        if ($ext === 'txt') {
            $text = trim((string) @file_get_contents($absPath));
        } elseif ($ext === 'docx') {
            $text = portal_extract_docx_text($absPath);
        } elseif ($ext === 'pdf') {
            $text = portal_extract_pdf_text($absPath);
        }

        if (mb_strlen($text) < 80 && in_array($ext, ['doc', 'docx', 'pdf', 'odt', 'rtf'], true)) {
            $converted = portal_extract_text_via_soffice($absPath);
            if (mb_strlen($converted) > mb_strlen($text)) {
                $text = $converted;
            }
        }

        return trim($text);
    }
}

if (!function_exists('portal_integrity_normalize_text')) {
    function portal_integrity_normalize_text(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[^\P{Z}\n]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}

if (!function_exists('portal_supported_submission_extensions')) {
    function portal_supported_submission_extensions(): array
    {
        return ['doc', 'docx', 'pdf', 'txt'];
    }
}

if (!function_exists('portal_supported_submission_hint')) {
    function portal_supported_submission_hint(): string
    {
        return 'DOC, DOCX, PDF, or TXT';
    }
}

if (!function_exists('portal_integrity_words')) {
    function portal_integrity_words(string $text): array
    {
        $text = strtolower(portal_integrity_normalize_text($text));
        preg_match_all("/[a-z0-9]+(?:'[a-z0-9]+)?/i", $text, $matches);
        return $matches[0] ?? [];
    }
}

if (!function_exists('portal_integrity_sentences')) {
    function portal_integrity_sentences(string $text, int $minLen = 45): array
    {
        $text = portal_integrity_normalize_text($text);
        $parts = preg_split('/(?<=[.!?])\s+/', $text) ?: [];
        $sentences = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if (mb_strlen($part) >= $minLen) {
                $sentences[] = $part;
            }
        }
        return $sentences;
    }
}

if (!function_exists('portal_integrity_shingles')) {
    function portal_integrity_shingles(array $words, int $size = 5): array
    {
        $count = count($words);
        if ($count < $size) {
            return [];
        }

        $shingles = [];
        for ($i = 0; $i <= $count - $size; $i++) {
            $shingles[implode(' ', array_slice($words, $i, $size))] = true;
        }
        return $shingles;
    }
}

if (!function_exists('portal_integrity_level')) {
    function portal_integrity_level(?float $score): string
    {
        if ($score === null) {
            return 'pending';
        }
        if ($score >= 35.0) {
            return 'high';
        }
        if ($score >= 15.0) {
            return 'medium';
        }
        return 'low';
    }
}

if (!function_exists('portal_integrity_receipt_number')) {
    function portal_integrity_receipt_number(int $courseId, int $itemId, int $userId): string
    {
        return 'RIEO-' . date('Ymd-His') . '-' . $courseId . '-' . $itemId . '-' . $userId . '-' . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('portal_integrity_pair_score')) {
    /**
     * @return array{score: float, phrases: string[], method: string}
     */
    function portal_integrity_pair_score(string $submissionText, string $sourceText): array
    {
        $submissionWords = portal_integrity_words($submissionText);
        $sourceWords = portal_integrity_words($sourceText);
        $submissionNorm = strtolower(portal_integrity_normalize_text($submissionText));
        $bestScore = 0.0;
        $bestPhrases = [];
        $bestMethod = 'none';

        if (count($submissionWords) >= 5 && count($sourceWords) >= 5) {
            foreach ([3, 5, 7] as $size) {
                $subShingles = portal_integrity_shingles($submissionWords, $size);
                $srcShingles = portal_integrity_shingles($sourceWords, $size);
                if ($subShingles === [] || $srcShingles === []) {
                    continue;
                }
                $overlap = array_intersect_key($subShingles, $srcShingles);
                if ($overlap === []) {
                    continue;
                }
                $forward = (count($overlap) / count($subShingles)) * 100;
                $reverse = (count($overlap) / count($srcShingles)) * 100;
                $score = max($forward, $reverse);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestPhrases = array_slice(array_keys($overlap), 0, 8);
                    $bestMethod = 'shingle-' . $size;
                }
            }

            $subSet = array_fill_keys($submissionWords, true);
            $srcSet = array_fill_keys($sourceWords, true);
            $intersection = array_intersect_key($subSet, $srcSet);
            $union = $subSet + $srcSet;
            if ($union !== []) {
                $jaccard = (count($intersection) / count($union)) * 100;
                if ($jaccard > $bestScore) {
                    $bestScore = $jaccard;
                    $bestPhrases = array_slice(array_keys($intersection), 0, 8);
                    $bestMethod = 'word-jaccard';
                }
            }
            if ($srcSet !== []) {
                $sourceContained = (count($intersection) / count($srcSet)) * 100;
                if ($sourceContained > $bestScore) {
                    $bestScore = $sourceContained;
                    $bestPhrases = array_slice(array_keys($intersection), 0, 8);
                    $bestMethod = 'source-contained-in-submission';
                }
            }
            if ($subSet !== []) {
                $submissionContained = (count($intersection) / count($subSet)) * 100;
                if ($submissionContained > $bestScore) {
                    $bestScore = $submissionContained;
                    $bestPhrases = array_slice(array_keys($intersection), 0, 8);
                    $bestMethod = 'submission-contained-in-source';
                }
            }
        }

        $sourceSentences = portal_integrity_sentences($sourceText);
        if ($sourceSentences !== []) {
            $matched = 0;
            $matchedSentences = [];
            foreach ($sourceSentences as $sentence) {
                $needle = strtolower(portal_integrity_normalize_text($sentence));
                if ($needle !== '' && str_contains($submissionNorm, $needle)) {
                    $matched++;
                    $matchedSentences[] = $sentence;
                }
            }
            $containment = ($matched / count($sourceSentences)) * 100;
            if ($containment > $bestScore) {
                $bestScore = $containment;
                $bestPhrases = array_slice(array_map(
                    static fn(string $s): string => mb_strlen($s) > 90 ? mb_substr($s, 0, 90) . '…' : $s,
                    $matchedSentences
                ), 0, 6);
                $bestMethod = 'sentence-containment';
            }
        }

        return [
            'score' => round($bestScore, 1),
            'phrases' => $bestPhrases,
            'method' => $bestMethod,
        ];
    }
}

if (!function_exists('portal_integrity_references_dir')) {
    function portal_integrity_references_dir(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'integrity_references';
    }
}

if (!function_exists('portal_integrity_sentence_hash')) {
    function portal_integrity_sentence_hash(string $sentence): string
    {
        $normalized = strtolower(portal_integrity_normalize_text($sentence));
        return hash('sha256', $normalized);
    }
}

if (!function_exists('portal_integrity_ensure_index_table')) {
    function portal_integrity_ensure_index_table(PDO $db): void
    {
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
    }
}

if (!function_exists('portal_integrity_index_document')) {
    function portal_integrity_index_document(
        PDO $db,
        string $text,
        string $sourceType,
        int $sourceId,
        string $sourceLabel,
        ?int $courseId = null
    ): int {
        portal_integrity_ensure_index_table($db);
        $db->prepare('DELETE FROM integrity_sentence_index WHERE source_type = ? AND source_id = ?')
            ->execute([$sourceType, $sourceId]);

        $insert = $db->prepare(
            'INSERT INTO integrity_sentence_index
             (sentence_hash, sentence_preview, source_type, source_id, source_label, course_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $count = 0;
        foreach (portal_integrity_sentences($text, 35) as $sentence) {
            $hash = portal_integrity_sentence_hash($sentence);
            $preview = mb_strlen($sentence) > 120 ? mb_substr($sentence, 0, 120) . '…' : $sentence;
            $insert->execute([$hash, $preview, $sourceType, $sourceId, $sourceLabel, $courseId]);
            $count++;
        }
        return $count;
    }
}

if (!function_exists('portal_integrity_fingerprint_matches')) {
    /**
     * @return array{score: float, matched: int, total: int, sources: array<int, array<string, mixed>>}
     */
    function portal_integrity_fingerprint_matches(
        PDO $db,
        string $text,
        ?string $excludeSourceType = null,
        ?int $excludeSourceId = null
    ): array {
        portal_integrity_ensure_index_table($db);
        $sentences = portal_integrity_sentences($text, 35);
        $total = count($sentences);
        if ($total === 0) {
            return ['score' => 0.0, 'matched' => 0, 'total' => 0, 'sources' => []];
        }

        $hashes = [];
        foreach ($sentences as $sentence) {
            $hashes[] = portal_integrity_sentence_hash($sentence);
        }
        $hashes = array_values(array_unique($hashes));

        $placeholders = implode(',', array_fill(0, count($hashes), '?'));
        $params = $hashes;
        $sql = "SELECT sentence_hash, source_type, source_id, source_label, sentence_preview
                FROM integrity_sentence_index
                WHERE sentence_hash IN ($placeholders)";
        if ($excludeSourceType !== null && $excludeSourceId !== null) {
            $sql .= ' AND NOT (source_type = ? AND source_id = ?)';
            $params[] = $excludeSourceType;
            $params[] = $excludeSourceId;
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $bySource = [];
        $matchedHashes = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $row['source_type'] . ':' . $row['source_id'];
            if (!isset($bySource[$key])) {
                $bySource[$key] = [
                    'source' => (string) $row['source_label'],
                    'type' => (string) $row['source_type'],
                    'score' => 0.0,
                    'matched_phrases' => [],
                    'snippet' => '',
                ];
            }
            $bySource[$key]['matched_phrases'][] = (string) $row['sentence_preview'];
            if ($bySource[$key]['snippet'] === '') {
                $bySource[$key]['snippet'] = (string) $row['sentence_preview'];
            }
            $matchedHashes[(string) $row['sentence_hash']] = true;
        }

        $matched = count($matchedHashes);
        $score = round(($matched / $total) * 100, 1);
        foreach ($bySource as &$source) {
            $source['score'] = $score;
            $source['method'] = 'sentence-fingerprint';
            $source['matched_phrases'] = array_slice(array_values(array_unique($source['matched_phrases'])), 0, 6);
        }
        unset($source);

        usort($bySource, static fn(array $a, array $b): int => count($b['matched_phrases']) <=> count($a['matched_phrases']));

        return [
            'score' => $score,
            'matched' => $matched,
            'total' => $total,
            'sources' => array_slice(array_values($bySource), 0, 5),
        ];
    }
}

if (!function_exists('portal_integrity_reference_sources')) {
    /**
     * @return array<int, array{label: string, text: string, type: string, date: string, id: int}>
     */
    function portal_integrity_reference_sources(): array
    {
        $dir = portal_integrity_references_dir();
        if (!is_dir($dir)) {
            return [];
        }

        $sources = [];
        $id = 0;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (str_starts_with($name, '.')) {
                continue;
            }
            $text = portal_extract_submission_text($path, $name);
            $text = portal_integrity_normalize_text($text);
            if ($text === '') {
                continue;
            }
            $id++;
            $sources[] = [
                'label' => 'Reference corpus: ' . $name,
                'text' => $text,
                'type' => 'reference corpus',
                'date' => '',
                'id' => $id,
            ];
        }
        return $sources;
    }
}

if (!function_exists('portal_integrity_parse_ooxml_datetime')) {
    function portal_integrity_parse_ooxml_datetime(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        return $ts !== false ? $ts : null;
    }
}

if (!function_exists('portal_integrity_parse_pdf_datetime')) {
    function portal_integrity_parse_pdf_datetime(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?/', $value, $m)) {
            $iso = sprintf(
                '%04d-%02d-%02d %02d:%02d:%02d',
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0,
                isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0,
                isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0
            );
            $ts = strtotime($iso);
            return $ts !== false ? $ts : null;
        }

        return portal_integrity_parse_ooxml_datetime($value);
    }
}

if (!function_exists('portal_integrity_xml_text')) {
    function portal_integrity_xml_text(?SimpleXMLElement $xml, array $paths): string
    {
        if ($xml === null) {
            return '';
        }

        foreach ($paths as $path) {
            $nodes = $xml->xpath($path);
            if (is_array($nodes) && isset($nodes[0])) {
                return trim((string) $nodes[0]);
            }
        }

        return '';
    }
}

if (!function_exists('portal_extract_docx_metadata')) {
    /**
     * @return array<string, mixed>
     */
    function portal_extract_docx_metadata(string $absPath): array
    {
        $meta = [
            'available' => false,
            'format' => 'docx',
            'author' => '',
            'last_modified_by' => '',
            'created_at' => '',
            'modified_at' => '',
            'created_at_ts' => null,
            'modified_at_ts' => null,
            'edit_time_minutes' => null,
            'word_count_meta' => null,
            'page_count' => null,
            'application' => '',
            'revision' => null,
            'filesystem_modified_at' => '',
            'filesystem_modified_at_ts' => null,
        ];

        if (!class_exists('ZipArchive') || !is_file($absPath)) {
            return $meta;
        }

        $zip = new ZipArchive();
        if ($zip->open($absPath) !== true) {
            return $meta;
        }

        $coreXml = (string) $zip->getFromName('docProps/core.xml');
        $appXml = (string) $zip->getFromName('docProps/app.xml');
        $zip->close();

        if ($coreXml !== '') {
            $core = @simplexml_load_string($coreXml);
            if ($core instanceof SimpleXMLElement) {
                $core->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $core->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
                $core->registerXPathNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

                $meta['author'] = portal_integrity_xml_text($core, [
                    '//dc:creator',
                    '//cp:creator',
                ]);
                $meta['last_modified_by'] = portal_integrity_xml_text($core, [
                    '//cp:lastModifiedBy',
                ]);
                $created = portal_integrity_xml_text($core, [
                    '//dcterms:created',
                    '//cp:created',
                ]);
                $modified = portal_integrity_xml_text($core, [
                    '//dcterms:modified',
                    '//cp:modified',
                ]);
                if ($created !== '') {
                    $meta['created_at'] = $created;
                    $meta['created_at_ts'] = portal_integrity_parse_ooxml_datetime($created);
                }
                if ($modified !== '') {
                    $meta['modified_at'] = $modified;
                    $meta['modified_at_ts'] = portal_integrity_parse_ooxml_datetime($modified);
                }
                $revision = portal_integrity_xml_text($core, ['//cp:revision']);
                if ($revision !== '' && ctype_digit($revision)) {
                    $meta['revision'] = (int) $revision;
                }
            }
        }

        if ($appXml !== '') {
            $app = @simplexml_load_string($appXml);
            if ($app instanceof SimpleXMLElement) {
                $app->registerXPathNamespace('ep', 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties');
                $totalTime = portal_integrity_xml_text($app, [
                    '//ep:TotalTime',
                    '//TotalTime',
                ]);
                if ($totalTime !== '' && is_numeric($totalTime)) {
                    $meta['edit_time_minutes'] = max(0, (int) round((float) $totalTime));
                }
                $words = portal_integrity_xml_text($app, [
                    '//ep:Words',
                    '//Words',
                ]);
                if ($words !== '' && is_numeric($words)) {
                    $meta['word_count_meta'] = max(0, (int) $words);
                }
                $pages = portal_integrity_xml_text($app, [
                    '//ep:Pages',
                    '//Pages',
                ]);
                if ($pages !== '' && is_numeric($pages)) {
                    $meta['page_count'] = max(0, (int) $pages);
                }
                $meta['application'] = portal_integrity_xml_text($app, [
                    '//ep:Application',
                    '//Application',
                ]);
            }
        }

        $mtime = @filemtime($absPath);
        if ($mtime !== false) {
            $meta['filesystem_modified_at_ts'] = $mtime;
            $meta['filesystem_modified_at'] = date('c', $mtime);
        }

        $meta['available'] = $meta['created_at'] !== ''
            || $meta['modified_at'] !== ''
            || $meta['edit_time_minutes'] !== null
            || $meta['author'] !== '';

        return $meta;
    }
}

if (!function_exists('portal_extract_pdf_metadata')) {
    /**
     * @return array<string, mixed>
     */
    function portal_extract_pdf_metadata(string $absPath): array
    {
        $meta = [
            'available' => false,
            'format' => 'pdf',
            'author' => '',
            'last_modified_by' => '',
            'created_at' => '',
            'modified_at' => '',
            'created_at_ts' => null,
            'modified_at_ts' => null,
            'edit_time_minutes' => null,
            'word_count_meta' => null,
            'page_count' => null,
            'application' => '',
            'creator' => '',
            'producer' => '',
            'filesystem_modified_at' => '',
            'filesystem_modified_at_ts' => null,
        ];

        if (!is_file($absPath)) {
            return $meta;
        }

        $raw = (string) @file_get_contents($absPath, false, null, 0, 2_000_000);
        if ($raw === '') {
            return $meta;
        }

        $readPdfString = static function (string $pattern) use ($raw): string {
            if (!preg_match($pattern, $raw, $m)) {
                return '';
            }
            $value = trim($m[1]);
            if (str_starts_with($value, '<')) {
                $hex = substr($value, 1, -1);
                if ($hex !== '' && ctype_xdigit($hex)) {
                    $value = (string) hex2bin($hex);
                }
            }
            return trim(str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $value));
        };

        $meta['author'] = $readPdfString('/\/Author\s*\(([^)]*)\)/');
        $meta['creator'] = $readPdfString('/\/Creator\s*\(([^)]*)\)/');
        $meta['producer'] = $readPdfString('/\/Producer\s*\(([^)]*)\)/');
        $meta['application'] = $meta['producer'] !== '' ? $meta['producer'] : $meta['creator'];

        $createdRaw = $readPdfString('/\/CreationDate\s*\(([^)]*)\)/');
        $modifiedRaw = $readPdfString('/\/ModDate\s*\(([^)]*)\)/');
        if ($createdRaw !== '') {
            $meta['created_at'] = $createdRaw;
            $meta['created_at_ts'] = portal_integrity_parse_pdf_datetime($createdRaw);
        }
        if ($modifiedRaw !== '') {
            $meta['modified_at'] = $modifiedRaw;
            $meta['modified_at_ts'] = portal_integrity_parse_pdf_datetime($modifiedRaw);
        }

        if (preg_match('/\/Count\s+(\d+)/', $raw, $pageMatch)) {
            $meta['page_count'] = max(0, (int) $pageMatch[1]);
        }

        $mtime = @filemtime($absPath);
        if ($mtime !== false) {
            $meta['filesystem_modified_at_ts'] = $mtime;
            $meta['filesystem_modified_at'] = date('c', $mtime);
        }

        $meta['available'] = $meta['author'] !== ''
            || $meta['created_at'] !== ''
            || $meta['modified_at'] !== ''
            || $meta['creator'] !== ''
            || $meta['producer'] !== '';

        return $meta;
    }
}

if (!function_exists('portal_extract_submission_file_metadata')) {
    /**
     * Extract author, edit time, and timestamps from uploaded submission files.
     *
     * @return array<string, mixed>
     */
    function portal_extract_submission_file_metadata(string $absPath, string $filename = ''): array
    {
        $ext = strtolower(pathinfo($filename !== '' ? $filename : $absPath, PATHINFO_EXTENSION));
        if ($ext === 'docx' || $ext === 'doc') {
            if ($ext === 'docx') {
                return portal_extract_docx_metadata($absPath);
            }
            $meta = [
                'available' => false,
                'format' => 'doc',
                'author' => '',
                'last_modified_by' => '',
                'created_at' => '',
                'modified_at' => '',
                'created_at_ts' => null,
                'modified_at_ts' => null,
                'edit_time_minutes' => null,
                'word_count_meta' => null,
                'page_count' => null,
                'application' => '',
                'filesystem_modified_at' => '',
                'filesystem_modified_at_ts' => null,
            ];
            $mtime = @filemtime($absPath);
            if ($mtime !== false) {
                $meta['filesystem_modified_at_ts'] = $mtime;
                $meta['filesystem_modified_at'] = date('c', $mtime);
                $meta['available'] = true;
            }
            return $meta;
        }

        if ($ext === 'pdf') {
            return portal_extract_pdf_metadata($absPath);
        }

        $meta = [
            'available' => false,
            'format' => $ext !== '' ? $ext : 'unknown',
            'author' => '',
            'last_modified_by' => '',
            'created_at' => '',
            'modified_at' => '',
            'created_at_ts' => null,
            'modified_at_ts' => null,
            'edit_time_minutes' => null,
            'word_count_meta' => null,
            'page_count' => null,
            'application' => '',
            'filesystem_modified_at' => '',
            'filesystem_modified_at_ts' => null,
        ];
        $mtime = @filemtime($absPath);
        if ($mtime !== false) {
            $meta['filesystem_modified_at_ts'] = $mtime;
            $meta['filesystem_modified_at'] = date('c', $mtime);
            $meta['available'] = true;
        }

        return $meta;
    }
}

if (!function_exists('portal_integrity_process_review')) {
    /**
     * @return array{score: float, level: string, signals: string[]}
     */
    function portal_integrity_process_review(array $submission, string $text): array
    {
        $wordCount = max(1, count(portal_integrity_words($text)));
        $editSeconds = max(0, (int) ($submission['process_edit_seconds'] ?? 0));
        $pasteEvents = max(0, (int) ($submission['process_paste_events'] ?? 0));
        $pastedChars = max(0, (int) ($submission['process_pasted_chars'] ?? 0));
        $charCount = max(1, mb_strlen($text));
        $fileMeta = is_array($submission['file_metadata'] ?? null) ? $submission['file_metadata'] : null;

        $signals = [];
        $score = 0.0;
        $fileEditMinutes = null;

        if ($fileMeta && ($fileMeta['available'] ?? false)) {
            if ($fileMeta['edit_time_minutes'] !== null) {
                $fileEditMinutes = max(0, (int) $fileMeta['edit_time_minutes']);
                $fileEditSeconds = $fileEditMinutes * 60;
                if ($fileEditSeconds > $editSeconds) {
                    $editSeconds = $fileEditSeconds;
                }
            }

            if ($fileMeta['created_at_ts'] !== null && $fileMeta['modified_at_ts'] !== null) {
                $metaGap = max(0, (int) $fileMeta['modified_at_ts'] - (int) $fileMeta['created_at_ts']);
                if ($wordCount >= 500 && $metaGap < 120) {
                    $score += 22;
                    $signals[] = 'File metadata shows the document was created and last saved within 2 minutes despite ' . $wordCount . ' words.';
                } elseif ($wordCount >= 200 && $metaGap < 60) {
                    $score += 14;
                    $signals[] = 'File metadata shows almost no gap between document creation and last save.';
                }
            }

            if ($fileMeta['word_count_meta'] !== null && $wordCount >= 100) {
                $metaWords = max(0, (int) $fileMeta['word_count_meta']);
                $wordDelta = abs($metaWords - $wordCount);
                if ($wordDelta / $wordCount >= 0.35) {
                    $score += 10;
                    $signals[] = 'Embedded file word count (' . $metaWords . ') differs significantly from extracted text (' . $wordCount . ' words).';
                }
            }

            if ($fileEditMinutes !== null) {
                if ($wordCount >= 300 && $fileEditMinutes < 3) {
                    $score += 32;
                    $signals[] = 'Office metadata reports only ' . $fileEditMinutes . ' minute(s) of editing for ' . $wordCount . ' words.';
                } elseif ($wordCount >= 1500 && $fileEditMinutes < 10) {
                    $score += 20;
                    $signals[] = 'Office metadata reports ' . $fileEditMinutes . ' minutes of editing for a long document (' . $wordCount . ' words).';
                } elseif ($fileEditMinutes >= 20 && $pasteEvents === 0) {
                    $signals[] = 'Office metadata reports ' . $fileEditMinutes . ' minutes of editing time.';
                }
            } elseif (($fileMeta['format'] ?? '') === 'pdf'
                && $fileMeta['created_at_ts'] !== null
                && $fileMeta['modified_at_ts'] !== null) {
                $pdfGap = max(0, (int) $fileMeta['modified_at_ts'] - (int) $fileMeta['created_at_ts']);
                if ($wordCount >= 400 && $pdfGap < 120) {
                    $score += 16;
                    $signals[] = 'PDF metadata shows creation and modification within 2 minutes for a long document.';
                }
            }

            if (($fileMeta['author'] ?? '') !== '') {
                $signals[] = 'Document author in file metadata: ' . (string) $fileMeta['author'] . '.';
            }
            if (($fileMeta['last_modified_by'] ?? '') !== ''
                && ($fileMeta['last_modified_by'] ?? '') !== ($fileMeta['author'] ?? '')) {
                $signals[] = 'Last modified by: ' . (string) $fileMeta['last_modified_by'] . '.';
            }
            if (($fileMeta['application'] ?? '') !== '') {
                $signals[] = 'Created/edited with: ' . (string) $fileMeta['application'] . '.';
            }
        }

        $pasteRatio = min(100.0, ($pastedChars / $charCount) * 100);
        if ($pasteRatio >= 45) {
            $score += 35;
            $signals[] = 'High pasted content (' . round($pasteRatio, 1) . '% of characters).';
        } elseif ($pasteRatio >= 20) {
            $score += 18;
            $signals[] = 'Moderate pasted content (' . round($pasteRatio, 1) . '% of characters).';
        }

        if ($pasteEvents >= 8) {
            $score += 20;
            $signals[] = 'Many paste events recorded (' . $pasteEvents . ').';
        } elseif ($pasteEvents >= 3) {
            $score += 10;
            $signals[] = 'Several paste events recorded (' . $pasteEvents . ').';
        }

        $wordsPerMinute = $editSeconds > 0 ? ($wordCount / max(1, $editSeconds / 60)) : $wordCount;
        if ($wordCount >= 300 && $editSeconds > 0 && $editSeconds < 180) {
            $score += 25;
            $signals[] = 'Very short editing window for a long submission (' . $editSeconds . ' seconds).';
        } elseif ($wordCount >= 150 && $wordsPerMinute > 250) {
            $score += 15;
            $signals[] = 'Unusually fast writing speed for the word count.';
        }

        if ($editSeconds >= 600 && $pasteEvents === 0) {
            $signals[] = 'Healthy editing time with no paste events.';
        }

        $score = min(100.0, round($score, 1));
        return [
            'score' => $score,
            'level' => portal_integrity_level($score),
            'signals' => $signals !== [] ? $signals : ['No unusual writing-process signals detected.'],
            'file_metadata' => $fileMeta,
            'effective_edit_seconds' => $editSeconds,
            'file_edit_minutes' => $fileEditMinutes,
        ];
    }
}

if (!function_exists('portal_integrity_heuristic_ai_review')) {
    /**
     * Lightweight statistical review — not a neural AI detector.
     *
     * @return array{score: float, level: string, signals: string[]}
     */
    function portal_integrity_heuristic_ai_review(string $text): array
    {
        $sentences = portal_integrity_sentences($text, 30);
        $words = portal_integrity_words($text);
        $wordCount = count($words);
        if ($wordCount < 80 || count($sentences) < 5) {
            return [
                'score' => 0.0,
                'level' => 'low',
                'signals' => ['Not enough text for a heuristic AI-style review.'],
            ];
        }

        $signals = [];
        $score = 0.0;

        $lengths = array_map(static fn(string $s): int => mb_strlen($s), $sentences);
        $avg = array_sum($lengths) / count($lengths);
        $variance = 0.0;
        foreach ($lengths as $len) {
            $variance += ($len - $avg) ** 2;
        }
        $variance /= count($lengths);
        $stdDev = sqrt($variance);
        $cv = $avg > 0 ? $stdDev / $avg : 0.0;
        if ($cv < 0.28) {
            $score += 22;
            $signals[] = 'Sentence lengths are unusually uniform (common in generated text).';
        }

        $uniqueRatio = count(array_unique($words)) / $wordCount;
        if ($uniqueRatio < 0.38) {
            $score += 18;
            $signals[] = 'Vocabulary diversity is lower than typical for human academic writing.';
        } elseif ($uniqueRatio > 0.72) {
            $score -= 5;
        }

        $transitionCount = 0;
        $transitions = ['furthermore', 'moreover', 'however', 'therefore', 'additionally', 'consequently', 'in conclusion', 'it is important to note'];
        $lower = strtolower($text);
        foreach ($transitions as $transition) {
            if (str_contains($lower, $transition)) {
                $transitionCount++;
            }
        }
        if ($transitionCount >= 5) {
            $score += 12;
            $signals[] = 'Frequent template-style transition phrases detected.';
        }

        $commaDensity = substr_count($lower, ',') / max(1, $wordCount);
        if ($commaDensity > 0.14) {
            $score += 8;
            $signals[] = 'Comma density is high, which can indicate list-like generated prose.';
        }

        $score = max(0.0, min(100.0, round($score, 1)));
        if ($signals === []) {
            $signals[] = 'Writing style looks within a typical human range for this heuristic check.';
        }

        return [
            'score' => $score,
            'level' => portal_integrity_level($score),
            'signals' => $signals,
        ];
    }
}

if (!function_exists('portal_zero_gpt_api_key')) {
    function portal_zero_gpt_api_key(): string
    {
        return trim((string) getenv('ZEROGPT_API_KEY'));
    }
}

if (!function_exists('portal_zero_gpt_request')) {
    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, http: int, body: string, error: string}
     */
    function portal_zero_gpt_request(string $url, array $payload): array
    {
        $apiKey = portal_zero_gpt_api_key();
        if ($apiKey === '') {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'ZEROGPT_API_KEY is not configured.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'PHP cURL is not enabled.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiKey,
                'ApiKey: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = (string) curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== '' && $http < 400,
            'http' => $http,
            'body' => $body,
            'error' => $err,
        ];
    }
}

if (!function_exists('portal_zero_gpt_pick_numeric')) {
    function portal_zero_gpt_pick_numeric(array $data, array $paths): ?float
    {
        foreach ($paths as $path) {
            $cursor = $data;
            foreach ($path as $key) {
                if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                    $cursor = null;
                    break;
                }
                $cursor = $cursor[$key];
            }
            if (is_numeric($cursor)) {
                return (float) $cursor;
            }
        }
        return null;
    }
}

if (!function_exists('portal_zero_gpt_detection')) {
    function portal_zero_gpt_detection(string $text): array
    {
        if (portal_zero_gpt_api_key() === '') {
            return ['status' => 'not_configured', 'score' => null, 'report' => 'AI detection needs ZEROGPT_API_KEY in a .env file at the project root.'];
        }
        if ($text === '') {
            return ['status' => 'no_text', 'score' => null, 'report' => 'No readable text could be extracted from this submission for AI detection.'];
        }

        $limitedText = function_exists('mb_substr') ? mb_substr($text, 0, 15000) : substr($text, 0, 15000);
        $endpoints = [
            'https://api.zerogpt.org/api/v1/developer/ai-detection',
            'https://api.zerogpt.org/api/v1/developer/detect',
        ];

        $lastError = 'ZeroGPT AI detection failed.';
        foreach ($endpoints as $endpoint) {
            $response = portal_zero_gpt_request($endpoint, [
                'text' => $limitedText,
                'include_sentence_analysis' => true,
            ]);
            if (!$response['ok']) {
                $lastError = $response['error'] !== '' ? $response['error'] : 'ZeroGPT returned HTTP ' . $response['http'] . '.';
                continue;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                $lastError = 'ZeroGPT returned an unreadable response.';
                continue;
            }

            $score = portal_zero_gpt_pick_numeric($data, [
                ['data', 'ai_percentage'],
                ['data', 'aiPercentage'],
                ['data', 'fakePercentage'],
                ['data', 'is_gpt_generated'],
                ['data', 'isAI'],
                ['fakePercentage'],
                ['aiPercentage'],
                ['score'],
            ]);

            if ($score !== null) {
                return [
                    'status' => 'checked',
                    'score' => $score,
                    'report' => function_exists('mb_substr') ? mb_substr($response['body'], 0, 4000) : substr($response['body'], 0, 4000),
                ];
            }

            $lastError = 'ZeroGPT response did not include an AI score.';
        }

        return ['status' => 'error', 'score' => null, 'report' => $lastError];
    }
}

if (!function_exists('portal_zero_gpt_plagiarism')) {
    /**
     * @return array{status: string, score: ?float, report: string, matches: array<int, array<string, mixed>>}
     */
    function portal_zero_gpt_plagiarism(string $text): array
    {
        if (portal_zero_gpt_api_key() === '') {
            return ['status' => 'not_configured', 'score' => null, 'report' => '', 'matches' => []];
        }
        if ($text === '') {
            return ['status' => 'no_text', 'score' => null, 'report' => '', 'matches' => []];
        }

        $limitedText = function_exists('mb_substr') ? mb_substr($text, 0, 20000) : substr($text, 0, 20000);
        $endpoints = [
            'https://api.zerogpt.org/api/v1/developer/plagiarism',
            'https://api.zerogpt.com/api/detect/detectPlagiarism',
        ];

        foreach ($endpoints as $endpoint) {
            $payload = str_contains($endpoint, 'detectPlagiarism')
                ? ['input_text' => $limitedText]
                : ['text' => $limitedText];

            $response = portal_zero_gpt_request($endpoint, $payload);
            if (!$response['ok']) {
                continue;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                continue;
            }

            $score = portal_zero_gpt_pick_numeric($data, [
                ['data', 'plagiarism_percentage'],
                ['data', 'plagiarismPercentage'],
                ['data', 'percent_plagiarized'],
                ['plagiarism_percentage'],
                ['plagiarismPercentage'],
                ['score'],
            ]);

            $matches = [];
            $rawMatches = $data['data']['matches'] ?? $data['data']['sources'] ?? $data['matches'] ?? [];
            if (is_array($rawMatches)) {
                foreach (array_slice($rawMatches, 0, 5) as $match) {
                    if (!is_array($match)) {
                        continue;
                    }
                    $matches[] = [
                        'source' => (string) ($match['url'] ?? $match['source'] ?? $match['title'] ?? 'Web source'),
                        'type' => 'web source',
                        'score' => isset($match['score']) && is_numeric($match['score']) ? (float) $match['score'] : ($score ?? 0.0),
                        'matched_phrases' => [],
                        'snippet' => (string) ($match['snippet'] ?? $match['text'] ?? ''),
                    ];
                }
            }

            if ($score !== null || $matches !== []) {
                return [
                    'status' => 'checked',
                    'score' => $score,
                    'report' => function_exists('mb_substr') ? mb_substr($response['body'], 0, 4000) : substr($response['body'], 0, 4000),
                    'matches' => $matches,
                ];
            }
        }

        return ['status' => 'not_configured', 'score' => null, 'report' => '', 'matches' => []];
    }
}

if (!function_exists('portal_integrity_check_similarity')) {
    function portal_integrity_check_similarity(
        PDO $db,
        string $text,
        int $courseId,
        int $itemId,
        int $userId,
        string $fileHash = '',
        ?int $submissionId = null,
        ?array $submissionContext = null
    ): array {
        $cleanText = portal_integrity_normalize_text($text);
        $words = portal_integrity_words($cleanText);
        $wordCount = count($words);

        if ($wordCount < 25) {
            return [
                'status' => 'no_text',
                'score' => null,
                'word_count' => $wordCount,
                'report' => json_encode([
                    'engine' => 'native_institutional_v3',
                    'status' => 'no_text',
                    'score' => null,
                    'level' => 'pending',
                    'summary' => 'Not enough readable text was available to produce a similarity report. Try DOCX or TXT, or install LibreOffice for better PDF/DOC extraction.',
                    'scope' => [
                        'institutional_database' => 'checked',
                        'global_web' => portal_zero_gpt_api_key() !== '' ? 'pending' : 'not_configured',
                        'academic_journals' => 'not_configured',
                    ],
                    'matches' => [],
                    'highlights' => [],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        $sources = [];

        if ($fileHash !== '') {
            $hashStmt = $db->prepare(
                "SELECT cs.filename, u.name AS student_name, c.full_title AS course_title, cfi.title AS slot_title
                 FROM course_submissions cs
                 JOIN users u ON u.id = cs.user_id
                 JOIN courses c ON c.id = cs.course_id
                 JOIN course_folder_items cfi ON cfi.id = cs.item_id
                 WHERE cs.file_sha256 = ?
                   AND NOT (cs.item_id = ? AND cs.user_id = ?)
                 LIMIT 1"
            );
            $hashStmt->execute([$fileHash, $itemId, $userId]);
            $hashRow = $hashStmt->fetch();
            if ($hashRow) {
                return [
                    'status' => 'checked',
                    'score' => 100.0,
                    'word_count' => $wordCount,
                    'report' => json_encode([
                        'engine' => 'native_institutional_v3',
                        'status' => 'checked',
                        'score' => 100.0,
                        'level' => 'high',
                        'summary' => 'This file is an exact duplicate of another submission in the institutional database.',
                        'scope' => [
                            'institutional_database' => 'checked',
                            'global_web' => 'skipped',
                            'academic_journals' => 'not_configured',
                        ],
                        'matches' => [[
                            'source' => trim($hashRow['student_name'] . ' - ' . $hashRow['slot_title'] . ' (' . $hashRow['course_title'] . ')'),
                            'type' => 'exact file duplicate',
                            'score' => 100.0,
                            'matched_phrases' => ['identical file hash'],
                            'snippet' => 'Matched file: ' . $hashRow['filename'],
                        ]],
                        'highlights' => ['identical file hash'],
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $stmt = $db->prepare(
            "SELECT cs.id, cs.submission_text, cs.filename, cs.submitted_at,
                    u.name AS student_name, c.full_title AS course_title, cfi.title AS slot_title
             FROM course_submissions cs
             JOIN users u ON u.id = cs.user_id
             JOIN courses c ON c.id = cs.course_id
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             WHERE cs.submission_text != ''
               AND NOT (cs.item_id = ? AND cs.user_id = ?)
             ORDER BY cs.submitted_at DESC
             LIMIT 120"
        );
        $stmt->execute([$itemId, $userId]);
        foreach ($stmt->fetchAll() as $source) {
            $label = trim($source['student_name'] . ' - ' . $source['slot_title'] . ' (' . $source['course_title'] . ')');
            portal_integrity_index_document(
                $db,
                (string) $source['submission_text'],
                'submission',
                (int) $source['id'],
                $label,
                $courseId
            );
            $sources[] = [
                'label' => $label,
                'text' => (string) $source['submission_text'],
                'type' => 'institutional submission',
                'date' => (string) $source['submitted_at'],
            ];
        }

        $materialStmt = $db->prepare(
            "SELECT cfi.id, cfi.title, cfi.description, cfi.file_path, cfi.file_name, c.full_title AS course_title
             FROM course_folder_items cfi
             JOIN courses c ON c.id = cfi.course_id
             WHERE cfi.type IN ('document', 'submission')
               AND (cfi.description != '' OR cfi.file_path != '')
             ORDER BY cfi.created_at DESC
             LIMIT 60"
        );
        $materialStmt->execute();
        foreach ($materialStmt->fetchAll() as $material) {
            $materialText = (string) $material['description'];
            if ($material['file_path'] !== '') {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $material['file_path']);
                if (is_file($abs)) {
                    $materialText .= "\n" . portal_extract_submission_text($abs, (string) $material['file_name']);
                }
            }
            $materialText = portal_integrity_normalize_text($materialText);
            if ($materialText !== '') {
                $label = trim($material['title'] . ' (' . $material['course_title'] . ')');
                portal_integrity_index_document(
                    $db,
                    $materialText,
                    'material',
                    (int) $material['id'],
                    $label,
                    $courseId
                );
                $sources[] = [
                    'label' => $label,
                    'text' => $materialText,
                    'type' => 'institutional material',
                    'date' => '',
                ];
            }
        }

        foreach (portal_integrity_reference_sources() as $reference) {
            $sources[] = [
                'label' => $reference['label'],
                'text' => $reference['text'],
                'type' => $reference['type'],
                'date' => '',
            ];
            portal_integrity_index_document(
                $db,
                $reference['text'],
                'reference',
                (int) $reference['id'],
                $reference['label'],
                null
            );
        }

        $matches = [];
        $allMatchedPhrases = [];
        foreach ($sources as $source) {
            $pair = portal_integrity_pair_score($cleanText, $source['text']);
            if ($pair['score'] <= 0) {
                continue;
            }

            $allMatchedPhrases = array_merge($allMatchedPhrases, $pair['phrases']);
            $matches[] = [
                'source' => $source['label'],
                'type' => $source['type'],
                'score' => $pair['score'],
                'method' => $pair['method'],
                'matched_phrases' => $pair['phrases'],
                'snippet' => substr(portal_integrity_normalize_text($source['text']), 0, 260),
            ];
        }

        usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        $institutionalMatches = array_slice($matches, 0, 5);
        $score = !empty($institutionalMatches) ? (float) $institutionalMatches[0]['score'] : 0.0;

        $fingerprint = portal_integrity_fingerprint_matches(
            $db,
            $cleanText,
            $submissionId !== null ? 'submission' : null,
            $submissionId
        );
        if ($fingerprint['score'] > $score) {
            $score = (float) $fingerprint['score'];
        }
        if (!empty($fingerprint['sources'])) {
            $institutionalMatches = array_merge($fingerprint['sources'], $institutionalMatches);
            usort($institutionalMatches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $institutionalMatches = array_slice($institutionalMatches, 0, 6);
        }

        $webScope = 'not_configured';
        $webMatches = [];
        $web = portal_zero_gpt_plagiarism($cleanText);
        if ($web['status'] === 'checked' && ($web['score'] !== null || $web['matches'] !== [])) {
            $webScope = 'checked';
            $webMatches = $web['matches'];
            if ($web['score'] !== null && (float) $web['score'] > $score) {
                $score = (float) $web['score'];
            }
            $matches = array_merge($webMatches, $institutionalMatches);
            usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $matches = array_slice($matches, 0, 6);
        } elseif (portal_zero_gpt_api_key() !== '') {
            $webScope = 'unavailable';
        }

        $highlights = array_values(array_unique(array_slice($allMatchedPhrases, 0, 16)));
        $level = portal_integrity_level($score);
        $summary = empty($institutionalMatches) && empty($webMatches)
            ? 'No meaningful overlap was found in the institutional database.'
            : ($webMatches !== []
                ? 'Overlap was found against institutional sources and/or the web.'
                : 'Potential overlap was found against institutional sources.');

        $processReview = $submissionContext !== null
            ? portal_integrity_process_review($submissionContext, $cleanText)
            : null;
        $heuristicAi = portal_integrity_heuristic_ai_review($cleanText);
        $fileMetadata = is_array($submissionContext['file_metadata'] ?? null)
            ? $submissionContext['file_metadata']
            : (is_array($processReview['file_metadata'] ?? null) ? $processReview['file_metadata'] : null);

        return [
            'status' => 'checked',
            'score' => $score,
            'word_count' => $wordCount,
            'report' => json_encode([
                'engine' => 'native_institutional_v3',
                'status' => 'checked',
                'score' => $score,
                'level' => $level,
                'summary' => $summary,
                'scope' => [
                    'institutional_database' => 'checked',
                    'global_web' => $webScope,
                    'academic_journals' => 'not_configured',
                    'reference_corpus' => is_dir(portal_integrity_references_dir()) ? 'checked' : 'empty',
                ],
                'matches' => $matches,
                'highlights' => $highlights,
                'fingerprint' => [
                    'matched_sentences' => $fingerprint['matched'],
                    'total_sentences' => $fingerprint['total'],
                    'score' => $fingerprint['score'],
                ],
                'file_metadata' => $fileMetadata,
                'process_review' => $processReview,
                'heuristic_ai' => $heuristicAi,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}

if (!function_exists('portal_integrity_report_data')) {
    function portal_integrity_report_data(array $submission): array
    {
        $raw = (string) ($submission['similarity_report'] ?? '');
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $score = isset($submission['similarity_score']) && $submission['similarity_score'] !== null
            ? (float) $submission['similarity_score']
            : null;
        return [
            'status' => (string) ($submission['similarity_status'] ?? ''),
            'score' => $score,
            'level' => portal_integrity_level($score),
            'summary' => 'No originality report is available yet.',
            'matches' => [],
            'highlights' => [],
            'scope' => [
                'institutional_database' => 'pending',
                'global_web' => 'not_configured',
                'academic_journals' => 'not_configured',
            ],
        ];
    }
}

if (!function_exists('portal_integrity_student_summary')) {
    function portal_integrity_student_summary(?float $score): string
    {
        if ($score === null) {
            return 'Your similarity check is still being processed.';
        }
        if ($score < 15) {
            return 'No significant overlap was found with other sources.';
        }
        if ($score < 40) {
            return 'Some overlap was found with other sources. Your teacher can review the full report.';
        }

        return 'A high level of overlap was found. Your teacher can review the full report.';
    }
}

if (!function_exists('portal_render_integrity_report')) {
    function portal_render_integrity_report(array $submission, bool $showAi): string
    {
        $report = portal_integrity_report_data($submission);
        $score = isset($report['score']) && $report['score'] !== null ? (float) $report['score'] : null;
        $level = portal_integrity_level($score);
        $matches = is_array($report['matches'] ?? null) ? $report['matches'] : [];
        $highlights = is_array($report['highlights'] ?? null) ? $report['highlights'] : [];
        $scope = is_array($report['scope'] ?? null) ? $report['scope'] : [];
        $receipt = (string) ($submission['receipt_number'] ?? '');
        $wordCount = (int) ($submission['text_word_count'] ?? 0);
        $checkedAt = (string) ($submission['similarity_checked_at'] ?? '');
        $fingerprint = is_array($report['fingerprint'] ?? null) ? $report['fingerprint'] : [];
        $processReview = is_array($report['process_review'] ?? null) ? $report['process_review'] : null;
        $heuristicAi = is_array($report['heuristic_ai'] ?? null) ? $report['heuristic_ai'] : null;
        $fileMetadata = is_array($report['file_metadata'] ?? null) ? $report['file_metadata'] : null;

        ob_start();

        if (!$showAi):
        ?>
        <div class="integrity-report integrity-report--student integrity-report--<?= portal_escape($level) ?>">
            <div class="integrity-student-hero">
                <div class="integrity-student-score">
                    <strong><?= $score !== null ? portal_escape((string) round($score, 1)) . '%' : '--' ?></strong>
                    <span>Similarity</span>
                </div>
                <p class="integrity-student-message"><?= portal_escape(portal_integrity_student_summary($score)) ?></p>
            </div>
        </div>
        <?php
            return trim((string) ob_get_clean());
        endif;
        ?>
        <div class="integrity-report integrity-report--<?= portal_escape($level) ?>">
            <div class="integrity-report-head">
                <div>
                    <p class="eyebrow">Originality receipt</p>
                    <h4><?= $receipt !== '' ? portal_escape($receipt) : 'Receipt pending' ?></h4>
                    <p>
                        <?= portal_escape((string) ($report['summary'] ?? 'No originality report is available yet.')) ?>
                        <?php if ($checkedAt !== ''): ?>
                            <span>Checked <?= portal_escape(date('j M Y H:i', strtotime($checkedAt))) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="integrity-score">
                    <strong><?= $score !== null ? portal_escape((string) round($score, 1)) . '%' : '--' ?></strong>
                    <span>Similarity</span>
                </div>
            </div>

            <div class="integrity-meter" aria-hidden="true">
                <span style="width: <?= $score !== null ? min(100, max(0, (float) $score)) : 0 ?>%;"></span>
            </div>

            <div class="integrity-meta-grid">
                <span>Words checked: <strong><?= $wordCount ?></strong></span>
                <span>School submissions: <strong><?= portal_escape((string) ($scope['institutional_database'] ?? 'pending')) ?></strong></span>
                <span>Reference corpus: <strong><?= portal_escape((string) ($scope['reference_corpus'] ?? 'empty')) ?></strong></span>
                <span>Web sources: <strong><?= portal_escape((string) ($scope['global_web'] ?? 'not_configured')) ?></strong></span>
            </div>

            <?php if (!empty($fingerprint['total_sentences'])): ?>
                <div class="integrity-fingerprint-panel">
                    <strong>Sentence fingerprint index</strong>
                    <span>
                        <?= (int) ($fingerprint['matched_sentences'] ?? 0) ?> of <?= (int) $fingerprint['total_sentences'] ?> sentences matched elsewhere
                        <?php if (($fingerprint['score'] ?? null) !== null): ?>
                            &middot; <?= portal_escape((string) round((float) $fingerprint['score'], 1)) ?>%
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($fileMetadata !== null && !empty($fileMetadata['available'])): ?>
                <div class="integrity-filemeta-panel">
                    <strong>File metadata</strong>
                    <div class="integrity-meta-grid integrity-meta-grid--file">
                        <?php if (($fileMetadata['format'] ?? '') !== ''): ?>
                            <span>Format: <strong><?= portal_escape(strtoupper((string) $fileMetadata['format'])) ?></strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['author'] ?? '') !== ''): ?>
                            <span>Author: <strong><?= portal_escape((string) $fileMetadata['author']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['edit_time_minutes'] ?? null) !== null): ?>
                            <span>Editing time: <strong><?= (int) $fileMetadata['edit_time_minutes'] ?> min</strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['word_count_meta'] ?? null) !== null): ?>
                            <span>Words in file: <strong><?= (int) $fileMetadata['word_count_meta'] ?></strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['created_at'] ?? '') !== ''): ?>
                            <span>Created: <strong><?= portal_escape((string) $fileMetadata['created_at']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['modified_at'] ?? '') !== ''): ?>
                            <span>Last saved: <strong><?= portal_escape((string) $fileMetadata['modified_at']) ?></strong></span>
                        <?php endif; ?>
                        <?php if (($fileMetadata['application'] ?? '') !== ''): ?>
                            <span>Application: <strong><?= portal_escape((string) $fileMetadata['application']) ?></strong></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($processReview !== null): ?>
                <div class="integrity-process-panel integrity-report--<?= portal_escape((string) ($processReview['level'] ?? 'low')) ?>">
                    <strong>Writing process review</strong>
                    <span><?= portal_escape((string) round((float) ($processReview['score'] ?? 0), 1)) ?>% review score</span>
                    <ul class="integrity-signal-list">
                        <?php foreach (($processReview['signals'] ?? []) as $signal): ?>
                            <li><?= portal_escape((string) $signal) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($heuristicAi !== null): ?>
                <div class="integrity-heuristic-panel integrity-report--<?= portal_escape((string) ($heuristicAi['level'] ?? 'low')) ?>">
                    <strong>Heuristic AI-style review</strong>
                    <span><?= portal_escape((string) round((float) ($heuristicAi['score'] ?? 0), 1)) ?>% — statistical only, not proof of AI use</span>
                    <ul class="integrity-signal-list">
                        <?php foreach (($heuristicAi['signals'] ?? []) as $signal): ?>
                            <li><?= portal_escape((string) $signal) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($matches)): ?>
                <div class="integrity-matches">
                    <?php foreach ($matches as $match): ?>
                        <article class="integrity-match">
                            <strong><?= portal_escape((string) ($match['source'] ?? 'Institutional source')) ?></strong>
                            <span>
                                <?= portal_escape((string) ($match['type'] ?? 'source')) ?>
                                &middot; <?= portal_escape((string) round((float) ($match['score'] ?? 0), 1)) ?>% overlap
                                <?php if (!empty($match['method'])): ?>
                                    &middot; <?= portal_escape((string) $match['method']) ?>
                                <?php endif; ?>
                            </span>
                            <?php if (($match['snippet'] ?? '') !== ''): ?>
                                <p><?= portal_escape((string) $match['snippet']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($highlights)): ?>
                <div class="integrity-highlights" aria-label="Highlighted overlapping phrases">
                    <?php foreach (array_slice($highlights, 0, 10) as $phrase): ?>
                        <mark><?= portal_escape((string) $phrase) ?></mark>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($showAi): ?>
                <div class="integrity-ai-panel">
                    <strong>External AI detection (ZeroGPT)</strong>
                    <span>
                        <?= portal_escape((string) ($submission['ai_status'] ?? 'not checked')) ?>
                        <?php if (($submission['ai_score'] ?? null) !== null): ?>
                            &middot; <?= portal_escape((string) round((float) $submission['ai_score'], 1)) ?>% AI-generated
                        <?php endif; ?>
                    </span>
                    <?php if (($submission['ai_report'] ?? '') !== ''): ?>
                        <details>
                            <summary>Raw AI report</summary>
                            <pre><?= portal_escape((string) $submission['ai_report']) ?></pre>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return trim((string) ob_get_clean());
    }
}

if (!function_exists('portal_rerun_submission_integrity')) {
    function portal_rerun_submission_integrity(PDO $db, int $submissionId, bool $runAi = true): bool
    {
        $stmt = $db->prepare(
            "SELECT cs.*, cfi.submission_ai_detection
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             WHERE cs.id = ?"
        );
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch();
        if (!$submission) {
            return false;
        }

        $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $submission['filepath']);
        $text = (string) ($submission['submission_text'] ?? '');
        if (is_file($abs)) {
            $extracted = portal_extract_submission_text($abs, (string) $submission['filename']);
            if (mb_strlen($extracted) > mb_strlen($text)) {
                $text = $extracted;
            }
        }
        $text = portal_integrity_normalize_text($text);
        $fileHash = is_file($abs) ? hash_file('sha256', $abs) : (string) ($submission['file_sha256'] ?? '');
        if (is_file($abs)) {
            $submission['file_metadata'] = portal_extract_submission_file_metadata($abs, (string) $submission['filename']);
        }

        $similarity = portal_integrity_check_similarity(
            $db,
            $text,
            (int) $submission['course_id'],
            (int) $submission['item_id'],
            (int) $submission['user_id'],
            $fileHash,
            $submissionId,
            $submission
        );

        portal_integrity_index_document(
            $db,
            $text,
            'submission',
            $submissionId,
            'Submission #' . $submissionId,
            (int) $submission['course_id']
        );

        $db->prepare(
            "UPDATE course_submissions
             SET submission_text = ?, text_word_count = ?, file_sha256 = ?,
                 similarity_status = ?, similarity_score = ?, similarity_report = ?,
                 similarity_checked_at = datetime('now')
             WHERE id = ?"
        )->execute([
            $text,
            (int) $similarity['word_count'],
            $fileHash,
            $similarity['status'],
            $similarity['score'],
            $similarity['report'],
            $submissionId,
        ]);

        if ($runAi && (!empty($submission['submission_ai_detection']) || portal_zero_gpt_api_key() !== '')) {
            $ai = portal_zero_gpt_detection($text);
            $db->prepare(
                "UPDATE course_submissions
                 SET ai_status = ?, ai_score = ?, ai_report = ?, ai_checked_at = datetime('now')
                 WHERE id = ?"
            )->execute([
                $ai['status'],
                $ai['score'],
                $ai['report'],
                $submissionId,
            ]);
        }

        return true;
    }
}
