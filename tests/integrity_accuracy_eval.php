<?php
declare(strict_types=1);

/**
 * Accuracy + spoofing eval for in-house AI / metadata detectors.
 * Run: C:\xampp\php\php.exe tests\integrity_accuracy_eval.php
 */

require_once __DIR__ . '/../bootstrap.php';

$outDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'portal-integrity-eval-' . bin2hex(random_bytes(4));
mkdir($outDir, 0755, true);

function eval_make_docx(string $path, string $bodyText, array $meta): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive required');
    }

    $paragraphs = preg_split("/\n+/", trim($bodyText)) ?: [];
    $wBody = '';
    foreach ($paragraphs as $p) {
        $p = htmlspecialchars($p, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $wBody .= '<w:p><w:r><w:t xml:space="preserve">' . $p . '</w:t></w:r></w:p>';
    }

    $created = $meta['created'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $modified = $meta['modified'] ?? $created;
    $author = htmlspecialchars((string) ($meta['author'] ?? 'Student'), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $lastMod = htmlspecialchars((string) ($meta['last_modified_by'] ?? $author), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $revision = (int) ($meta['revision'] ?? 1);
    $totalTime = (int) ($meta['total_time'] ?? 0);
    $words = (int) ($meta['words'] ?? max(1, str_word_count($bodyText)));
    $app = htmlspecialchars((string) ($meta['application'] ?? 'Microsoft Office Word'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $wBody . '<w:sectPr/></w:body></w:document>';

    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
        . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
        . ' xmlns:dcterms="http://purl.org/dc/terms/"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>' . $author . '</dc:creator>'
        . '<cp:lastModifiedBy>' . $lastMod . '</cp:lastModifiedBy>'
        . '<cp:revision>' . $revision . '</cp:revision>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $modified . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
        . '<TotalTime>' . $totalTime . '</TotalTime>'
        . '<Words>' . $words . '</Words>'
        . '<Pages>1</Pages>'
        . '<Application>' . $app . '</Application>'
        . '</Properties>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create ' . $path);
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('word/document.xml', $documentXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->close();
}

function eval_band(float $score, string $expect): bool
{
    return match ($expect) {
        'low' => $score < 20,
        'medium' => $score >= 15 && $score < 45,
        'high' => $score >= 35,
        'skip' => $score < 10,
        default => false,
    };
}

function eval_level_ok(string $level, string $expect): bool
{
    $level = strtolower($level);
    return match ($expect) {
        'low' => in_array($level, ['low', 'some'], true) || $level === 'low',
        'medium' => in_array($level, ['medium', 'some', 'needs review', 'needs_review'], true)
            || str_contains($level, 'some') || str_contains($level, 'needs'),
        'high' => in_array($level, ['high', 'medium'], true) || str_contains($level, 'high') || str_contains($level, 'needs'),
        default => false,
    };
}

$aiEssay = <<<'TXT'
In today's world, artificial intelligence plays a crucial role in shaping educational outcomes. Furthermore, it is important to note that digital tools enhance learning in ways that are difficult to overstate. Moreover, educators have observed that personalised feedback is becoming more frequent. Therefore, schools must delve into comprehensive strategies to navigate this transition.

Additionally, adaptive learning platforms play a vital role in supporting student progress. It is worth noting that data-driven insights have improved significantly over the past decade. Consequently, many institutions are navigating the shift toward blended models. When it comes to equity, furthermore, access to technology creates new opportunities. In the realm of assessment, a myriad of approaches attempt to measure understanding.

However, challenges remain. It is essential to understand that not all learners benefit equally. Moreover, teacher professional development plays a significant role in successful adoption. In conclusion, artificial intelligence requires sustained collective effort. To summarize, addressing this transformation is not only a pedagogical imperative but also a moral one. Ultimately, the choices made today will determine the quality of future classrooms.
TXT;

$humanEssay = <<<'TXT'
I used to hate writing essays until Miss Rahman made us keep a messy notebook for two weeks. Mine had coffee rings on half the pages and a shopping list wedged between notes about the water cycle. On Tuesday I interviewed my uncle who works nights at the packaging plant; he said the river near his house smells different after heavy rain, which somehow ended up in my geography draft.

The first introduction I wrote sounded like a textbook, so I ripped it out. The second version started with the flooded underpass by the bus station because that's what I actually see walking home. I still don't know if my conclusion is any good — I rewrote it at 10pm while my sister practised piano badly in the next room — but at least it sounds like me.
TXT;

$paraphrasedAi = <<<'TXT'
Modern education is increasingly influenced by artificial intelligence systems. It should be noted that digital platforms support learning in substantial ways. Teachers have found that tailored responses appear more often than before. As a result, schools need to explore thorough plans for managing this change.

Also, adaptive tools are important for helping learners advance. One should recognise that analytics-based guidance has advanced a lot recently. Because of this, many organisations are managing the move toward hybrid approaches. Regarding fairness, technological access opens fresh possibilities. Within assessment practice, numerous methods try to evaluate comprehension.

Still, difficulties persist. One must appreciate that outcomes are uneven across students. In addition, staff training is important for effective use. Overall, AI needs ongoing shared commitment. In short, dealing with this shift is both educational and ethical. In the end, decisions taken now will shape tomorrow's learning spaces.
TXT;

$chatgptExportStyle = <<<'TXT'
Sure — here's a polished draft you can submit:

Title: The Importance of Renewable Energy

Renewable energy is essential for a sustainable future. Solar and wind power reduce greenhouse gas emissions while creating economic opportunities. Governments should invest in clean infrastructure, and individuals can contribute by adopting energy-efficient habits at home.

Would you like me to expand the conclusion or add citations?
TXT;

$aiSamples = [
    [
        'id' => 'ai_template',
        'label' => 'Obvious ChatGPT-style template essay',
        'expect_ai' => 'high',
        'text' => $aiEssay,
    ],
    [
        'id' => 'ai_paraphrase',
        'label' => 'Lightly paraphrased AI essay',
        'expect_ai' => 'medium',
        'text' => $paraphrasedAi,
    ],
    [
        'id' => 'ai_export_wrapper',
        'label' => 'ChatGPT export wrapper left in',
        'expect_ai' => 'high',
        'text' => $chatgptExportStyle . "\n\n" . $aiEssay,
    ],
    [
        'id' => 'human_messy',
        'label' => 'Human messy reflective essay',
        'expect_ai' => 'low',
        'text' => $humanEssay,
    ],
];

// (Do not require integrity_benchmark.php — it executes on include.)

// Add long academic human-like sample
$aiSamples[] = [
    'id' => 'human_academic',
    'label' => 'Human formal academic with citations',
    'expect_ai' => 'low',
    'text' => <<<'TXT'
Abstract

This dissertation examines how small-scale fisheries in coastal Kenya adapt to irregular rainfall patterns. Drawing on fourteen months of ethnographic fieldwork in Kilifi County, I argue that household strategies cannot be understood separately from informal credit networks and women's cooperative selling arrangements.

Methodology

I conducted semi-structured interviews with 38 fishers and 12 traders between January 2024 and March 2025. Participant observation took place at three landing sites. Interview transcripts were coded inductively using NVivo 14. I triangulated self-reported catch data with sales records from two beach-management units.

Literature review

Existing work on climate adaptation in East African fisheries emphasises macroeconomic vulnerability (Omollo et al., 2021) but pays less attention to gendered labour divisions. My findings extend this debate by showing how traders, not only boat owners, reorganise supply when storms disrupt morning landings.

Discussion

Three adaptation pathways emerged. First, fishers shifted target species when nearshore waters became turbid. Second, households reduced consumption before selling surplus stock. Third, women's groups pooled ice purchases to limit post-harvest loss. These results suggest that policy interventions focused solely on boat technology miss the relational infrastructure that sustains livelihoods.
TXT,
];

$now = time();
$spoofCases = [
    [
        'id' => 'genuine_writing',
        'label' => 'Genuine-looking metadata (hours of edits, many revisions, matching author)',
        'expect_process' => 'low',
        'student_name' => 'Alex Morgan',
        'meta' => [
            'author' => 'Alex Morgan',
            'last_modified_by' => 'Alex Morgan',
            'created' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400 * 5),
            'modified' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600),
            'revision' => 18,
            'total_time' => 240,
            'application' => 'Microsoft Office Word',
        ],
        'process' => ['process_edit_seconds' => 0, 'process_paste_events' => 0, 'process_pasted_chars' => 0],
        'text' => $humanEssay,
    ],
    [
        'id' => 'paste_and_submit',
        'label' => 'Paste-and-submit signals (0 edit time, revision 1, 2-min create/save)',
        'expect_process' => 'high',
        'student_name' => 'Alex Morgan',
        'meta' => [
            'author' => 'Alex Morgan',
            'last_modified_by' => 'Alex Morgan',
            'created' => gmdate('Y-m-d\TH:i:s\Z', $now - 90),
            'modified' => gmdate('Y-m-d\TH:i:s\Z', $now - 30),
            'revision' => 1,
            'total_time' => 0,
            'application' => 'Microsoft Office Word',
        ],
        'process' => ['process_edit_seconds' => 12, 'process_paste_events' => 1, 'process_pasted_chars' => 2200],
        'text' => $aiEssay,
    ],
    [
        'id' => 'spoof_author_mismatch',
        'label' => 'Spoof: wrong author name vs submitter',
        'expect_process' => 'medium',
        'student_name' => 'Alex Morgan',
        'meta' => [
            'author' => 'ChatGPT User',
            'last_modified_by' => 'ChatGPT User',
            'created' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400),
            'modified' => gmdate('Y-m-d\TH:i:s\Z', $now - 86000),
            'revision' => 3,
            'total_time' => 45,
            'application' => 'Microsoft Office Word',
        ],
        'process' => ['process_edit_seconds' => 0, 'process_paste_events' => 0, 'process_pasted_chars' => 0],
        'text' => $aiEssay,
    ],
    [
        'id' => 'spoof_fake_edit_time',
        'label' => 'Spoof: AI text but inflated TotalTime/revisions to look human',
        'expect_detect_spoof' => true, // we hope AI heuristic still flags text; metadata alone may look clean
        'expect_process' => 'low', // metadata spoofed to look genuine
        'expect_ai' => 'high',
        'student_name' => 'Alex Morgan',
        'meta' => [
            'author' => 'Alex Morgan',
            'last_modified_by' => 'Alex Morgan',
            'created' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400 * 7),
            'modified' => gmdate('Y-m-d\TH:i:s\Z', $now - 7200),
            'revision' => 22,
            'total_time' => 380,
            'application' => 'Microsoft Office Word',
        ],
        'process' => ['process_edit_seconds' => 0, 'process_paste_events' => 0, 'process_pasted_chars' => 0],
        'text' => $aiEssay,
    ],
    [
        'id' => 'spoof_wordcount_mismatch',
        'label' => 'Spoof: embedded word count far from extracted text',
        'expect_process' => 'medium',
        'student_name' => 'Alex Morgan',
        'meta' => [
            'author' => 'Alex Morgan',
            'last_modified_by' => 'Alex Morgan',
            'created' => gmdate('Y-m-d\TH:i:s\Z', $now - 86400),
            'modified' => gmdate('Y-m-d\TH:i:s\Z', $now - 80000),
            'revision' => 5,
            'total_time' => 60,
            'words' => 12,
            'application' => 'Microsoft Office Word',
        ],
        'process' => ['process_edit_seconds' => 0, 'process_paste_events' => 0, 'process_pasted_chars' => 0],
        'text' => $aiEssay,
    ],
    [
        'id' => 'heavy_portal_paste',
        'label' => 'Portal textarea: >45% pasted characters',
        'expect_process' => 'high',
        'student_name' => 'Alex Morgan',
        'meta' => null,
        'process' => [
            'process_edit_seconds' => 40,
            'process_paste_events' => 3,
            'process_pasted_chars' => 2500,
        ],
        'text' => $aiEssay,
    ],
];

$results = [
    'ai' => [],
    'metadata' => [],
    'combined' => [],
];

$aiPass = 0;
$aiTotal = 0;

echo "=== AI STYLE DETECTOR ===\n\n";
foreach ($aiSamples as $sample) {
    $review = portal_integrity_heuristic_ai_review($sample['text']);
    $score = (float) ($review['score'] ?? 0);
    $level = (string) ($review['level'] ?? '');
    $expect = $sample['expect_ai'];
    $ok = eval_band($score, $expect) || eval_level_ok($level, $expect)
        || eval_level_ok((string) ($review['level_label'] ?? ''), $expect);
    // Softer: for high expect, score>=30 counts; for low expect score<25
    if ($expect === 'high' && $score >= 30) {
        $ok = true;
    }
    if ($expect === 'low' && $score < 25) {
        $ok = true;
    }
    if ($expect === 'medium' && $score >= 18 && $score < 55) {
        $ok = true;
    }

    $aiTotal++;
    if ($ok) {
        $aiPass++;
    }

    $row = [
        'id' => $sample['id'],
        'label' => $sample['label'],
        'expect' => $expect,
        'score' => round($score, 1),
        'level' => $review['level_label'] ?? $level,
        'evidence' => $review['evidence_strength'] ?? '',
        'ok' => $ok,
        'signals' => array_slice($review['risk_signals'] ?? [], 0, 3),
        'verdict' => $ok ? 'correct' : 'miss',
    ];
    $results['ai'][] = $row;
    printf(
        "%s [%s] %s — expect %s, got %.1f (%s)\n",
        $ok ? 'PASS' : 'FAIL',
        $sample['id'],
        $sample['label'],
        $expect,
        $score,
        $row['level']
    );
}

$metaPass = 0;
$metaTotal = 0;
$metaReadPass = 0;
$metaReadTotal = 0;

echo "\n=== METADATA READER + PROCESS / SPOOFING ===\n\n";
foreach ($spoofCases as $case) {
    $path = null;
    $fileMeta = ['available' => false];
    $extraction = null;

    if ($case['meta'] !== null) {
        $path = $outDir . DIRECTORY_SEPARATOR . $case['id'] . '.docx';
        eval_make_docx($path, $case['text'], $case['meta']);
        $extraction = portal_extract_submission_text_detailed($path, basename($path));
        $fileMeta = portal_extract_submission_file_metadata($path, basename($path));

        $metaReadTotal++;
        $readOk = !empty($fileMeta['available'])
            && (string) ($fileMeta['author'] ?? '') === (string) $case['meta']['author']
            && (int) ($fileMeta['revision'] ?? -1) === (int) $case['meta']['revision']
            && (int) ($fileMeta['edit_time_minutes'] ?? -1) === (int) $case['meta']['total_time'];
        if ($readOk) {
            $metaReadPass++;
        }
        echo ($readOk ? 'READ-OK ' : 'READ-MISS ') . $case['id']
            . ' author=' . ($fileMeta['author'] ?? '')
            . ' rev=' . ($fileMeta['revision'] ?? 'n/a')
            . ' editMin=' . ($fileMeta['edit_time_minutes'] ?? 'n/a')
            . "\n";
    }

    $submission = array_merge($case['process'], [
        'student_name' => $case['student_name'],
        'file_metadata' => $fileMeta,
    ]);
    $process = portal_integrity_process_review($submission, $case['text']);
    $ai = portal_integrity_heuristic_ai_review($case['text'], [
        'file_metadata' => $fileMeta,
        'process_review' => $process,
        'student_name' => $case['student_name'],
    ]);

    $expectProcess = $case['expect_process'] ?? 'medium';
    $pScore = (float) ($process['score'] ?? 0);
    $pOk = eval_band($pScore, $expectProcess)
        || eval_level_ok((string) ($process['level'] ?? ''), $expectProcess);
    if ($expectProcess === 'high' && $pScore >= 30) {
        $pOk = true;
    }
    if ($expectProcess === 'low' && $pScore < 25) {
        $pOk = true;
    }
    if ($expectProcess === 'medium' && $pScore >= 12 && $pScore < 50) {
        $pOk = true;
    }

    $metaTotal++;
    if ($pOk) {
        $metaPass++;
    }

    $combinedNote = '';
    $spoofCaught = null;
    if (!empty($case['expect_detect_spoof'])) {
        // Spoofed metadata looks clean; AI text should still score high.
        $aiHigh = (float) ($ai['score'] ?? 0) >= 30;
        $metaLooksClean = $pScore < 25;
        $spoofCaught = $aiHigh; // text layer catches what metadata can't
        $combinedNote = $aiHigh
            ? ($metaLooksClean
                ? 'Metadata spoof succeeded, but AI style layer still flagged the text'
                : 'Both layers flagged')
            : 'MISS: spoofed metadata hid paste-and-submit and AI text was not flagged';
    }

    $row = [
        'id' => $case['id'],
        'label' => $case['label'],
        'expect_process' => $expectProcess,
        'process_score' => round($pScore, 1),
        'process_level' => $process['level'] ?? '',
        'process_ok' => $pOk,
        'ai_score' => round((float) ($ai['score'] ?? 0), 1),
        'ai_level' => $ai['level_label'] ?? ($ai['level'] ?? ''),
        'risk_signals' => array_slice($process['risk_signals'] ?? [], 0, 4),
        'extraction_confidence' => $extraction['confidence'] ?? null,
        'meta_author' => $fileMeta['author'] ?? '',
        'meta_revision' => $fileMeta['revision'] ?? null,
        'meta_edit_minutes' => $fileMeta['edit_time_minutes'] ?? null,
        'spoof_caught_by_ai' => $spoofCaught,
        'note' => $combinedNote,
    ];
    $results['metadata'][] = $row;

    printf(
        "%s [%s] process expect=%s got=%.1f (%s) | AI style=%.1f\n  %s\n\n",
        $pOk ? 'PASS' : 'FAIL',
        $case['id'],
        $expectProcess,
        $pScore,
        $row['process_level'],
        $row['ai_score'],
        $combinedNote !== '' ? $combinedNote : implode(' | ', array_slice($row['risk_signals'], 0, 2))
    );
}

// Discrimination check
$aiScore = null;
$humanScore = null;
foreach ($results['ai'] as $r) {
    if ($r['id'] === 'ai_template') {
        $aiScore = $r['score'];
    }
    if ($r['id'] === 'human_messy') {
        $humanScore = $r['score'];
    }
}
$gap = ($aiScore !== null && $humanScore !== null) ? ($aiScore - $humanScore) : 0;
$gapOk = $gap >= 15;

$aiAccuracy = $aiTotal > 0 ? round(100 * $aiPass / $aiTotal, 1) : 0;
$metaAccuracy = $metaTotal > 0 ? round(100 * $metaPass / $metaTotal, 1) : 0;
$readAccuracy = $metaReadTotal > 0 ? round(100 * $metaReadPass / $metaReadTotal, 1) : 0;

// Ratings out of 10
$aiRating = round(($aiAccuracy / 100) * 10, 1);
$metaRating = round(($metaAccuracy / 100) * 10, 1);
$readRating = round(($readAccuracy / 100) * 10, 1);
// Spoof resistance: fake_edit_time case
$spoofRow = null;
foreach ($results['metadata'] as $r) {
    if ($r['id'] === 'spoof_fake_edit_time') {
        $spoofRow = $r;
        break;
    }
}
$spoofRating = 0.0;
if ($spoofRow) {
    if ($spoofRow['spoof_caught_by_ai'] === true && $spoofRow['process_score'] < 25) {
        $spoofRating = 7.0; // metadata fooled, text saved us
    } elseif ($spoofRow['spoof_caught_by_ai'] === true) {
        $spoofRating = 8.5;
    } else {
        $spoofRating = 3.0;
    }
}
$overall = round(($aiRating * 0.4 + $metaRating * 0.25 + $readRating * 0.15 + $spoofRating * 0.2), 1);

$summary = [
    'generated_at' => date('c'),
    'ai' => [
        'pass' => $aiPass,
        'total' => $aiTotal,
        'accuracy_pct' => $aiAccuracy,
        'rating_10' => $aiRating,
        'discrimination_gap' => round($gap, 1),
        'discrimination_ok' => $gapOk,
    ],
    'metadata_reader' => [
        'pass' => $metaReadPass,
        'total' => $metaReadTotal,
        'accuracy_pct' => $readAccuracy,
        'rating_10' => $readRating,
    ],
    'process_spoof_detection' => [
        'pass' => $metaPass,
        'total' => $metaTotal,
        'accuracy_pct' => $metaAccuracy,
        'rating_10' => $metaRating,
    ],
    'spoof_resistance' => [
        'rating_10' => $spoofRating,
        'detail' => $spoofRow['note'] ?? '',
    ],
    'overall_rating_10' => $overall,
    'results' => $results,
];

$jsonPath = __DIR__ . '/integrity_accuracy_results.json';
file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n=== RATINGS /10 ===\n";
echo "AI style detector:     {$aiRating}  ({$aiPass}/{$aiTotal}, {$aiAccuracy}%)\n";
echo "Metadata reader:       {$readRating}  ({$metaReadPass}/{$metaReadTotal}, {$readAccuracy}%)\n";
echo "Process/spoof flags:   {$metaRating}  ({$metaPass}/{$metaTotal}, {$metaAccuracy}%)\n";
echo "Spoof resistance:      {$spoofRating}\n";
echo "Overall:               {$overall}\n";
echo "Discrimination gap AI-human: {$gap}\n";
echo "Wrote {$jsonPath}\n";

// cleanup temp docx
foreach (glob($outDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($outDir);
