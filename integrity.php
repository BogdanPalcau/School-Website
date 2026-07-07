<?php
declare(strict_types=1);

/**
 * Academic integrity helpers: text extraction, similarity, and optional GPTZero checks.
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

if (!function_exists('portal_submission_image_extensions')) {
    function portal_submission_image_extensions(): array
    {
        return ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    }
}

if (!function_exists('portal_submission_is_image')) {
    function portal_submission_is_image(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, portal_submission_image_extensions(), true);
    }
}

if (!function_exists('portal_supported_submission_extensions')) {
    function portal_supported_submission_extensions(): array
    {
        return array_merge(['doc', 'docx', 'pdf', 'txt'], portal_submission_image_extensions());
    }
}

if (!function_exists('portal_supported_submission_hint')) {
    function portal_supported_submission_hint(): string
    {
        return 'DOC, DOCX, PDF, TXT, or an image (PNG, JPG)';
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

if (!function_exists('portal_submission_min_words')) {
    /** Minimum word count for a text-based submission to be considered substantive. */
    function portal_submission_min_words(): int
    {
        return 20;
    }
}

if (!function_exists('portal_submission_min_chars')) {
    /** Minimum character count (after normalising) for a text-based submission. */
    function portal_submission_min_chars(): int
    {
        return 60;
    }
}

if (!function_exists('portal_integrity_stopwords')) {
    /** @return array<string, true> */
    function portal_integrity_stopwords(): array
    {
        static $stopwords = null;
        if ($stopwords !== null) {
            return $stopwords;
        }
        $list = [
            'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are',
            "aren't", 'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both',
            'but', 'by', "can't", 'cannot', 'could', "couldn't", 'did', "didn't", 'do', 'does', "doesn't",
            'doing', "don't", 'down', 'during', 'each', 'few', 'for', 'from', 'further', 'had', "hadn't",
            'has', "hasn't", 'have', "haven't", 'having', 'he', "he'd", "he'll", "he's", 'her', 'here',
            "here's", 'hers', 'herself', 'him', 'himself', 'his', 'how', "how's", 'i', "i'd", "i'll", "i'm",
            "i've", 'if', 'in', 'into', 'is', "isn't", 'it', "it's", 'its', 'itself', "let's", 'me', 'more',
            'most', "mustn't", 'my', 'myself', 'no', 'nor', 'not', 'of', 'off', 'on', 'once', 'only', 'or',
            'other', 'ought', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'same', "shan't", 'she',
            "she'd", "she'll", "she's", 'should', "shouldn't", 'so', 'some', 'such', 'than', 'that', "that's",
            'the', 'their', 'theirs', 'them', 'themselves', 'then', 'there', "there's", 'these', 'they',
            "they'd", "they'll", "they're", "they've", 'this', 'those', 'through', 'to', 'too', 'under',
            'until', 'up', 'very', 'was', "wasn't", 'we', "we'd", "we'll", "we're", "we've", 'were',
            "weren't", 'what', "what's", 'when', "when's", 'where', "where's", 'which', 'while', 'who',
            "who's", 'whom', 'why', "why's", 'with', "won't", 'would', "wouldn't", 'you', "you'd", "you'll",
            "you're", "you've", 'your', 'yours', 'yourself', 'yourselves',
        ];
        $stopwords = array_fill_keys($list, true);
        return $stopwords;
    }
}

if (!function_exists('portal_integrity_content_words')) {
    /**
     * Words with stopwords removed — used for meaning-based overlap so common
     * function words ("the", "and", "is") do not inflate similarity scores.
     *
     * @return string[]
     */
    function portal_integrity_content_words(string $text): array
    {
        $stopwords = portal_integrity_stopwords();
        $out = [];
        foreach (portal_integrity_words($text) as $word) {
            if (mb_strlen($word) < 3) {
                continue;
            }
            if (isset($stopwords[$word])) {
                continue;
            }
            $out[] = $word;
        }
        return $out;
    }
}

if (!function_exists('portal_integrity_strip_quotes')) {
    /**
     * Remove quoted spans so properly cited material does not count against the
     * student's similarity score. Handles straight and curly quotation marks and
     * common block-quote lead-ins.
     */
    function portal_integrity_strip_quotes(string $text): string
    {
        // Double quotes: "..." “...” «...»
        $patterns = [
            '/"[^"]{0,600}"/u',
            '/\x{201C}[^\x{201D}]{0,600}\x{201D}/u',
            '/\x{00AB}[^\x{00BB}]{0,600}\x{00BB}/u',
        ];
        $stripped = preg_replace($patterns, ' ', $text);
        return is_string($stripped) ? $stripped : $text;
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

            // Meaning-based overlap uses content words only (stopwords removed) so
            // ubiquitous function words do not inflate the score.
            $subContent = portal_integrity_content_words($submissionText);
            $srcContent = portal_integrity_content_words($sourceText);
            $subSet = array_fill_keys($subContent !== [] ? $subContent : $submissionWords, true);
            $srcSet = array_fill_keys($srcContent !== [] ? $srcContent : $sourceWords, true);
            $intersection = array_intersect_key($subSet, $srcSet);

            // Guardrails: bag-of-words containment/Jaccard is only meaningful when
            // both sides carry enough distinct vocabulary and the overlap itself is
            // more than a handful of incidental shared words (names, course/topic
            // keywords). Without this, a tiny source (e.g. a one-sentence
            // assignment description, or a short document with a broken/partial
            // text extraction) can appear "100% contained" in a huge submission
            // after sharing only 3-8 common words.
            $minSetWords = 20;
            $minOverlapWords = 8;
            $overlapCount = count($intersection);
            $meaningfulOverlap = $overlapCount >= $minOverlapWords;

            $union = $subSet + $srcSet;
            if ($union !== [] && $meaningfulOverlap && count($subSet) >= $minSetWords && count($srcSet) >= $minSetWords) {
                $jaccard = (count($intersection) / count($union)) * 100;
                if ($jaccard > $bestScore) {
                    $bestScore = $jaccard;
                    $bestPhrases = array_slice(array_keys($intersection), 0, 8);
                    $bestMethod = 'content-word-jaccard';
                }
            }
            if ($srcSet !== [] && $meaningfulOverlap && count($srcSet) >= $minSetWords) {
                $sourceContained = (count($intersection) / count($srcSet)) * 100;
                if ($sourceContained > $bestScore) {
                    $bestScore = $sourceContained;
                    $bestPhrases = array_slice(array_keys($intersection), 0, 8);
                    $bestMethod = 'source-contained-in-submission';
                }
            }
            if ($subSet !== [] && $meaningfulOverlap && count($subSet) >= $minSetWords) {
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
        ?int $excludeSourceId = null,
        array $excludeSourceKeys = []
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
                WHERE sentence_hash IN ($placeholders)
                  AND (
                    source_type != 'submission'
                    OR EXISTS (
                        SELECT 1
                        FROM course_submissions cs
                        WHERE cs.id = integrity_sentence_index.source_id
                    )
                  )";
        if ($excludeSourceType !== null && $excludeSourceId !== null) {
            $sql .= ' AND NOT (source_type = ? AND source_id = ?)';
            $params[] = $excludeSourceType;
            $params[] = $excludeSourceId;
        }
        // Sentence-level matches against the same student's own other work
        // (e.g. an earlier draft/interim submission or a document they authored
        // that a teacher shared as course material) are self-overlap, not
        // plagiarism, and should not inflate the fingerprint score.
        foreach ($excludeSourceKeys as $key) {
            if (!is_string($key) || !str_contains($key, ':')) {
                continue;
            }
            [$keyType, $keyId] = explode(':', $key, 2);
            $sql .= ' AND NOT (source_type = ? AND source_id = ?)';
            $params[] = $keyType;
            $params[] = (int) $keyId;
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

if (!function_exists('portal_integrity_names_match')) {
    /**
     * Loose name comparison for author-vs-submitter checks. Treats names as a
     * match if either is contained in the other, or they share a given/family
     * name token — this avoids false mismatches from "J. Smith" vs "John Smith"
     * while still catching a genuinely different person.
     */
    function portal_integrity_names_match(string $a, string $b, bool $strict = false): bool
    {
        $norm = static function (string $s): string {
            $s = strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
            return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
        };
        $a = $norm($a);
        $b = $norm($b);
        if ($a === '' || $b === '') {
            return !$strict; // nothing to compare → don't flag a mismatch, but never confirm identity
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }
        $at = array_filter(explode(' ', $a), static fn(string $t): bool => mb_strlen($t) >= 2);
        $bt = array_filter(explode(' ', $b), static fn(string $t): bool => mb_strlen($t) >= 2);
        $shared = array_intersect($at, $bt);
        // Strict mode is used to positively confirm the same person (e.g. before
        // excluding a similarity match from scoring as "self-authored"), so a
        // single shared common name isn't enough — require most of both name's
        // tokens to overlap.
        if ($strict) {
            $minTokens = max(1, min(count($at), count($bt)));
            return count($shared) >= 2 || count($shared) >= $minTokens;
        }
        return count($shared) >= 1;
    }
}

if (!function_exists('portal_integrity_document_context')) {
    /**
     * Build document-level context used to calibrate AI/style heuristics.
     *
     * @return array<string, mixed>
     */
    function portal_integrity_document_context(string $text, ?array $fileMetadata, ?array $submissionContext, ?float $similarityScore): array
    {
        $words = portal_integrity_words($text);
        $wordCount = count($words);
        $lower = strtolower($text);
        $isLongDocument = $wordCount >= 2000;

        $citationPatterns = [
            '/\([A-Z][A-Za-z\'-]+(?:\s+(?:and|&)\s+[A-Z][A-Za-z\'-]+|(?:\s+et\s+al\.)?)?,\s*(?:19|20)\d{2}[a-z]?\)/u',
            '/\[(?:\d{1,3})(?:\s*[-,]\s*\d{1,3})*\]/',
            '/\b(?:references|bibliography)\b/i',
            '/\bet\s+al\.?\b/i',
            '/\bdoi\s*:\s*10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/i',
            '/\b10\.\d{4,9}\/[-._;()\/:A-Z0-9]+/i',
        ];
        $hasCitations = false;
        foreach ($citationPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $hasCitations = true;
                break;
            }
        }

        $academicMarkers = [
            'abstract', 'methodology', 'methods', 'literature review', 'dissertation',
            'thesis', 'findings', 'discussion', 'conclusion', 'research question',
            'hypothesis', 'theoretical framework', 'empirical', 'qualitative',
            'quantitative', 'case study', 'data analysis', 'results',
        ];
        $markerHits = 0;
        foreach ($academicMarkers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/i', $lower)) {
                $markerHits++;
            }
        }

        $isAcademicTechnical = $hasCitations || $markerHits >= 2 || $wordCount >= 3000;

        preg_match_all('/["\x{201C}\x{2018}][^"\x{201D}\x{2019}]{1,240}["\x{201D}\x{2019}]/u', $text, $dialogueMatches);
        $dialogueCount = count($dialogueMatches[0] ?? []);
        $dialogueWords = 0;
        foreach (($dialogueMatches[0] ?? []) as $dialogue) {
            $dialogueWords += count(portal_integrity_words((string) $dialogue));
        }
        preg_match_all('/\b(?:said|asked|replied|whispered|shouted|cried|grinned|sighed)\b/i', $lower, $speechVerbMatches);
        $speechVerbCount = count($speechVerbMatches[0] ?? []);
        $dialogueCount = max($dialogueCount, min(12, $speechVerbCount));
        $dialogueRatio = $wordCount > 0 ? $dialogueWords / $wordCount : 0.0;
        $paragraphCount = count(array_values(array_filter(
            array_map('trim', preg_split('/\n{2,}|\r\n\r\n/', $text) ?: []),
            static fn(string $p): bool => $p !== ''
        )));

        $narrativeMarkers = [
            'one day', 'one rainy', 'from that day', 'years later', 'afterwards',
            'teacher', 'class', 'classroom', 'library', 'school', 'friend',
            'friends', 'laughed', 'smiled', 'asked', 'replied', 'thought',
            'looked', 'noticed', 'became', 'lived', 'town', 'story',
        ];
        $narrativeMarkerHits = 0;
        foreach ($narrativeMarkers as $marker) {
            if (preg_match('/\b' . preg_quote($marker, '/') . '\b/i', $lower)) {
                $narrativeMarkerHits++;
            }
        }
        $storyArcHits = 0;
        foreach (['at first', 'then', 'over the next', 'on the day', 'afterwards', 'from that day', 'years later'] as $marker) {
            if (str_contains($lower, $marker)) {
                $storyArcHits++;
            }
        }
        $narrativeCandidate = !$isAcademicTechnical
            && (
                ($dialogueCount >= 2 && $dialogueRatio >= 0.015 && $narrativeMarkerHits >= 3)
                || ($paragraphCount >= 6 && $storyArcHits >= 2)
                || ($narrativeMarkerHits >= 6 && $wordCount >= 250)
            );

        $genericStoryPhrases = [
            'small town where everyone knew everyone',
            'did not become friends straight away',
            'by bad luck, or perhaps by secret design',
            'it changed something between them',
            'over the next week',
            'on the day of the performance',
            'the whole class clapped',
            'from that day on',
            'years later',
            'different in almost every way',
            'where their friendship began',
            'led not to treasure, but to home',
        ];
        $genericStoryPhraseHits = 0;
        foreach ($genericStoryPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $genericStoryPhraseHits++;
            }
        }
        $tidyResolution = $narrativeCandidate
            && (bool) preg_match('/\b(from that day on|years later|for once|in the end|and somehow|where their friendship began)\b/i', $lower);
        $archetypalContrast = $narrativeCandidate
            && (bool) preg_match('/\b(quiet|serious|thoughtful)\b.*\b(loud|cheerful|noisy|dreamer)\b|\b(loud|cheerful|noisy|dreamer)\b.*\b(quiet|serious|thoughtful)\b/is', $lower);
        $settingSpecificity = 0;
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'january', 'february', 'march', 'april', 'may ', 'june', 'july', 'august', 'september', 'october', 'november', 'december'] as $marker) {
            if (str_contains($lower, $marker)) {
                $settingSpecificity++;
            }
        }
        preg_match_all('/\b[A-Z][a-z]{2,}\b/u', $text, $properMatches);
        $properNames = array_values(array_filter(array_unique($properMatches[0] ?? []), static function (string $name): bool {
            return !in_array(strtolower($name), [
                'the', 'one', 'girl', 'boy', 'alex', 'tuesday', 'english', 'because',
                'then', 'over', 'on', 'afterwards', 'from', 'years',
            ], true);
        }));
        $settingSpecificity += min(3, count($properNames));
        $lowNarrativeSpecificity = $narrativeCandidate && $wordCount >= 250 && $settingSpecificity <= 1;

        $sentencesForContext = portal_integrity_sentences($text, 25);
        $definitionSentenceCount = 0;
        foreach ($sentencesForContext as $sentence) {
            if (preg_match('/\b(?:is|are|means|refers to|is known as|are known as|is called|are called)\b/i', $sentence)) {
                $definitionSentenceCount++;
            }
        }
        $definitionDensity = count($sentencesForContext) > 0 ? $definitionSentenceCount / count($sentencesForContext) : 0.0;
        $expositoryScaffoldPhrases = [
            'one of the most important',
            'the basic idea is simple',
            'this process',
            'this means',
            'this shows',
            'this is why',
            'therefore',
            'however',
            'although',
            'the general word equation',
            'the chemical equation',
            'mainly takes place',
            'plants need',
            'can be divided into',
            'the second stage',
            'also known as',
            'one of these factors',
            'another factor',
            'several factors',
            'another important',
            'is also the foundation',
            'is also important',
            'in addition',
            'in conclusion',
            'without',
            'for example',
            'over time',
            'at that time',
            'during the',
            'one reason',
            'the largest',
            'the main reason',
            'fossils are',
            'fossil evidence',
            'scientists still',
            'scientists have',
            'modern birds',
            'this discovery',
            'this connection',
            'as a result',
            'not only because',
            'their story shows',
            'studying',
            'despite everything',
            'many questions',
        ];
        $expositoryScaffoldHits = 0;
        foreach ($expositoryScaffoldPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $expositoryScaffoldHits++;
            }
        }
        $genericExplainerPhrases = [
            'is one of the most important',
            'may sound complicated',
            'the basic idea is simple',
            'does not only help',
            'life as we know it',
            'comes from two parts',
            'literally means',
            'this means six',
            'are well adapted for this job',
            'three main things',
            'once all these materials are available',
            'can be divided into two main stages',
            'is extremely important because',
            'this shows how closely connected',
            'foundation of most food chains',
            'depend on photosynthesis directly',
            'depend on photosynthesis indirectly',
            'another important reason',
            'several factors can affect',
            'only up to a certain point',
            'another factor may become limiting',
            'not the same',
            'these features show',
            'feeding a growing world population',
            'also play a huge role',
            'although it may seem like a simple process',
            'one of the main reasons life on earth is possible',
            'some of the most fascinating',
            'helps us understand',
            'important clues about',
            'came from greek',
            'looked very different from today',
            'evolved into many different',
            'is divided into three main',
            'one reason',
            'for example',
            'these features show',
            'fossils are the main reason',
            'a fossil is',
            'over millions of years',
            'carefully dig up and examine',
            'especially useful because',
            'for a long time',
            'changed the way scientists understand',
            'are actually considered',
            'this means that',
            'can be seen in features',
            'one of the most famous events',
            'was probably not the only problem',
            'not only because',
            'environmental changes can completely transform',
            'also had a huge impact',
            'many questions about',
            'there is always more to learn',
            'remind us that',
        ];
        $genericExplainerHits = 0;
        foreach ($genericExplainerPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $genericExplainerHits++;
            }
        }
        $isEducationalExpository = !$isAcademicTechnical
            && $wordCount >= 300
            && (
                $definitionSentenceCount >= 8
                || $definitionDensity >= 0.32
                || $expositoryScaffoldHits >= 7
                || $genericExplainerHits >= 4
            );
        $isCreativeNarrative = $narrativeCandidate && !$isEducationalExpository;

        if ($wordCount < 500) {
            $styleDampening = 1.0;
        } elseif ($wordCount < 2000) {
            $styleDampening = 1.0 - (($wordCount - 500) / 1500) * 0.4;
        } elseif ($wordCount < 5000) {
            $styleDampening = 0.6 - (($wordCount - 2000) / 3000) * 0.3;
        } else {
            $styleDampening = 0.3;
        }
        if ($isAcademicTechnical) {
            $styleDampening = min($styleDampening, $isLongDocument ? 0.5 : 0.75);
        }
        $styleDampening = max(0.25, min(1.0, $styleDampening));

        $fileMetadata = is_array($fileMetadata) ? $fileMetadata : null;
        $hasFileMetadata = $fileMetadata !== null && !empty($fileMetadata['available']);
        $studentName = trim((string) ($submissionContext['student_name'] ?? ''));
        $metaAuthor = trim((string) ($fileMetadata['author'] ?? ''));
        $metaEditMinutes = ($fileMetadata !== null && ($fileMetadata['edit_time_minutes'] ?? null) !== null)
            ? max(0, (int) $fileMetadata['edit_time_minutes'])
            : null;
        $metaRevision = ($fileMetadata !== null && ($fileMetadata['revision'] ?? null) !== null)
            ? max(0, (int) $fileMetadata['revision'])
            : null;
        $metaGapDays = null;
        $metaGapSeconds = null;
        if ($fileMetadata !== null
            && ($fileMetadata['created_at_ts'] ?? null) !== null
            && ($fileMetadata['modified_at_ts'] ?? null) !== null) {
            $metaGapSeconds = max(0, (int) $fileMetadata['modified_at_ts'] - (int) $fileMetadata['created_at_ts']);
            $metaGapDays = round($metaGapSeconds / 86400, 3);
        }

        $positiveSignals = [];
        if ($hasFileMetadata && $studentName !== '' && $metaAuthor !== ''
            && portal_integrity_names_match($studentName, $metaAuthor, true)) {
            $positiveSignals[] = 'Document author metadata is consistent with the submitting student.';
        }
        if ($metaEditMinutes !== null) {
            $minimumRealisticMinutes = max(15.0, ($wordCount / 40) * 0.3);
            if ($metaEditMinutes >= $minimumRealisticMinutes) {
                $positiveSignals[] = 'Office editing time is consistent with a realistic writing period.';
            }
        }
        if ($metaGapSeconds !== null && $metaGapSeconds >= 3600) {
            $positiveSignals[] = 'File creation and last-save times are consistent with a sustained writing period.';
        }
        if ($metaRevision !== null && $metaRevision >= 5) {
            $positiveSignals[] = 'Document revision count is consistent with iterative editing.';
        }
        if ($similarityScore !== null && $similarityScore < 10.0) {
            $positiveSignals[] = 'Similarity score is below 10%, which is consistent with original authorship.';
        }
        $application = trim((string) ($fileMetadata['application'] ?? ''));
        if ($application !== '' && preg_match('/\b(word|office|microsoft|libreoffice|openoffice)\b/i', $application)) {
            $positiveSignals[] = 'File was created or edited with normal document software.';
        }
        if ($isLongDocument && $isAcademicTechnical) {
            $positiveSignals[] = 'Long academic/technical document detected, so style heuristics were weighted conservatively.';
        }

        return [
            'word_count' => $wordCount,
            'is_long_document' => $isLongDocument,
            'is_academic_technical' => $isAcademicTechnical,
            'is_creative_narrative' => $isCreativeNarrative,
            'is_educational_expository' => $isEducationalExpository,
            'has_citations' => $hasCitations,
            'academic_marker_hits' => $markerHits,
            'narrative_marker_hits' => $narrativeMarkerHits,
            'story_arc_hits' => $storyArcHits,
            'dialogue_count' => $dialogueCount,
            'dialogue_ratio' => round($dialogueRatio, 3),
            'speech_verb_count' => $speechVerbCount,
            'generic_story_phrase_hits' => $genericStoryPhraseHits,
            'tidy_resolution' => $tidyResolution,
            'archetypal_contrast' => $archetypalContrast,
            'low_narrative_specificity' => $lowNarrativeSpecificity,
            'definition_sentence_count' => $definitionSentenceCount,
            'definition_density' => round($definitionDensity, 3),
            'expository_scaffold_hits' => $expositoryScaffoldHits,
            'generic_explainer_hits' => $genericExplainerHits,
            'style_dampening' => round($styleDampening, 3),
            'meta_author' => $metaAuthor,
            'meta_edit_minutes' => $metaEditMinutes,
            'meta_revision' => $metaRevision,
            'meta_gap_days' => $metaGapDays,
            'tracking_meaningful' => $hasFileMetadata,
            'positive_signals' => array_values(array_unique($positiveSignals)),
        ];
    }
}

if (!function_exists('portal_integrity_process_review')) {
    /**
     * @return array<string, mixed>
     */
    function portal_integrity_process_review(array $submission, string $text): array
    {
        $wordCount = max(1, count(portal_integrity_words($text)));
        $portalEditSeconds = max(0, (int) ($submission['process_edit_seconds'] ?? 0));
        $pasteEvents = max(0, (int) ($submission['process_paste_events'] ?? 0));
        $pastedChars = max(0, (int) ($submission['process_pasted_chars'] ?? 0));
        $charCount = max(1, mb_strlen($text));
        $fileMeta = is_array($submission['file_metadata'] ?? null) ? $submission['file_metadata'] : null;
        $hasFileMeta = $fileMeta !== null && !empty($fileMeta['available']);
        $trackingAvailable = $portalEditSeconds > 0 || $pasteEvents > 0 || $pastedChars > 0;
        $source = match (true) {
            $hasFileMeta && $trackingAvailable => 'mixed',
            $hasFileMeta => 'file_metadata_only',
            $trackingAvailable => 'portal_textarea',
            default => 'unknown',
        };

        $riskSignals = [];
        $positiveSignals = [];
        $score = 0.0;
        $fileEditMinutes = null;
        $effectiveEditSeconds = $portalEditSeconds;

        if ($hasFileMeta) {
            if (($fileMeta['edit_time_minutes'] ?? null) !== null) {
                $fileEditMinutes = max(0, (int) $fileMeta['edit_time_minutes']);
                $fileEditSeconds = $fileEditMinutes * 60;
                if ($fileEditSeconds > $effectiveEditSeconds) {
                    $effectiveEditSeconds = $fileEditSeconds;
                }
            }

            if (($fileMeta['created_at_ts'] ?? null) !== null && ($fileMeta['modified_at_ts'] ?? null) !== null) {
                $metaGap = max(0, (int) $fileMeta['modified_at_ts'] - (int) $fileMeta['created_at_ts']);
                if ($wordCount >= 500 && $metaGap < 120) {
                    $score += 22;
                    $riskSignals[] = 'File metadata shows the document was created and last saved within 2 minutes despite ' . $wordCount . ' words.';
                } elseif ($wordCount >= 200 && $metaGap < 60) {
                    $score += 14;
                    $riskSignals[] = 'File metadata shows almost no gap between document creation and last save.';
                } elseif ($metaGap >= 3600) {
                    $positiveSignals[] = 'File creation and last-save times are consistent with a sustained writing period.';
                }
            }

            if (($fileMeta['word_count_meta'] ?? null) !== null && $wordCount >= 100) {
                $metaWords = max(0, (int) $fileMeta['word_count_meta']);
                $wordDelta = abs($metaWords - $wordCount);
                if ($wordDelta / $wordCount >= 0.35) {
                    $score += 10;
                    $riskSignals[] = 'Embedded file word count (' . $metaWords . ') differs significantly from extracted text (' . $wordCount . ' words).';
                }
            }

            if ($fileEditMinutes !== null) {
                if ($wordCount >= 300 && $fileEditMinutes < 3) {
                    $score += 32;
                    $riskSignals[] = 'Office metadata reports only ' . $fileEditMinutes . ' minute(s) of editing for ' . $wordCount . ' words.';
                } elseif ($wordCount >= 1500 && $fileEditMinutes < 10) {
                    $score += 20;
                    $riskSignals[] = 'Office metadata reports ' . $fileEditMinutes . ' minutes of editing for a long document (' . $wordCount . ' words).';
                } elseif ($fileEditMinutes >= 20) {
                    $positiveSignals[] = 'Office metadata reports ' . $fileEditMinutes . ' minutes of editing time, consistent with a real writing session.';
                }
            } elseif (($fileMeta['format'] ?? '') === 'pdf'
                && ($fileMeta['created_at_ts'] ?? null) !== null
                && ($fileMeta['modified_at_ts'] ?? null) !== null) {
                $pdfGap = max(0, (int) $fileMeta['modified_at_ts'] - (int) $fileMeta['created_at_ts']);
                if ($wordCount >= 400 && $pdfGap < 120) {
                    $score += 16;
                    $riskSignals[] = 'PDF metadata shows creation and modification within 2 minutes for a long document.';
                }
            }

            if (($fileMeta['revision'] ?? null) !== null) {
                $revision = (int) $fileMeta['revision'];
                if ($wordCount >= 400 && $revision <= 1) {
                    $score += 24;
                    $riskSignals[] = 'Document was saved only once (revision ' . $revision . ') despite ' . $wordCount . ' words - consistent with paste-and-submit.';
                } elseif ($wordCount >= 250 && $revision <= 2) {
                    $score += 12;
                    $riskSignals[] = 'Very few document revisions (' . $revision . ') for this amount of text.';
                } elseif ($revision >= 5) {
                    $positiveSignals[] = 'Document revision count is consistent with iterative editing.';
                }
            }

            $studentName = trim((string) ($submission['student_name'] ?? ''));
            $docAuthor = trim((string) ($fileMeta['author'] ?? ''));
            $lastModBy = trim((string) ($fileMeta['last_modified_by'] ?? ''));
            if ($studentName !== '' && $docAuthor !== '' && !portal_integrity_names_match($studentName, $docAuthor)) {
                $score += 18;
                $riskSignals[] = 'Document author (' . $docAuthor . ') does not match the submitting student (' . $studentName . ').';
            } elseif ($docAuthor !== '') {
                $positiveSignals[] = 'Document author metadata is present and consistent with genuine authorship: ' . $docAuthor . '.';
            }
            if ($lastModBy !== '' && $lastModBy !== $docAuthor) {
                if ($studentName !== '' && !portal_integrity_names_match($studentName, $lastModBy)) {
                    $score += 8;
                    $riskSignals[] = 'Last modified by (' . $lastModBy . ') does not match the submitting student.';
                } else {
                    $positiveSignals[] = 'Last modified by metadata is consistent with the submitting student.';
                }
            }
            if (($fileMeta['application'] ?? '') !== '') {
                $positiveSignals[] = 'Created or edited with normal document software: ' . (string) $fileMeta['application'] . '.';
            }

            if ($fileEditMinutes !== null
                && $fileEditMinutes === 0
                && $portalEditSeconds < 30
                && $wordCount >= 300) {
                $score += 14;
                $riskSignals[] = 'Neither the file metadata nor the editing session shows meaningful time spent writing this document.';
            }
        }

        $pasteRatio = min(100.0, ($pastedChars / $charCount) * 100);
        if ($pasteRatio >= 45) {
            $score += 35;
            $riskSignals[] = 'High pasted content (' . round($pasteRatio, 1) . '% of characters).';
        } elseif ($pasteRatio >= 20) {
            $score += 18;
            $riskSignals[] = 'Moderate pasted content (' . round($pasteRatio, 1) . '% of characters).';
        }

        if ($pasteEvents >= 8) {
            $score += 20;
            $riskSignals[] = 'Many paste events recorded (' . $pasteEvents . ').';
        } elseif ($pasteEvents >= 3) {
            $score += 10;
            $riskSignals[] = 'Several paste events recorded (' . $pasteEvents . ').';
        }

        $wordsPerMinute = $effectiveEditSeconds > 0 ? ($wordCount / max(1, $effectiveEditSeconds / 60)) : $wordCount;
        if ($wordCount >= 300 && $effectiveEditSeconds > 0 && $effectiveEditSeconds < 180) {
            $score += 25;
            $riskSignals[] = 'Very short effective editing window for a long submission (' . $effectiveEditSeconds . ' seconds).';
        } elseif ($wordCount >= 150 && $wordsPerMinute > 250) {
            $score += 15;
            $riskSignals[] = 'Unusually fast writing speed for the word count.';
        }

        if ($trackingAvailable && $portalEditSeconds >= 600 && $pasteEvents === 0) {
            $positiveSignals[] = 'Healthy portal editing time with no paste events.';
        }

        $score = min(100.0, round($score, 1));
        $level = portal_integrity_level($score);
        $summary = match ($level) {
            'high' => 'Writing-process evidence needs review.',
            'medium' => 'Some writing-process signals were detected.',
            default => $riskSignals === []
                ? 'No unusual writing-process signals detected.'
                : 'Minor writing-process signals were detected.',
        };
        $signals = array_values(array_merge($riskSignals, $positiveSignals));
        if ($signals === []) {
            $signals = ['No unusual writing-process signals detected.'];
        }

        return [
            'score' => $score,
            'level' => $level,
            'summary' => $summary,
            'source' => $source,
            'tracking_available' => $trackingAvailable,
            'risk_signals' => array_values(array_unique($riskSignals)),
            'positive_signals' => array_values(array_unique($positiveSignals)),
            'signals' => $signals,
            'file_metadata' => $fileMeta,
            'portal_edit_seconds' => $portalEditSeconds,
            'portal_paste_events' => $pasteEvents,
            'portal_pasted_chars' => $pastedChars,
            'effective_edit_seconds' => $effectiveEditSeconds,
            'file_edit_minutes' => $fileEditMinutes,
        ];
    }
}

if (!function_exists('portal_integrity_process_review_legacy')) {
    /**
     * @return array{score: float, level: string, signals: string[]}
     */
    function portal_integrity_process_review_legacy(array $submission, string $text): array
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

            // Revision count: a substantial document saved only once is a strong
            // marker of paste-and-submit (Office increments this on every save).
            if (isset($fileMeta['revision']) && $fileMeta['revision'] !== null) {
                $revision = (int) $fileMeta['revision'];
                if ($wordCount >= 400 && $revision <= 1) {
                    $score += 24;
                    $signals[] = 'Document was saved only once (revision ' . $revision . ') despite ' . $wordCount . ' words — consistent with paste-and-submit.';
                } elseif ($wordCount >= 250 && $revision <= 2) {
                    $score += 12;
                    $signals[] = 'Very few document revisions (' . $revision . ') for this amount of text.';
                }
            }

            // Author-name mismatch: the file's embedded author differs from the
            // student who submitted it.
            $studentName = trim((string) ($submission['student_name'] ?? ''));
            $docAuthor = trim((string) ($fileMeta['author'] ?? ''));
            $lastModBy = trim((string) ($fileMeta['last_modified_by'] ?? ''));
            if ($studentName !== '' && $docAuthor !== '' && !portal_integrity_names_match($studentName, $docAuthor)) {
                $score += 18;
                $signals[] = 'Document author (' . $docAuthor . ') does not match the submitting student (' . $studentName . ').';
            } elseif ($docAuthor !== '') {
                $signals[] = 'Document author in file metadata: ' . $docAuthor . '.';
            }
            if ($lastModBy !== '' && $lastModBy !== $docAuthor) {
                if ($studentName !== '' && !portal_integrity_names_match($studentName, $lastModBy)) {
                    $score += 8;
                }
                $signals[] = 'Last modified by: ' . $lastModBy . '.';
            }
            if (($fileMeta['application'] ?? '') !== '') {
                $signals[] = 'Created/edited with: ' . (string) $fileMeta['application'] . '.';
            }

            // Edit-time contradiction: the portal recorded a real editing session
            // but the file's own metadata reports essentially no editing time.
            $portalEditSeconds = max(0, (int) ($submission['process_edit_seconds'] ?? 0));
            if ($fileEditMinutes !== null
                && $fileEditMinutes === 0
                && $portalEditSeconds < 30
                && $wordCount >= 300) {
                $score += 14;
                $signals[] = 'Neither the file metadata nor the editing session shows meaningful time spent writing this document.';
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
     * Lightweight statistical review - not a neural AI detector.
     *
     * @return array<string, mixed>
     */
    function portal_integrity_heuristic_ai_review(string $text, array $context = []): array
    {
        $fileMetadata = is_array($context['file_metadata'] ?? null) ? $context['file_metadata'] : null;
        $processReview = is_array($context['process_review'] ?? null) ? $context['process_review'] : null;
        $submissionContext = [
            'student_name' => (string) ($context['student_name'] ?? ''),
        ];
        $similarityScore = ($context['similarity_score'] ?? null) !== null ? (float) $context['similarity_score'] : null;
        $documentContext = portal_integrity_document_context($text, $fileMetadata, $submissionContext, $similarityScore);
        $wordCount = (int) $documentContext['word_count'];
        $styleDampening = (float) $documentContext['style_dampening'];
        $isAcademicTechnical = !empty($documentContext['is_academic_technical']);
        $isCreativeNarrative = !empty($documentContext['is_creative_narrative']);
        $isEducationalExpository = !empty($documentContext['is_educational_expository']);
        $positiveSignals = is_array($documentContext['positive_signals'] ?? null)
            ? $documentContext['positive_signals']
            : [];
        if ($processReview !== null && is_array($processReview['positive_signals'] ?? null)) {
            $positiveSignals = array_merge($positiveSignals, $processReview['positive_signals']);
        }

        $sentences = portal_integrity_sentences($text, 30);
        $levelLabelFor = static function (float $score): string {
            if ($score >= 45.0) {
                return 'High concern';
            }
            if ($score >= 35.0) {
                return 'Needs review';
            }
            if ($score >= 8.0) {
                return 'Some concern';
            }
            return 'Low';
        };

        if ($wordCount < 80 || count($sentences) < 5) {
            $signals = ['Not enough text for a heuristic AI-style review.'];
            return [
                'schema_version' => 2,
                'score' => 0.0,
                'level' => 'low',
                'level_label' => 'Low',
                'evidence_strength' => 'low',
                'summary' => 'Not enough text was available for a reliable writing-style review.',
                'risk_signals' => $signals,
                'positive_signals' => array_values(array_unique($positiveSignals)),
                'teacher_note' => 'This is a statistical writing-style review only and should support teacher judgement, not replace it. File metadata can be edited, so treat it as context rather than proof.',
                'metrics' => [
                    'word_count' => $wordCount,
                    'style_dampening' => $styleDampening,
                    'risk_categories' => 0,
                ],
                'document_context' => $documentContext,
                'signals' => $signals,
            ];
        }

        $words = portal_integrity_words($text);
        $lower = strtolower($text);
        $riskSignals = [];
        $riskCategories = [];
        $score = 0.0;
        $addRisk = static function (
            string $category,
            float $points,
            string $message,
            bool $countCategory = true,
            bool $showSignal = true
        ) use (&$score, &$riskSignals, &$riskCategories, $styleDampening): void {
            $score += $points * $styleDampening;
            if ($countCategory) {
                $riskCategories[$category] = true;
            }
            if ($showSignal) {
                $riskSignals[] = $message;
            }
        };

        // 1. Sentence-length uniformity (coefficient of variation) + burstiness.
        $lengths = array_map(static fn(string $s): int => mb_strlen($s), $sentences);
        $avg = array_sum($lengths) / count($lengths);
        $variance = 0.0;
        foreach ($lengths as $len) {
            $variance += ($len - $avg) ** 2;
        }
        $variance /= count($lengths);
        $stdDev = sqrt($variance);
        $cv = $avg > 0 ? $stdDev / $avg : 0.0;
        if ($cv < 0.22) {
            $addRisk('sentence_uniformity', 24, 'Sentence lengths are extremely uniform.');
        } elseif ($cv < 0.30) {
            $addRisk('sentence_uniformity', 14, 'Sentence lengths are quite uniform.');
        }

        // 2. Vocabulary diversity (type-token ratio).
        $uniqueRatio = count(array_unique($words)) / max(1, $wordCount);
        $softAcademicSignalVisible = !$isAcademicTechnical || $styleDampening >= 0.5;
        if ($uniqueRatio < 0.38) {
            $addRisk(
                'vocabulary_diversity',
                18,
                $isAcademicTechnical
                    ? 'Vocabulary diversity is low, likely affected by technical terminology.'
                    : 'Vocabulary diversity is lower than typical for human writing.',
                $softAcademicSignalVisible,
                $softAcademicSignalVisible
            );
        } elseif ($uniqueRatio > 0.72) {
            $score -= 5;
        }

        // 3. Hedging / template phrasing frequently over-produced by LLMs.
        $aiPhrases = [
            'furthermore', 'moreover', 'however', 'therefore', 'additionally', 'consequently',
            'in conclusion', 'it is important to note', 'it is worth noting', 'it is essential to',
            'plays a crucial role', 'plays a vital role', 'plays a significant role',
            'in today\'s world', 'in the modern world', 'in the realm of', 'a myriad of',
            'it is important to understand', 'delve into', 'delving into', 'navigating the',
            'a testament to', 'when it comes to', 'first and foremost', 'in summary',
            'to summarize', 'overall', 'notably', 'importantly', 'ultimately',
            'this essay will', 'this paper will', 'in this essay', 'in this paper',
        ];
        $phraseHits = [];
        foreach ($aiPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $phraseHits[] = $phrase;
            }
        }
        $phraseCount = count($phraseHits);
        if ($phraseCount >= 8) {
            $addRisk(
                'transition_phrases',
                22,
                'Very frequent academic transition/template phrases were detected (' . $phraseCount . ' distinct).',
                $softAcademicSignalVisible,
                $softAcademicSignalVisible
            );
        } elseif ($phraseCount >= 5) {
            $addRisk(
                'transition_phrases',
                13,
                'Some repeated academic transition phrases were detected (' . $phraseCount . ' distinct).',
                $softAcademicSignalVisible,
                $softAcademicSignalVisible
            );
        } elseif ($phraseCount >= 3) {
            $score += 6 * $styleDampening;
        }

        // 4. First-person voice. In formal academic/technical work, absence of
        // first-person voice is a convention, not evidence of AI use.
        preg_match_all('/\b(i|my|me|mine|myself|we|our|us)\b/i', $lower, $fpMatches);
        $firstPersonRatio = count($fpMatches[0]) / max(1, $wordCount);
        if (!$isAcademicTechnical && !$isCreativeNarrative && $firstPersonRatio < 0.002 && $wordCount >= 200) {
            $addRisk('first_person', 10, 'Almost no first-person voice across a long text.');
        }

        // 5. Sentence-initial word diversity.
        $initials = [];
        foreach ($sentences as $sentence) {
            if (preg_match('/^\s*([a-z]+)/i', $sentence, $m)) {
                $initials[] = strtolower($m[1]);
            }
        }
        $initialDiversity = null;
        if (count($initials) >= 6) {
            $initialDiversity = count(array_unique($initials)) / count($initials);
            if ($initialDiversity < 0.5) {
                $addRisk('sentence_openings', 12, 'Many sentences begin with the same few words.');
            }
        }

        // 6. Paragraph-length uniformity.
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n{2,}|\r\n\r\n/', $text) ?: []), static fn(string $p): bool => $p !== ''));
        $paraCv = null;
        if (count($paragraphs) >= 4) {
            $paraLengths = array_map(static fn(string $p): int => count(portal_integrity_words($p)), $paragraphs);
            $paraAvg = array_sum($paraLengths) / count($paraLengths);
            if ($paraAvg > 0) {
                $paraVar = 0.0;
                foreach ($paraLengths as $pl) {
                    $paraVar += ($pl - $paraAvg) ** 2;
                }
                $paraVar /= count($paraLengths);
                $paraCv = sqrt($paraVar) / $paraAvg;
                if ($paraCv < 0.20) {
                    $addRisk('paragraph_uniformity', 10, 'Paragraphs are unusually equal in length.');
                }
            }
        }

        // 7. Comma density (list-like generated prose).
        $commaDensity = substr_count($lower, ',') / max(1, $wordCount);
        if ($commaDensity > 0.14) {
            $addRisk('comma_density', 8, 'Comma density is high, which can indicate list-like generated prose.');
        }

        // 8. Creative-writing genericity. These are deliberately weak signals:
        // tidy, generic story arcs can appear in AI examples, but they are also
        // common in younger students' original creative writing.
        $genericStoryHits = (int) ($documentContext['generic_story_phrase_hits'] ?? 0);
        $narrativeSignalCount = 0;
        if ($isCreativeNarrative && $genericStoryHits >= 3) {
            $narrativeSignalCount++;
            $addRisk(
                'narrative_genericity',
                12,
                'The story uses several generic/tidy narrative phrases often seen in generated examples (' . $genericStoryHits . ' matches).'
            );
        } elseif ($isCreativeNarrative && $genericStoryHits >= 1) {
            $score += 4 * $styleDampening;
        }
        if ($isCreativeNarrative && !empty($documentContext['tidy_resolution'])) {
            $narrativeSignalCount++;
            $addRisk('tidy_resolution', 7, 'The ending resolves the friendship arc very neatly.');
        }
        if ($isCreativeNarrative && !empty($documentContext['archetypal_contrast'])) {
            $narrativeSignalCount++;
            $addRisk('archetypal_contrast', 6, 'The main characters are built around a simple balanced contrast.');
        }
        if ($isCreativeNarrative && !empty($documentContext['low_narrative_specificity'])) {
            $narrativeSignalCount++;
            $addRisk('low_narrative_specificity', 5, 'The story has a broad setting/timeline with few specific personal details.');
        }

        // 9. Educational/expository genericity. This catches textbook-like AI
        // explainers that avoid dialogue and academic citations but follow a
        // highly generic definition -> stages -> factors -> conclusion pattern.
        $genericExplainerHits = (int) ($documentContext['generic_explainer_hits'] ?? 0);
        $expositoryScaffoldHits = (int) ($documentContext['expository_scaffold_hits'] ?? 0);
        $definitionDensity = (float) ($documentContext['definition_density'] ?? 0.0);
        $definitionSentenceCount = (int) ($documentContext['definition_sentence_count'] ?? 0);
        $expositorySignalCount = 0;
        if ($isEducationalExpository && $genericExplainerHits >= 5) {
            $expositorySignalCount++;
            $addRisk(
                'expository_genericity',
                14,
                'The explanation uses many generic textbook-style phrases often seen in generated educational summaries (' . $genericExplainerHits . ' matches).'
            );
        } elseif ($isEducationalExpository && $genericExplainerHits >= 2) {
            $score += 5 * $styleDampening;
        }
        if ($isEducationalExpository && $expositoryScaffoldHits >= 9) {
            $expositorySignalCount++;
            $addRisk(
                'expository_scaffold',
                12,
                'The response follows a very regular definition, stages, factors, and conclusion structure.'
            );
        }
        if ($isEducationalExpository && ($definitionDensity >= 0.34 || $definitionSentenceCount >= 12)) {
            $expositorySignalCount++;
            $addRisk(
                'definition_density',
                10,
                'A high share of sentences are definition/explanation statements, giving the response a generic textbook tone.'
            );
        }

        $processRiskCount = ($processReview !== null && is_array($processReview['risk_signals'] ?? null))
            ? count($processReview['risk_signals'])
            : 0;
        $categoryCount = count($riskCategories);
        $combinedEvidenceCount = $categoryCount + min(2, $processRiskCount);
        if ($combinedEvidenceCount < 2) {
            $score = min($score, 14.0);
        } elseif ($combinedEvidenceCount < 3) {
            $score = min($score, 34.0);
        }
        if ($isCreativeNarrative && $processRiskCount === 0) {
            $score = min($score, 34.0);
        }
        if ($isEducationalExpository && $processRiskCount === 0) {
            $score = min($score, 44.0);
        }

        $score = max(0.0, min(100.0, round($score, 1)));
        $level = portal_integrity_level($score);
        $positiveSignals = array_values(array_unique($positiveSignals));
        $positiveCount = count($positiveSignals);
        $strengthRank = ($categoryCount >= 4 || $score >= 35.0) ? 2 : (($categoryCount >= 2 || $score >= 15.0) ? 1 : 0);
        if ($isCreativeNarrative && $processRiskCount === 0) {
            $strengthRank = min($strengthRank, 1);
        }
        if ($isEducationalExpository && $processRiskCount === 0) {
            $strengthRank = min($strengthRank, 1);
        }
        if ($positiveCount >= 3) {
            $strengthRank--;
        }
        if ($positiveCount >= 5) {
            $strengthRank--;
        }
        $strengthRank = max(0, min(2, $strengthRank));
        $evidenceStrength = ['low', 'medium', 'high'][$strengthRank];

        if ($riskSignals === []) {
            $riskSignals[] = 'Writing style looks within a typical human range for this heuristic check.';
        }

        if ($isAcademicTechnical && $positiveCount >= 2) {
            $summary = 'Formal academic writing patterns were detected, but metadata and document context reduce concern. This is not proof of AI use.';
        } elseif ($isAcademicTechnical) {
            $summary = 'Some formal academic writing patterns were detected, but these are common in long technical work. This is not proof of AI use.';
        } elseif ($isCreativeNarrative && $level === 'medium') {
            $summary = 'Some generic creative-writing patterns were detected, but text alone is weak evidence. Review file metadata and process evidence before drawing conclusions.';
        } elseif ($isCreativeNarrative) {
            $summary = 'Low concern. The submission appears to be creative/narrative writing; generic story patterns alone are weak evidence.';
        } elseif ($isEducationalExpository && $level === 'high') {
            $summary = 'The response has a generic textbook-style explanation pattern that needs review. Text-only evidence is not proof of AI use, so compare it with file metadata and the student\'s normal work.';
        } elseif ($isEducationalExpository) {
            $summary = 'Some generic educational-explanation patterns were detected. Text-only evidence is not proof of AI use.';
        } elseif ($level === 'high') {
            $summary = 'Several writing-style patterns need teacher review. This is not proof of AI use.';
        } elseif ($level === 'medium') {
            $summary = 'Some writing-style patterns were detected. This is not proof of AI use.';
        } else {
            $summary = 'Low concern. Writing style looks broadly typical for this heuristic review.';
        }

        $signals = array_values(array_merge($riskSignals, $positiveSignals));

        return [
            'schema_version' => 2,
            'score' => $score,
            'level' => $level,
            'level_label' => $levelLabelFor($score),
            'evidence_strength' => $evidenceStrength,
            'summary' => $summary,
            'risk_signals' => $riskSignals,
            'positive_signals' => $positiveSignals,
            'teacher_note' => 'This is a statistical writing-style review only and should support teacher judgement, not replace it. File metadata can be edited, so treat it as context rather than proof.',
            'metrics' => [
                'word_count' => $wordCount,
                'sentence_length_cv' => round($cv, 3),
                'type_token_ratio' => round($uniqueRatio, 3),
                'ai_phrase_hits' => $phraseCount,
                'first_person_ratio' => round($firstPersonRatio, 4),
                'initial_word_diversity' => $initialDiversity !== null ? round($initialDiversity, 3) : null,
                'paragraph_length_cv' => $paraCv !== null ? round($paraCv, 3) : null,
                'comma_density' => round($commaDensity, 4),
                'is_creative_narrative' => $isCreativeNarrative,
                'is_educational_expository' => $isEducationalExpository,
                'generic_story_phrase_hits' => $genericStoryHits,
                'narrative_signal_count' => $narrativeSignalCount,
                'generic_explainer_hits' => $genericExplainerHits,
                'expository_scaffold_hits' => $expositoryScaffoldHits,
                'definition_density' => round($definitionDensity, 3),
                'expository_signal_count' => $expositorySignalCount,
                'style_dampening' => $styleDampening,
                'risk_categories' => $categoryCount,
                'process_risk_signals' => $processRiskCount,
                'combined_evidence_categories' => $combinedEvidenceCount,
            ],
            'document_context' => $documentContext,
            'signals' => $signals,
        ];
    }
}

if (!function_exists('portal_integrity_heuristic_ai_review_legacy')) {
    /**
     * Lightweight statistical review — not a neural AI detector.
     *
     * @return array{score: float, level: string, signals: string[]}
     */
    function portal_integrity_heuristic_ai_review_legacy(string $text): array
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
        $lower = strtolower($text);

        // 1. Sentence-length uniformity (coefficient of variation) + burstiness.
        $lengths = array_map(static fn(string $s): int => mb_strlen($s), $sentences);
        $avg = array_sum($lengths) / count($lengths);
        $variance = 0.0;
        foreach ($lengths as $len) {
            $variance += ($len - $avg) ** 2;
        }
        $variance /= count($lengths);
        $stdDev = sqrt($variance);
        $cv = $avg > 0 ? $stdDev / $avg : 0.0;
        if ($cv < 0.22) {
            $score += 24;
            $signals[] = 'Sentence lengths are extremely uniform (a strong marker of generated text).';
        } elseif ($cv < 0.30) {
            $score += 14;
            $signals[] = 'Sentence lengths are quite uniform (common in generated text).';
        }

        // 2. Vocabulary diversity (type-token ratio).
        $uniqueRatio = count(array_unique($words)) / $wordCount;
        if ($uniqueRatio < 0.38) {
            $score += 18;
            $signals[] = 'Vocabulary diversity is lower than typical for human academic writing.';
        } elseif ($uniqueRatio > 0.72) {
            $score -= 5;
        }

        // 3. Hedging / template phrasing frequently over-produced by LLMs.
        $aiPhrases = [
            'furthermore', 'moreover', 'however', 'therefore', 'additionally', 'consequently',
            'in conclusion', 'it is important to note', 'it is worth noting', 'it is essential to',
            'plays a crucial role', 'plays a vital role', 'plays a significant role',
            'in today\'s world', 'in the modern world', 'in the realm of', 'a myriad of',
            'it is important to understand', 'delve into', 'delving into', 'navigating the',
            'a testament to', 'when it comes to', 'first and foremost', 'in summary',
            'to summarize', 'overall', 'notably', 'importantly', 'ultimately',
            'this essay will', 'this paper will', 'in this essay', 'in this paper',
        ];
        $phraseHits = [];
        foreach ($aiPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                $phraseHits[] = $phrase;
            }
        }
        $phraseCount = count($phraseHits);
        if ($phraseCount >= 8) {
            $score += 22;
            $signals[] = 'Very frequent AI-style hedging/transition phrases (' . $phraseCount . ' distinct).';
        } elseif ($phraseCount >= 5) {
            $score += 13;
            $signals[] = 'Frequent template-style transition phrases detected (' . $phraseCount . ' distinct).';
        } elseif ($phraseCount >= 3) {
            $score += 6;
        }

        // 4. First-person voice — AI academic prose rarely uses "I / my / we".
        preg_match_all('/\b(i|my|me|mine|myself|we|our|us)\b/i', $lower, $fpMatches);
        $firstPersonRatio = count($fpMatches[0]) / $wordCount;
        if ($firstPersonRatio < 0.002 && $wordCount >= 200) {
            $score += 10;
            $signals[] = 'Almost no first-person voice across a long text (typical of generated prose).';
        }

        // 5. Sentence-initial word diversity — AI often opens sentences the same way.
        $initials = [];
        foreach ($sentences as $sentence) {
            if (preg_match('/^\s*([a-z]+)/i', $sentence, $m)) {
                $initials[] = strtolower($m[1]);
            }
        }
        if (count($initials) >= 6) {
            $initialDiversity = count(array_unique($initials)) / count($initials);
            if ($initialDiversity < 0.5) {
                $score += 12;
                $signals[] = 'Many sentences begin with the same few words (repetitive AI-style structure).';
            }
        }

        // 6. Paragraph-length uniformity.
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\n{2,}|\r\n\r\n/', $text) ?: []), static fn(string $p): bool => $p !== ''));
        if (count($paragraphs) >= 4) {
            $paraLengths = array_map(static fn(string $p): int => count(portal_integrity_words($p)), $paragraphs);
            $paraAvg = array_sum($paraLengths) / count($paraLengths);
            if ($paraAvg > 0) {
                $paraVar = 0.0;
                foreach ($paraLengths as $pl) {
                    $paraVar += ($pl - $paraAvg) ** 2;
                }
                $paraVar /= count($paraLengths);
                $paraCv = sqrt($paraVar) / $paraAvg;
                if ($paraCv < 0.20) {
                    $score += 10;
                    $signals[] = 'Paragraphs are unusually equal in length (regular AI-style structure).';
                }
            }
        }

        // 7. Comma density (list-like generated prose).
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
            'metrics' => [
                'sentence_length_cv' => round($cv, 3),
                'type_token_ratio' => round($uniqueRatio, 3),
                'ai_phrase_hits' => $phraseCount,
                'first_person_ratio' => round($firstPersonRatio, 4),
            ],
        ];
    }
}

if (!function_exists('portal_site_settings_reload')) {
    function portal_site_settings_reload(): void
    {
        $GLOBALS['portal_site_settings_cache'] = null;
    }
}

if (!function_exists('portal_site_setting_get')) {
    function portal_site_setting_get(string $key, string $default = ''): string
    {
        if (!isset($GLOBALS['portal_site_settings_cache']) || $GLOBALS['portal_site_settings_cache'] === null) {
            $cache = [];
            try {
                $rows = portal_db()->query("SELECT setting_key, setting_value FROM portal_site_settings")->fetchAll();
                foreach ($rows as $row) {
                    $cache[(string) $row['setting_key']] = (string) $row['setting_value'];
                }
            } catch (\PDOException $e) {
                $cache = [];
            }
            $GLOBALS['portal_site_settings_cache'] = $cache;
        }

        $cache = $GLOBALS['portal_site_settings_cache'];
        return $cache[$key] ?? $default;
    }
}

if (!function_exists('portal_site_setting_set')) {
    function portal_site_setting_set(string $key, string $value): void
    {
        portal_db()->prepare(
            "INSERT INTO portal_site_settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, datetime('now'))
             ON CONFLICT(setting_key) DO UPDATE SET
                setting_value = excluded.setting_value,
                updated_at = datetime('now')"
        )->execute([$key, $value]);
        portal_site_settings_reload();
    }
}

if (!function_exists('portal_site_setting_has')) {
    function portal_site_setting_has(string $key): bool
    {
        return portal_site_setting_get($key, '') !== '';
    }
}

if (!function_exists('portal_external_ai_policy')) {
    /** @return 'disabled'|'site_wide'|'per_module' */
    function portal_external_ai_policy(): string
    {
        $policy = portal_site_setting_get('external_ai_policy', 'disabled');
        return in_array($policy, ['disabled', 'site_wide', 'per_module'], true) ? $policy : 'disabled';
    }
}

if (!function_exists('portal_course_external_ai_enabled')) {
    function portal_course_external_ai_enabled(int $courseId): bool
    {
        if ($courseId <= 0) {
            return false;
        }
        $stmt = portal_db()->prepare("SELECT external_ai_detection FROM courses WHERE id = ?");
        $stmt->execute([$courseId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }
}

if (!function_exists('portal_external_ai_configured')) {
    function portal_external_ai_configured(): bool
    {
        return portal_gptzero_key_configured();
    }
}

if (!function_exists('portal_external_ai_should_run')) {
    /**
     * Whether GPTZero should run for a submission.
     *
     * @param array<string, mixed> $context item row or submission context with course_id and optional submission_ai_detection
     */
    function portal_external_ai_should_run(array $context): bool
    {
        if (!portal_external_ai_configured()) {
            return false;
        }

        $policy = portal_external_ai_policy();
        if ($policy === 'disabled') {
            return false;
        }

        $courseId = (int) ($context['course_id'] ?? 0);

        if ($policy === 'site_wide') {
            return true;
        }

        // per_module — course must be enabled by admin
        if (!portal_course_external_ai_enabled($courseId)) {
            return false;
        }

        // Within enabled modules, teachers opt in per submission slot.
        return !empty($context['submission_ai_detection']);
    }
}

if (!function_exists('portal_show_submission_external_ai_option')) {
    function portal_show_submission_external_ai_option(int $courseId): bool
    {
        return portal_external_ai_configured()
            && portal_external_ai_policy() === 'per_module'
            && portal_course_external_ai_enabled($courseId);
    }
}

if (!function_exists('portal_gptzero_api_key')) {
    function portal_gptzero_api_key(): string
    {
        $fromDb = portal_site_setting_get('gptzero_api_key', '');
        if ($fromDb !== '') {
            return $fromDb;
        }
        $legacy = portal_site_setting_get('zerogpt_api_key', '');
        if ($legacy !== '') {
            portal_site_setting_set('gptzero_api_key', $legacy);
            return $legacy;
        }
        return trim((string) getenv('GPTZERO_API_KEY'));
    }
}

if (!function_exists('portal_gptzero_key_configured')) {
    function portal_gptzero_key_configured(): bool
    {
        return portal_gptzero_api_key() !== '';
    }
}

if (!function_exists('portal_gptzero_key_save')) {
    function portal_gptzero_key_save(string $apiKey): void
    {
        portal_site_setting_set('gptzero_api_key', trim($apiKey));
        portal_site_setting_set('zerogpt_api_key', '');
    }
}

if (!function_exists('portal_gptzero_key_clear')) {
    function portal_gptzero_key_clear(): void
    {
        portal_site_setting_set('gptzero_api_key', '');
        portal_site_setting_set('zerogpt_api_key', '');
    }
}

if (!function_exists('portal_gptzero_request_with_key')) {
    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, http: int, body: string, error: string}
     */
    function portal_gptzero_request_with_key(string $url, array $payload, string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'GPTZero API key is not configured.'];
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
                'x-api-key: ' . $apiKey,
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

if (!function_exists('portal_gptzero_request')) {
    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, http: int, body: string, error: string}
     */
    function portal_gptzero_request(string $url, array $payload): array
    {
        return portal_gptzero_request_with_key($url, $payload, portal_gptzero_api_key());
    }
}

if (!function_exists('portal_gptzero_validate_api_key')) {
    /**
     * @return array{ok: bool, error: string}
     */
    function portal_gptzero_validate_api_key(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Add a GPTZero API key before saving GPTZero settings.'];
        }

        $validationText = 'This validation request checks whether the supplied GPTZero API key can authenticate against the text prediction endpoint. It is ordinary prose written for a system configuration test. The result is not used as an academic integrity decision, and the submitted key is only saved after GPTZero accepts this request.';
        $response = portal_gptzero_request_with_key('https://api.gptzero.me/v2/predict/text', [
            'document' => $validationText,
        ], $apiKey);

        if ($response['ok']) {
            $data = json_decode($response['body'], true);
            if (is_array($data)) {
                return ['ok' => true, 'error' => ''];
            }
            return ['ok' => false, 'error' => 'GPTZero accepted the request but returned an unreadable validation response.'];
        }

        if (in_array($response['http'], [401, 403], true)) {
            return ['ok' => false, 'error' => 'GPTZero rejected this API key. Check the key and try again.'];
        }

        if ($response['http'] === 429) {
            return ['ok' => false, 'error' => 'GPTZero could not validate this key because the API rate limit was reached. Try again later.'];
        }

        if ($response['http'] > 0) {
            return ['ok' => false, 'error' => 'GPTZero could not validate this key. The validation request returned HTTP ' . $response['http'] . '.'];
        }

        return ['ok' => false, 'error' => $response['error'] !== '' ? $response['error'] : 'GPTZero could not validate this key.'];
    }
}

if (!function_exists('portal_gptzero_pick_numeric')) {
    function portal_gptzero_pick_numeric(array $data, array $paths): ?float
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

if (!function_exists('portal_gptzero_score_percent')) {
    function portal_gptzero_score_percent(float $score): float
    {
        return $score <= 1.0 ? $score * 100.0 : $score;
    }
}

if (!function_exists('portal_gptzero_probability_value')) {
    /**
     * @param array<string, mixed> $probabilities
     */
    function portal_gptzero_probability_value(array $probabilities, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($probabilities[$key]) && is_numeric($probabilities[$key])) {
                return (float) $probabilities[$key];
            }
        }
        return null;
    }
}

if (!function_exists('portal_gptzero_ai_score')) {
    function portal_gptzero_ai_score(array $data): ?float
    {
        $explicit = portal_gptzero_pick_numeric($data, [
            ['ai_probability'],
            ['ai_score'],
            ['generated_probability'],
            ['completely_generated_prob'],
            ['result', 'ai_probability'],
            ['document', 'ai_probability'],
        ]);
        if ($explicit !== null) {
            return portal_gptzero_score_percent($explicit);
        }

        $probabilitySets = [
            $data['class_probabilities'] ?? null,
            $data['document']['class_probabilities'] ?? null,
            $data['result']['class_probabilities'] ?? null,
            $data['documents'][0]['class_probabilities'] ?? null,
        ];

        foreach ($probabilitySets as $probabilities) {
            if (!is_array($probabilities)) {
                continue;
            }

            $aiOnly = portal_gptzero_probability_value($probabilities, ['AI_ONLY', 'ai_only', 'AI_GENERATED', 'ai_generated']);
            $mixed = portal_gptzero_probability_value($probabilities, ['MIXED', 'mixed']);
            if ($aiOnly !== null || $mixed !== null) {
                return min(100.0, portal_gptzero_score_percent(($aiOnly ?? 0.0) + ($mixed ?? 0.0)));
            }
        }

        return null;
    }
}

if (!function_exists('portal_gptzero_detection')) {
    function portal_gptzero_detection(string $text): array
    {
        if (portal_gptzero_api_key() === '') {
            return ['status' => 'not_configured', 'score' => null, 'report' => 'External AI detection is disabled. An admin can enable it in Admin → External AI detection.'];
        }
        if ($text === '') {
            return ['status' => 'no_text', 'score' => null, 'report' => 'No readable text could be extracted from this submission for AI detection.'];
        }

        $limitedText = function_exists('mb_substr') ? mb_substr($text, 0, 15000) : substr($text, 0, 15000);
        $endpoints = [
            'https://api.gptzero.me/v2/predict/text',
        ];

        $lastError = 'GPTZero AI detection failed.';
        foreach ($endpoints as $endpoint) {
            $response = portal_gptzero_request($endpoint, [
                'document' => $limitedText,
            ]);
            if (!$response['ok']) {
                $lastError = $response['error'] !== '' ? $response['error'] : 'GPTZero returned HTTP ' . $response['http'] . '.';
                continue;
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                $lastError = 'GPTZero returned an unreadable response.';
                continue;
            }

            $score = portal_gptzero_ai_score($data);

            if ($score !== null) {
                return [
                    'status' => 'checked',
                    'score' => $score,
                    'report' => function_exists('mb_substr') ? mb_substr($response['body'], 0, 4000) : substr($response['body'], 0, 4000),
                ];
            }

            $lastError = 'GPTZero response did not include an AI score.';
        }

        return ['status' => 'error', 'score' => null, 'report' => $lastError];
    }
}

if (!function_exists('portal_gptzero_plagiarism')) {
    /**
     * @return array{status: string, score: ?float, report: string, matches: array<int, array<string, mixed>>}
     */
    function portal_gptzero_plagiarism(string $text): array
    {
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
        // Score against a quote-stripped copy so properly cited passages don't
        // count as the student's own overlap. Display/report still use full text.
        $scoringText = portal_integrity_normalize_text(portal_integrity_strip_quotes($cleanText));
        if ($scoringText === '') {
            $scoringText = $cleanText;
        }
        $words = portal_integrity_words($cleanText);
        $wordCount = count($words);
        $scoringWordCount = count(portal_integrity_words($scoringText));
        $studentName = trim((string) ($submissionContext['student_name'] ?? ''));
        // Portal account display names don't always match a document's embedded
        // "author" metadata (nicknames, real name vs. username, etc.), so also
        // keep the submitter's own file-author name to cross-check against other
        // documents' author metadata when identifying self-authored sources.
        $submissionFileAuthor = trim((string) ($submissionContext['file_metadata']['author'] ?? ''));

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
                        'global_web' => 'not_configured',
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
            // A different assignment slot submitted by the same student (e.g. an
            // earlier draft/interim submission that evolved into this one) is
            // self-overlap, not plagiarism — flag it so it doesn't drive the score.
            $selfAuthored = $studentName !== ''
                && portal_integrity_names_match($studentName, (string) $source['student_name'], true);
            $sources[] = [
                'label' => $label,
                'text' => (string) $source['submission_text'],
                'type' => 'institutional submission',
                'date' => (string) $source['submitted_at'],
                'self_authored' => $selfAuthored,
                'source_key' => 'submission:' . (int) $source['id'],
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
            $materialAuthor = '';
            if ($material['file_path'] !== '') {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $material['file_path']);
                if (is_file($abs)) {
                    $materialText .= "\n" . portal_extract_submission_text($abs, (string) $material['file_name']);
                    $materialMeta = portal_extract_submission_file_metadata($abs, (string) $material['file_name']);
                    $materialAuthor = trim((string) ($materialMeta['author'] ?? ''));
                }
            }
            $materialText = portal_integrity_normalize_text($materialText);
            // Skip trivially short descriptions (e.g. generic placeholder
            // instructions like "This is where you submit your assignment.") —
            // they carry no meaningful comparison signal and can only produce
            // false-positive matches against unrelated submissions.
            if ($materialText !== '' && count(portal_integrity_words($materialText)) >= 25) {
                $label = trim($material['title'] . ' (' . $material['course_title'] . ')');
                portal_integrity_index_document(
                    $db,
                    $materialText,
                    'material',
                    (int) $material['id'],
                    $label,
                    $courseId
                );
                // A course document authored by the same student (e.g. a teacher
                // sharing that student's own interim report as a reference file)
                // is self-overlap, not plagiarism.
                $selfAuthored = ($studentName !== '' && $materialAuthor !== ''
                        && portal_integrity_names_match($studentName, $materialAuthor, true))
                    || ($submissionFileAuthor !== '' && $materialAuthor !== ''
                        && portal_integrity_names_match($submissionFileAuthor, $materialAuthor, true));
                $sources[] = [
                    'label' => $label,
                    'text' => $materialText,
                    'type' => 'institutional material',
                    'date' => '',
                    'self_authored' => $selfAuthored,
                    'source_key' => 'material:' . (int) $material['id'],
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
        $selfAuthoredSourceKeys = [];
        foreach ($sources as $source) {
            if (!empty($source['self_authored']) && isset($source['source_key'])) {
                $selfAuthoredSourceKeys[] = (string) $source['source_key'];
            }
            $pair = portal_integrity_pair_score($scoringText, $source['text']);
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
                'self_authored' => !empty($source['self_authored']),
            ];
        }

        usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        // Self-authored matches (the student's own earlier work, e.g. an
        // interim draft) are shown for context but must not drive the headline
        // similarity score — that score should reflect overlap with other
        // people's work.
        $scorableMatches = array_values(array_filter($matches, static fn(array $m): bool => empty($m['self_authored'])));
        $institutionalMatches = array_slice($matches, 0, 5);
        $score = !empty($scorableMatches) ? (float) $scorableMatches[0]['score'] : 0.0;

        $fingerprint = portal_integrity_fingerprint_matches(
            $db,
            $scoringText,
            $submissionId !== null ? 'submission' : null,
            $submissionId,
            $selfAuthoredSourceKeys
        );
        if ($fingerprint['score'] > $score) {
            $score = (float) $fingerprint['score'];
        }
        if (!empty($fingerprint['sources'])) {
            $institutionalMatches = array_merge($fingerprint['sources'], $institutionalMatches);
            usort($institutionalMatches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $institutionalMatches = array_slice($institutionalMatches, 0, 6);
        }

        // Length-aware calibration: short submissions naturally overlap more on
        // shingles/word sets, so dampen their native similarity score to avoid
        // false "high" flags on brief answers. Full weight kicks in around 300+
        // scoring words.
        $lengthConfidence = min(1.0, $scoringWordCount / 300);
        if ($scoringWordCount < 300 && $score > 0) {
            // Blend toward a floor so genuine 100% duplicates still read high,
            // but a 40-word answer that shares phrasing isn't over-penalised.
            $dampened = $score * (0.55 + 0.45 * $lengthConfidence);
            $score = round($dampened, 1);
        }

        $webScope = 'not_configured';
        $webMatches = [];
        $web = ['status' => 'not_configured', 'score' => null, 'report' => '', 'matches' => []];
        if ($submissionContext !== null && portal_external_ai_should_run($submissionContext)) {
            $web = portal_gptzero_plagiarism($cleanText);
        }
        if ($web['status'] === 'checked' && ($web['score'] !== null || $web['matches'] !== [])) {
            $webScope = 'checked';
            $webMatches = $web['matches'];
            if ($web['score'] !== null && (float) $web['score'] > $score) {
                $score = (float) $web['score'];
            }
            $matches = array_merge($webMatches, $institutionalMatches);
            usort($matches, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
            $matches = array_slice($matches, 0, 6);
        } elseif ($submissionContext !== null && portal_external_ai_should_run($submissionContext)) {
            $webScope = 'unavailable';
        }

        $highlights = array_values(array_unique(array_slice($allMatchedPhrases, 0, 16)));
        $level = portal_integrity_level($score);
        $hasScorableMatches = !empty($scorableMatches) || $fingerprint['score'] > 0;
        $hasSelfAuthoredOnly = !$hasScorableMatches
            && (!empty(array_filter($institutionalMatches, static fn(array $m): bool => !empty($m['self_authored']))));
        if ($hasSelfAuthoredOnly) {
            $summary = 'Overlap found only with this student\'s own earlier work — not flagged as plagiarism.';
        } elseif (empty($institutionalMatches) && empty($webMatches)) {
            $summary = 'No meaningful overlap was found in the institutional database.';
        } elseif ($webMatches !== []) {
            $summary = 'Overlap was found against institutional sources and/or the web.';
        } else {
            $summary = 'Potential overlap was found against institutional sources.';
        }

        $processReview = $submissionContext !== null
            ? portal_integrity_process_review($submissionContext, $cleanText)
            : null;
        $fileMetadata = $submissionContext !== null && is_array($submissionContext['file_metadata'] ?? null)
            ? $submissionContext['file_metadata']
            : (is_array($processReview['file_metadata'] ?? null) ? $processReview['file_metadata'] : null);
        $heuristicAi = portal_integrity_heuristic_ai_review($cleanText, [
            'file_metadata' => $fileMetadata,
            'process_review' => $processReview,
            'similarity_score' => $score,
            'student_name' => $submissionContext['student_name'] ?? '',
        ]);

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
                'calibration' => [
                    'scoring_word_count' => $scoringWordCount,
                    'quotes_excluded' => $scoringWordCount < $wordCount,
                    'length_confidence' => round($lengthConfidence, 2),
                ],
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
                    <?php if (($processReview['summary'] ?? '') !== ''): ?>
                        <p><?= portal_escape((string) $processReview['summary']) ?></p>
                    <?php endif; ?>
                    <?php if (isset($processReview['risk_signals'])): ?>
                        <?php if (!empty($processReview['risk_signals'])): ?>
                            <p><strong>Risk signals</strong></p>
                            <ul class="integrity-signal-list">
                                <?php foreach ($processReview['risk_signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($processReview['positive_signals'])): ?>
                            <p><strong>Signals consistent with genuine authorship</strong></p>
                            <ul class="integrity-signal-list">
                                <?php foreach ($processReview['positive_signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <ul class="integrity-signal-list">
                            <?php foreach (($processReview['signals'] ?? []) as $signal): ?>
                                <li><?= portal_escape((string) $signal) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($heuristicAi !== null): ?>
                <div class="integrity-heuristic-panel integrity-report--<?= portal_escape((string) ($heuristicAi['level'] ?? 'low')) ?>">
                    <strong>Heuristic AI-style review</strong>
                    <?php if (isset($heuristicAi['risk_signals'])): ?>
                        <span>
                            <?= portal_escape((string) round((float) ($heuristicAi['score'] ?? 0), 1)) ?>%
                            <?php if (($heuristicAi['level_label'] ?? '') !== ''): ?>
                                &middot; <?= portal_escape((string) $heuristicAi['level_label']) ?>
                            <?php endif; ?>
                            <?php if (($heuristicAi['evidence_strength'] ?? '') !== ''): ?>
                                &middot; Evidence strength: <?= portal_escape(ucfirst((string) $heuristicAi['evidence_strength'])) ?>
                            <?php endif; ?>
                        </span>
                        <?php if (($heuristicAi['summary'] ?? '') !== ''): ?>
                            <p><?= portal_escape((string) $heuristicAi['summary']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($heuristicAi['risk_signals'])): ?>
                            <p><strong>Risk signals</strong></p>
                            <ul class="integrity-signal-list">
                                <?php foreach ($heuristicAi['risk_signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($heuristicAi['positive_signals'])): ?>
                            <p><strong>Signals consistent with genuine authorship</strong></p>
                            <ul class="integrity-signal-list">
                                <?php foreach ($heuristicAi['positive_signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (($heuristicAi['teacher_note'] ?? '') !== ''): ?>
                            <p><?= portal_escape((string) $heuristicAi['teacher_note']) ?></p>
                        <?php endif; ?>
                    <?php else: ?>
                    <span><?= portal_escape((string) round((float) ($heuristicAi['score'] ?? 0), 1)) ?>% — statistical only, not proof of AI use</span>
                    <ul class="integrity-signal-list">
                        <?php foreach (($heuristicAi['signals'] ?? []) as $signal): ?>
                            <li><?= portal_escape((string) $signal) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
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
                    <strong>External AI detection (GPTZero)</strong>
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

if (!function_exists('portal_integrity_tone')) {
    /** Map a 0-100 risk score to a tone: good | warn | bad. */
    function portal_integrity_tone(?float $score, float $warnAt = 15.0, float $badAt = 40.0): string
    {
        if ($score === null) {
            return 'muted';
        }
        if ($score >= $badAt) {
            return 'bad';
        }
        if ($score >= $warnAt) {
            return 'warn';
        }
        return 'good';
    }
}

if (!function_exists('portal_integrity_summary_cards')) {
    /**
     * Compact, expandable summary cards used in the review panel.
     * Detailed evidence is only rendered for teachers ($isTeacher = true).
     */
    function portal_integrity_summary_cards(array $submission, bool $isTeacher): string
    {
        $report      = portal_integrity_report_data($submission);
        $simScore    = isset($report['score']) && $report['score'] !== null ? (float) $report['score'] : null;
        $matches     = is_array($report['matches'] ?? null) ? $report['matches'] : [];
        $processRev   = is_array($report['process_review'] ?? null) ? $report['process_review'] : null;
        $heuristicAi  = is_array($report['heuristic_ai'] ?? null) ? $report['heuristic_ai'] : null;
        $fileMeta     = is_array($report['file_metadata'] ?? null) ? $report['file_metadata'] : null;
        $calibration  = is_array($report['calibration'] ?? null) ? $report['calibration'] : null;

        $aiChance    = $heuristicAi !== null && isset($heuristicAi['score']) ? (float) $heuristicAi['score'] : null;
        $processLevel = $processRev['level'] ?? 'low';
        $processLabel = match ($processLevel) {
            'high'   => 'Needs review',
            'medium' => 'Some signals',
            default  => 'Looks normal',
        };
        $processTone = match ($processLevel) {
            'high'   => 'bad',
            'medium' => 'warn',
            default  => 'good',
        };

        $keyConfigured = portal_external_ai_configured();
        $policy = portal_external_ai_policy();
        $extStatus = (string) ($submission['ai_status'] ?? '');
        $extScore  = ($submission['ai_score'] ?? null) !== null ? (float) $submission['ai_score'] : null;
        $extDisabled = !$keyConfigured || $policy === 'disabled';

        $editSeconds = (int) ($processRev['portal_edit_seconds'] ?? ($submission['process_edit_seconds'] ?? 0));
        $pasteEvents = (int) ($processRev['portal_paste_events'] ?? ($submission['process_paste_events'] ?? 0));
        $pastedChars = (int) ($processRev['portal_pasted_chars'] ?? ($submission['process_pasted_chars'] ?? 0));
        $trackingAvailable = $processRev !== null
            ? (bool) ($processRev['tracking_available'] ?? ($editSeconds > 0 || $pasteEvents > 0 || $pastedChars > 0))
            : ($editSeconds > 0 || $pasteEvents > 0 || $pastedChars > 0);
        $processSource = (string) ($processRev['source'] ?? ($fileMeta !== null && !empty($fileMeta['available']) ? 'file_metadata_only' : 'unknown'));

        ob_start();
        ?>
        <div class="rvw-cards">

            <!-- Similarity -->
            <details class="rvw-card rvw-card--<?= portal_escape(portal_integrity_tone($simScore)) ?>"<?= ($isTeacher && !empty($matches)) ? '' : ' data-static="1"' ?>>
                <summary class="rvw-card-head">
                    <span class="rvw-card-label">Similarity</span>
                    <span class="rvw-card-value"><?= $simScore !== null ? portal_escape((string) round($simScore, 1)) . '%' : '—' ?></span>
                </summary>
                <div class="rvw-card-body">
                    <?php if ($isTeacher && !empty($matches)): ?>
                        <p class="rvw-card-note">Overlap found against the sources below.</p>
                        <?php foreach (array_slice($matches, 0, 6) as $match): ?>
                            <?php $isSelf = !empty($match['self_authored']); ?>
                            <div class="rvw-source<?= $isSelf ? ' rvw-source--self' : '' ?>">
                                <div class="rvw-source-head">
                                    <strong><?= portal_escape((string) ($match['source'] ?? 'Matched source')) ?></strong>
                                    <span><?= portal_escape((string) round((float) ($match['score'] ?? 0), 1)) ?>%</span>
                                </div>
                                <?php if ($isSelf): ?>
                                    <p class="rvw-source-tag">Own earlier work — excluded from the similarity score</p>
                                <?php endif; ?>
                                <?php if (($match['snippet'] ?? '') !== ''): ?>
                                    <p><?= portal_escape((string) $match['snippet']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="rvw-card-note">
                            <?php if ($simScore === null): ?>
                                Similarity is still being processed.
                            <?php elseif ($simScore < 15): ?>
                                No significant overlap was found with other sources.
                            <?php else: ?>
                                Some overlap with other sources was found.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($isTeacher && $calibration !== null): ?>
                        <p class="rvw-card-calibration">
                            <?php if (!empty($calibration['quotes_excluded'])): ?>Quoted passages were excluded from the score. <?php endif; ?>
                            <?php if (($calibration['length_confidence'] ?? 1) < 1): ?>Short submission (<?= (int) ($calibration['scoring_word_count'] ?? 0) ?> words) — score dampened to reduce false positives.<?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
            </details>

            <!-- Chance of AI usage -->
            <details class="rvw-card rvw-card--<?= portal_escape(portal_integrity_tone($aiChance, 20, 45)) ?>"<?= $isTeacher ? '' : ' data-static="1"' ?>>
                <summary class="rvw-card-head">
                    <span class="rvw-card-label">Chance of AI usage</span>
                    <span class="rvw-card-value"><?= $aiChance !== null ? portal_escape((string) round($aiChance, 0)) . '%' : '—' ?></span>
                </summary>
                <div class="rvw-card-body">
                    <p class="rvw-card-note rvw-card-note--soft">Statistical estimate only — not proof of AI use.</p>
                    <?php if ($isTeacher): ?>
                        <?php if ($heuristicAi !== null && isset($heuristicAi['risk_signals'])): ?>
                            <?php if (($heuristicAi['level_label'] ?? '') !== '' || ($heuristicAi['evidence_strength'] ?? '') !== ''): ?>
                                <p class="rvw-card-note">
                                    <?php if (($heuristicAi['level_label'] ?? '') !== ''): ?>
                                        Level: <strong><?= portal_escape((string) $heuristicAi['level_label']) ?></strong>
                                    <?php endif; ?>
                                    <?php if (($heuristicAi['evidence_strength'] ?? '') !== ''): ?>
                                        &middot; Evidence strength: <strong><?= portal_escape(ucfirst((string) $heuristicAi['evidence_strength'])) ?></strong>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                            <?php if (($heuristicAi['summary'] ?? '') !== ''): ?>
                                <p class="rvw-card-note"><?= portal_escape((string) $heuristicAi['summary']) ?></p>
                            <?php endif; ?>
                            <?php if (!empty($heuristicAi['risk_signals'])): ?>
                                <p class="rvw-evidence-title">Risk signals</p>
                                <ul class="rvw-evidence-list">
                                    <?php foreach ($heuristicAi['risk_signals'] as $signal): ?>
                                        <li><?= portal_escape((string) $signal) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (!empty($heuristicAi['positive_signals'])): ?>
                                <p class="rvw-evidence-title">Signals consistent with genuine authorship</p>
                                <ul class="rvw-evidence-list">
                                    <?php foreach ($heuristicAi['positive_signals'] as $signal): ?>
                                        <li><?= portal_escape((string) $signal) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (($heuristicAi['teacher_note'] ?? '') !== ''): ?>
                                <p class="rvw-card-note rvw-card-note--soft"><?= portal_escape((string) $heuristicAi['teacher_note']) ?></p>
                            <?php endif; ?>
                        <?php else: ?>
                        <?php if ($heuristicAi !== null && !empty($heuristicAi['signals'])): ?>
                            <p class="rvw-evidence-title">Writing-style signals</p>
                            <ul class="rvw-evidence-list">
                                <?php foreach ($heuristicAi['signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($fileMeta !== null && !empty($fileMeta['available'])): ?>
                            <p class="rvw-evidence-title">Authenticity evidence</p>
                            <ul class="rvw-evidence-list">
                                <?php if (($fileMeta['author'] ?? '') !== ''): ?><li>Document author: <strong><?= portal_escape((string) $fileMeta['author']) ?></strong></li><?php endif; ?>
                                <?php if (($fileMeta['edit_time_minutes'] ?? null) !== null): ?><li>Editing time: <strong><?= (int) $fileMeta['edit_time_minutes'] ?> min</strong></li><?php endif; ?>
                                <?php if (($fileMeta['application'] ?? '') !== ''): ?><li>Application: <strong><?= portal_escape((string) $fileMeta['application']) ?></strong></li><?php endif; ?>
                                <?php if ($pasteEvents > 0): ?><li>Paste events while typing: <strong><?= $pasteEvents ?></strong></li><?php endif; ?>
                            </ul>
                        <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="rvw-card-note">Your teacher can review the detailed evidence.</p>
                    <?php endif; ?>
                </div>
            </details>

            <!-- Writing process -->
            <details class="rvw-card rvw-card--<?= portal_escape($processTone) ?>"<?= $isTeacher ? '' : ' data-static="1"' ?>>
                <summary class="rvw-card-head">
                    <span class="rvw-card-label">Writing process</span>
                    <span class="rvw-card-value rvw-card-value--text"><?= portal_escape($processLabel) ?></span>
                </summary>
                <div class="rvw-card-body">
                    <?php if ($isTeacher): ?>
                        <?php if ($processSource === 'file_metadata_only'): ?>
                            <p class="rvw-card-note"><strong>Writing process:</strong> File metadata only</p>
                            <p class="rvw-card-note">Portal writing tracking was not used for this uploaded file.</p>
                        <?php elseif ($processSource === 'mixed'): ?>
                            <p class="rvw-card-note"><strong>Writing process:</strong> Portal tracking and file metadata</p>
                        <?php elseif ($processSource === 'portal_textarea'): ?>
                            <p class="rvw-card-note"><strong>Writing process:</strong> Portal textarea</p>
                        <?php endif; ?>
                        <?php if ($trackingAvailable || ($fileMeta !== null && !empty($fileMeta['available']))): ?>
                            <ul class="rvw-evidence-list">
                                <?php if ($trackingAvailable && $editSeconds > 0): ?>
                                    <li>Editing time in portal: <strong><?= (int) round($editSeconds / 60) ?> min</strong></li>
                                <?php endif; ?>
                                <?php if ($trackingAvailable && $pasteEvents > 0): ?>
                                    <li>Paste events: <strong><?= $pasteEvents ?></strong></li>
                                <?php endif; ?>
                                <?php if ($trackingAvailable && $pastedChars > 0): ?>
                                    <li>Pasted characters: <strong><?= $pastedChars ?></strong></li>
                                <?php endif; ?>
                                <?php if ($fileMeta !== null && ($fileMeta['edit_time_minutes'] ?? null) !== null): ?>
                                    <li>File editing time: <strong><?= (int) $fileMeta['edit_time_minutes'] ?> min</strong></li>
                                <?php endif; ?>
                                <?php if ($fileMeta !== null && ($fileMeta['author'] ?? '') !== ''): ?>
                                    <li>File author: <strong><?= portal_escape((string) $fileMeta['author']) ?></strong></li>
                                <?php endif; ?>
                                <?php if ($fileMeta !== null && ($fileMeta['created_at'] ?? '') !== ''): ?>
                                    <li>Created: <strong><?= portal_escape((string) $fileMeta['created_at']) ?></strong></li>
                                <?php endif; ?>
                                <?php if ($fileMeta !== null && ($fileMeta['modified_at'] ?? '') !== ''): ?>
                                    <li>Last saved: <strong><?= portal_escape((string) $fileMeta['modified_at']) ?></strong></li>
                                <?php endif; ?>
                                <?php if ($fileMeta !== null && ($fileMeta['application'] ?? '') !== ''): ?>
                                    <li>Application: <strong><?= portal_escape((string) $fileMeta['application']) ?></strong></li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($processRev !== null && isset($processRev['risk_signals'])): ?>
                            <?php if (!empty($processRev['risk_signals'])): ?>
                                <p class="rvw-evidence-title">Risk signals</p>
                                <ul class="rvw-evidence-list">
                                    <?php foreach ($processRev['risk_signals'] as $signal): ?>
                                        <li><?= portal_escape((string) $signal) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (!empty($processRev['positive_signals'])): ?>
                                <p class="rvw-evidence-title">Signals consistent with genuine authorship</p>
                                <ul class="rvw-evidence-list">
                                    <?php foreach ($processRev['positive_signals'] as $signal): ?>
                                        <li><?= portal_escape((string) $signal) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php elseif ($processRev !== null && !empty($processRev['signals'])): ?>
                            <ul class="rvw-evidence-list">
                                <?php foreach ($processRev['signals'] as $signal): ?>
                                    <li><?= portal_escape((string) $signal) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="rvw-card-note">Your submission process looked <?= portal_escape(strtolower($processLabel)) ?>.</p>
                    <?php endif; ?>
                </div>
            </details>

            <!-- External AI detection -->
            <div class="rvw-card rvw-card--flat" data-static="1">
                <div class="rvw-card-head">
                    <span class="rvw-card-label">External AI detection</span>
                    <?php if ($extDisabled): ?>
                        <span class="rvw-card-value rvw-card-value--text rvw-card-value--muted">Disabled</span>
                    <?php elseif ($extScore !== null): ?>
                        <span class="rvw-card-value"><?= portal_escape((string) round($extScore, 0)) ?>%</span>
                    <?php else: ?>
                        <span class="rvw-card-value rvw-card-value--text"><?= portal_escape($extStatus !== '' ? $extStatus : 'Not checked') ?></span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php
        return trim((string) ob_get_clean());
    }
}

if (!function_exists('portal_render_submission_review')) {
    /**
     * Turnitin-style review body: submitted content on the left, feedback panel on the right.
     * $annotations is the list of stored annotations for this submission.
     */
    function portal_render_submission_review(array $submission, bool $isTeacher, array $item, array $annotations, string $csrfToken): string
    {
        $subId       = (int) $submission['id'];
        $filename    = (string) ($submission['filename'] ?? '');
        $ext         = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isImage     = portal_submission_is_image($filename);
        $text        = (string) ($submission['submission_text'] ?? '');
        $score       = $submission['score'] ?? null;
        $feedback    = (string) ($submission['feedback'] ?? '');

        // Preview strategy (browser-side loaders in course.php mirror public/view.php)
        if ($isImage) {
            $previewMode = 'image';
            $annKind     = 'image';
        } elseif ($ext === 'pdf') {
            $previewMode = 'pdf';
            $annKind     = 'text';
        } elseif ($ext === 'docx') {
            $previewMode = 'docx';
            $annKind     = 'text';
        } elseif ($ext === 'xlsx') {
            $previewMode = 'xlsx';
            $annKind     = 'text';
        } elseif (in_array($ext, ['ppt', 'pptx', 'pps', 'ppsx'], true)) {
            $previewMode = 'pptx';
            $annKind     = 'text';
        } elseif ($ext === 'txt') {
            $previewMode = 'txt';
            $annKind     = 'text';
        } elseif (in_array($ext, ['doc', 'odp', 'odt', 'ods', 'pot', 'potx'], true)) {
            $previewMode = 'office';
            $annKind     = 'text';
        } elseif (trim($text) !== '') {
            $previewMode = 'text';
            $annKind     = 'text';
        } else {
            $previewMode = 'none';
            $annKind     = 'text';
        }

        ob_start();
        ?>
        <div class="rvw-shell"
             data-submission-id="<?= $subId ?>"
             data-preview-mode="<?= portal_escape($previewMode) ?>"
             data-preview-ext="<?= portal_escape($ext) ?>"
             data-kind="<?= $annKind ?>"
             data-can-annotate="<?= $isTeacher ? '1' : '0' ?>">
            <div class="rvw-main">
                <div class="rvw-main-toolbar">
                    <span class="rvw-file"><?= portal_icon('file', 'icon-xs') ?><?= portal_escape($filename) ?></span>
                    <a href="download.php?sub=<?= $subId ?>" class="rvw-download" target="_blank" rel="noopener">Download</a>
                </div>
                <div class="rvw-doc">
                    <?php if ($previewMode === 'image'): ?>
                        <div class="rvw-image-wrap" data-image-layer>
                            <img src="download.php?sub=<?= $subId ?>&amp;view=1" alt="<?= portal_escape($filename) ?>" class="rvw-image">
                            <div class="rvw-pins" data-pins></div>
                        </div>
                    <?php elseif ($previewMode === 'pdf'): ?>
                        <iframe class="rvw-iframe"
                                src="download.php?sub=<?= $subId ?>&amp;view=1"
                                title="<?= portal_escape($filename) ?>"></iframe>
                    <?php elseif ($previewMode === 'docx'): ?>
                        <div class="rvw-docx-scroll">
                            <div class="rvw-docx-paper docx-content" data-preview-mount>
                                <p class="rvw-doc-loading">Loading document…</p>
                            </div>
                        </div>
                    <?php elseif ($previewMode === 'xlsx'): ?>
                        <div class="rvw-docx-scroll" data-preview-mount>
                            <p class="rvw-doc-loading">Loading spreadsheet…</p>
                        </div>
                    <?php elseif ($previewMode === 'pptx'): ?>
                        <div class="rvw-docx-scroll" data-preview-mount>
                            <p class="rvw-doc-loading">Loading presentation…</p>
                        </div>
                    <?php elseif ($previewMode === 'txt'): ?>
                        <div class="rvw-docx-scroll">
                            <div class="rvw-docx-paper" data-preview-mount>
                                <p class="rvw-doc-loading">Loading…</p>
                            </div>
                        </div>
                    <?php elseif ($previewMode === 'office'): ?>
                        <iframe class="rvw-iframe"
                                src="preview.php?sub=<?= $subId ?>"
                                title="<?= portal_escape($filename) ?>"></iframe>
                    <?php elseif ($previewMode === 'text'): ?>
                        <div class="rvw-text" data-annotate-surface><?= portal_escape($text) ?></div>
                    <?php else: ?>
                        <div class="rvw-doc-empty">
                            <p>No preview is available for this file type.</p>
                            <a href="download.php?sub=<?= $subId ?>" class="button button--sm">Download to view</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($isTeacher && trim($text) !== '' && in_array($previewMode, ['pdf', 'office'], true)): ?>
                        <details class="rvw-text-layer-toggle">
                            <summary>Show extracted text (for text annotations)</summary>
                            <div class="rvw-text-layer" data-annotate-surface><?= portal_escape($text) ?></div>
                        </details>
                    <?php elseif (trim($text) !== '' && in_array($previewMode, ['pdf', 'office'], true)): ?>
                        <div class="rvw-text-layer" data-annotate-surface hidden><?= portal_escape($text) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <aside class="rvw-side">
                <!-- Grade -->
                <section class="rvw-panel">
                    <h4 class="rvw-panel-title">Grade</h4>
                    <?php if ($isTeacher): ?>
                        <div class="rvw-grade-block" data-grade-block>
                            <div class="rvw-grade-view<?= $score !== null ? ' is-visible' : '' ?>">
                                <div class="rvw-grade-posted">
                                    <div class="rvw-grade-posted-head">
                                        <span class="rvw-grade-saved-icon" aria-hidden="true">✓</span>
                                        <div class="rvw-grade-posted-score">
                                            <p class="rvw-grade-saved-label">Grade posted</p>
                                            <span class="rvw-grade-big" data-grade-score-display><?= $score !== null ? (int) $score : '' ?><small>/100</small></span>
                                        </div>
                                    </div>
                                    <div class="rvw-feedback-box<?= $feedback !== '' ? ' is-visible' : '' ?>" data-grade-feedback-view>
                                        <p class="rvw-feedback-box-label">General feedback</p>
                                        <p class="rvw-feedback-box-text" data-grade-feedback-text><?= portal_escape($feedback) ?></p>
                                    </div>
                                    <p class="rvw-grade-no-feedback<?= $feedback === '' ? ' is-visible' : '' ?>" data-grade-no-feedback>No general feedback provided.</p>
                                </div>
                                <button type="button" class="button button--sm button--ghost rvw-grade-edit">Edit grade</button>
                            </div>
                            <form method="POST" class="rvw-grade-form<?= $score !== null ? '' : ' is-visible' ?>" data-grade-form>
                                <input type="hidden" name="_token" value="<?= portal_escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="mark_submission">
                                <input type="hidden" name="submission_id" value="<?= $subId ?>">
                                <div class="rvw-grade-row">
                                    <input type="number" name="score" min="0" max="100" value="<?= $score !== null ? (int) $score : '' ?>" placeholder="0–100" required>
                                    <span class="rvw-grade-max">/ 100</span>
                                </div>
                                <label class="rvw-feedback-label">General feedback</label>
                                <textarea name="feedback" rows="4" maxlength="2000" placeholder="Overall feedback for this submission"><?= portal_escape($feedback) ?></textarea>
                                <div class="rvw-grade-form-actions">
                                    <button type="submit" class="button button--sm" data-grade-submit>Save grade &amp; feedback</button>
                                    <button type="button" class="button button--sm button--ghost rvw-grade-cancel<?= $score !== null ? ' is-visible' : '' ?>">Cancel</button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="rvw-grade-display">
                            <?php if ($score !== null): ?>
                                <span class="rvw-grade-big"><?= (int) $score ?><small>/100</small></span>
                            <?php else: ?>
                                <span class="rvw-grade-pending">Not graded yet</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($feedback !== ''): ?>
                            <div class="rvw-feedback-box is-visible">
                                <p class="rvw-feedback-box-label">Feedback</p>
                                <p class="rvw-feedback-box-text"><?= portal_escape($feedback) ?></p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <!-- Integrity summary -->
                <section class="rvw-panel">
                    <h4 class="rvw-panel-title">AI / integrity review</h4>
                    <?= portal_integrity_summary_cards($submission, $isTeacher) ?>
                </section>

                <!-- Comments -->
                <section class="rvw-panel">
                    <h4 class="rvw-panel-title">
                        Comments
                        <?php if ($isTeacher): ?><span class="rvw-panel-hint"><?= $isImage ? 'Click the image to pin a comment' : 'Select text to add a comment' ?></span><?php endif; ?>
                    </h4>
                    <div class="rvw-comments" data-comments></div>
                </section>
            </aside>

            <script type="application/json" class="rvw-annotations-data"><?= json_encode(array_map(static function (array $a): array {
                return [
                    'id'          => (int) $a['id'],
                    'anchor_type' => (string) $a['anchor_type'],
                    'range_start' => $a['range_start'] !== null ? (int) $a['range_start'] : null,
                    'range_end'   => $a['range_end'] !== null ? (int) $a['range_end'] : null,
                    'quote'       => (string) $a['quote'],
                    'pos_x'       => $a['pos_x'] !== null ? (float) $a['pos_x'] : null,
                    'pos_y'       => $a['pos_y'] !== null ? (float) $a['pos_y'] : null,
                    'comment'     => (string) $a['comment'],
                    'author'      => (string) ($a['author_name'] ?? ''),
                ];
            }, $annotations), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
        </div>
        <?php
        return trim((string) ob_get_clean());
    }
}

if (!function_exists('portal_rerun_submission_integrity')) {
    function portal_rerun_submission_integrity(PDO $db, int $submissionId, bool $runAi = true): bool
    {
        $stmt = $db->prepare(
            "SELECT cs.*, cfi.submission_ai_detection, u.name AS student_name
             FROM course_submissions cs
             JOIN course_folder_items cfi ON cfi.id = cs.item_id
             LEFT JOIN users u ON u.id = cs.user_id
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

        if ($runAi && portal_external_ai_should_run([
            'course_id' => (int) ($submission['course_id'] ?? 0),
            'submission_ai_detection' => (int) ($submission['submission_ai_detection'] ?? 0),
        ])) {
            $ai = portal_gptzero_detection($text);
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
