<?php
declare(strict_types=1);

/**
 * Benchmark harness for in-house integrity tools (heuristic AI + process + similarity).
 * Run: php tests/integrity_benchmark.php
 */

require_once __DIR__ . '/../integrity.php';

// ── Sample texts ─────────────────────────────────────────────────────────────

$samples = [
    'human_casual_essay' => [
        'label' => 'Human casual reflective essay (first-person, varied)',
        'expect_ai' => 'low',
        'text' => <<<'TXT'
When I started secondary school I honestly thought geography would be boring. My older brother kept telling me it was just colouring maps and memorising capital cities, and I believed him until Mr Patel made us walk around the estate with clipboards. We had to note where the bus stopped, where the corner shop was, and which streets flooded after heavy rain. I didn't expect to care, but I kept thinking about how the council had built the new flats on the lowest ground.

That week changed how I look at my neighbourhood. I started noticing things I'd walked past for years: the drain that always blocks, the tree roots pushing up the pavement near the post office, the way the park feels cooler in summer because of all the oak trees. For my project I interviewed my neighbour Mrs Okonkwo, who has lived here since the 1970s. She told me stories about the market that used to be where the car park is now. I included her quotes because they made the place feel real, not like a textbook case study.

In the end I got a B+, which I'm proud of because I actually worked on it over three evenings. I rewrote the introduction twice because the first version sounded too formal. Geography still isn't my favourite subject, but I understand now that places aren't fixed — people change them, and sometimes the changes create problems nobody planned for. I'd like to study urban planning one day, maybe, though I'm not totally sure yet.
TXT,
    ],

    'obvious_ai_template' => [
        'label' => 'Obvious AI-style template essay',
        'expect_ai' => 'high',
        'text' => <<<'TXT'
In today's world, climate change plays a crucial role in shaping global policy and everyday life. Furthermore, it is important to note that rising temperatures affect ecosystems in ways that are difficult to predict. Moreover, scientists have observed that extreme weather events are becoming more frequent. Therefore, governments must delve into comprehensive strategies to mitigate environmental damage.

Additionally, renewable energy plays a vital role in reducing carbon emissions. It is worth noting that solar and wind technologies have improved significantly over the past decade. Consequently, many nations are navigating the transition away from fossil fuels. When it comes to economic impacts, furthermore, green investment creates new opportunities. In the realm of international cooperation, a myriad of treaties attempt to coordinate action.

However, challenges remain. It is essential to understand that developing countries often face disproportionate burdens. Moreover, adaptation funding plays a significant role in equitable outcomes. In conclusion, climate change requires sustained collective effort. To summarize, addressing this crisis is not only an environmental imperative but also a moral one. Ultimately, the choices made today will determine the stability of future generations. This essay will examine key factors and propose practical responses.
TXT,
    ],

    'human_academic_dissertation_style' => [
        'label' => 'Human formal academic (citations, technical)',
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

Conclusion

Adaptive capacity in Kilifi is distributed across kin and cooperative ties rather than held by individual entrepreneurs. Future research should compare these findings with Tanzanian landing sites where credit institutions differ markedly.
TXT,
    ],

    'human_creative_story' => [
        'label' => 'Human creative narrative (dialogue, specific details)',
        'expect_ai' => 'low',
        'text' => <<<'TXT'
"You're holding it wrong," said Amara, leaning over my shoulder in the library on Thursday afternoon. I was trying to fold a paper crane for the Year 9 display board, and every crease looked like a crushed crisp packet.

"I'm not," I muttered, though I probably was. The heating had broken again, so we wore our coats indoors. Mr Lewis walked past twice pretending not to notice us eating biscuits behind the encyclopaedias.

Amara and I hadn't really spoken before the geography trip to the reservoir. She sat alone on the coach and read a book about volcanoes with a cracked spine. I thought she was intimidating until she lent me her spare gloves when mine got soaked. On the way back she told me her mum works night shifts at the hospital and sometimes forgets to buy milk.

We started meeting on Tuesdays to revise, though we mostly talked. She wanted to be a paramedic. I wanted to design video games, which she said was "still saving people, just virtually." When the crane finally looked like a bird, she taped it above the fiction shelf where the Year 7s would see it. It fell down on Friday and hit Ethan on the head, which made everyone laugh, including Ethan.

I'm not sure we'd call ourselves best friends yet, but I saved her a seat on the 8:15 bus this morning without thinking about it. That felt like something.
TXT,
    ],

    'ai_educational_explainer' => [
        'label' => 'AI-style educational expository explainer',
        'expect_ai' => 'high',
        'text' => <<<'TXT'
Photosynthesis is one of the most important biological processes on Earth. The basic idea is simple: plants use sunlight to make food. This process mainly takes place in the leaves, where chlorophyll captures light energy. Therefore, without photosynthesis, most life on Earth could not survive.

Photosynthesis can be divided into two main stages. The first stage is known as the light-dependent reactions. This means that light energy is converted into chemical energy. The second stage is the Calvin cycle, also known as the light-independent reactions. In this stage, carbon dioxide is used to build glucose.

Several factors affect the rate of photosynthesis. One of these factors is light intensity. Another factor is carbon dioxide concentration. Another important factor is temperature. For example, if temperature becomes too high, enzymes may denature. As a result, the rate of photosynthesis decreases.

Plants need water as well. Water is also important because it provides electrons during the light reactions. In addition, water helps maintain turgor pressure in plant cells. Over time, scientists have studied how different species adapt to varying light conditions.

In conclusion, photosynthesis is also the foundation of most food chains. It is also important for producing oxygen in the atmosphere. Studying photosynthesis helps us understand ecosystems and agriculture. This discovery connects biology, chemistry, and environmental science in a meaningful way.
TXT,
    ],

    'human_educational_explainer' => [
        'label' => 'Human educational explainer (less scaffolded)',
        'expect_ai' => 'low',
        'text' => <<<'TXT'
Our teacher asked us to explain photosynthesis without copying the textbook, which was harder than it sounds. I drew a diagram of a leaf with arrows for sunlight, water, and carbon dioxide because pictures help me remember things.

From what I understood in class, the leaf uses chlorophyll to trap light, and that energy splits water so oxygen escapes — which is why plants are useful in classrooms, according to Mr Adeyemi. The tricky part is the Calvin cycle; I kept mixing up which stage needs light directly. Maria explained it using a factory metaphor: the light reactions charge the batteries, and the Calvin cycle spends that charge to build sugar.

We tested rate of photosynthesis with pondweed in beakers. When we moved the lamp closer, bubbles increased until the lamp got too hot and the teacher made us stop. My lab partner thought temperature didn't matter, but our results showed it did once we left the beaker under the lamp for ten minutes.

I still mix up the word equation sometimes, but I can describe the process in my own words now, which feels like progress compared to last term.
TXT,
    ],

    'too_short' => [
        'label' => 'Too short for review',
        'expect_ai' => 'skip',
        'text' => 'Climate change is bad. We should fix it. Plants help.',
    ],
];

// ── Process review scenarios ─────────────────────────────────────────────────

$processCases = [
    'healthy_editing' => [
        'label' => 'Healthy editing session',
        'expect' => 'low',
        'submission' => [
            'process_edit_seconds' => 2400,
            'process_paste_events' => 0,
            'process_pasted_chars' => 0,
            'student_name' => 'Jamie Smith',
            'file_metadata' => [
                'available' => true,
                'author' => 'Jamie Smith',
                'edit_time_minutes' => 35,
                'revision' => 8,
                'application' => 'Microsoft Word',
                'created_at_ts' => time() - 7200,
                'modified_at_ts' => time() - 600,
                'format' => 'docx',
            ],
        ],
        'text' => $samples['human_casual_essay']['text'],
    ],
    'paste_and_submit' => [
        'label' => 'Paste-heavy suspicious submission',
        'expect' => 'high',
        'submission' => [
            'process_edit_seconds' => 45,
            'process_paste_events' => 12,
            'process_pasted_chars' => 4500,
            'student_name' => 'Jamie Smith',
            'file_metadata' => [
                'available' => true,
                'author' => 'ChatGPT',
                'edit_time_minutes' => 0,
                'revision' => 1,
                'application' => 'Microsoft Word',
                'created_at_ts' => time() - 30,
                'modified_at_ts' => time() - 5,
                'format' => 'docx',
            ],
        ],
        'text' => $samples['obvious_ai_template']['text'],
    ],
    'wrong_author' => [
        'label' => 'Author metadata mismatch',
        'expect' => 'medium',
        'submission' => [
            'process_edit_seconds' => 900,
            'process_paste_events' => 1,
            'process_pasted_chars' => 200,
            'student_name' => 'Jamie Smith',
            'file_metadata' => [
                'available' => true,
                'author' => 'OpenAI User',
                'last_modified_by' => 'Admin',
                'edit_time_minutes' => 2,
                'revision' => 1,
                'format' => 'docx',
            ],
        ],
        'text' => $samples['human_casual_essay']['text'],
    ],
];

// ── Similarity pairs ─────────────────────────────────────────────────────────

$similarityPairs = [
    'exact_copy' => [
        'label' => 'Exact copy',
        'expect' => 'high',
        'source' => $samples['human_casual_essay']['text'],
        'submission' => $samples['human_casual_essay']['text'],
    ],
    'unrelated' => [
        'label' => 'Unrelated texts',
        'expect' => 'low',
        'source' => $samples['human_casual_essay']['text'],
        'submission' => 'Quantum computing uses qubits that can exist in superposition. Shor\'s algorithm threatens RSA encryption. Error correction remains the main engineering challenge for practical devices.',
    ],
    'same_topic_different_wording' => [
        'label' => 'Same topic, different wording (should not over-flag)',
        'expect' => 'low',
        'source' => 'Photosynthesis converts light energy into chemical energy in plants. Chlorophyll in leaves absorbs sunlight. The process produces glucose and releases oxygen as a byproduct.',
        'submission' => 'In biology class we learned that leaves use chlorophyll to capture sunlight and turn it into sugar, which is basically photosynthesis. Oxygen gets released too, which is why people keep plants in offices.',
    ],
    'partial_copy' => [
        'label' => 'Partial paragraph copy',
        'expect' => 'medium',
        'source' => $samples['human_casual_essay']['text'],
        'submission' => 'Introduction to my report.' . "\n\n" . explode("\n\n", $samples['human_casual_essay']['text'])[1] . "\n\n" . 'This is my own conclusion about urban planning.',
    ],
];

// ── Run tests ────────────────────────────────────────────────────────────────

function level_ok(string $actual, string $expected): bool
{
    if ($expected === 'skip') {
        return $actual === 'low' && true; // score 0 / low is acceptable
    }
    return $actual === $expected || ($expected === 'medium' && in_array($actual, ['medium', 'high'], true));
}

function score_band(?float $score, string $band): bool
{
    if ($score === null) {
        return $band === 'skip';
    }
    return match ($band) {
        'skip' => $score < 8,
        'low' => $score < 15,
        'medium' => $score >= 15 && $score < 35,
        'high' => $score >= 35,
        default => false,
    };
}

$results = ['ai' => [], 'process' => [], 'similarity' => []];
$pass = 0;
$total = 0;

echo "=== HEURISTIC AI REVIEW ===\n\n";

foreach ($samples as $key => $sample) {
    $review = portal_integrity_heuristic_ai_review($sample['text']);
    $score = (float) $review['score'];
    $level = (string) $review['level'];
    $expect = $sample['expect_ai'];
    $ok = $expect === 'skip'
        ? ($score < 8 && $level === 'low')
        : score_band($score, $expect) || level_ok($level, $expect);

    $total++;
    if ($ok) {
        $pass++;
    }

    $results['ai'][$key] = [
        'label' => $sample['label'],
        'expect' => $expect,
        'score' => $score,
        'level' => $level,
        'level_label' => $review['level_label'] ?? '',
        'evidence' => $review['evidence_strength'] ?? '',
        'ok' => $ok,
        'top_signals' => array_slice($review['risk_signals'] ?? [], 0, 3),
        'context' => [
            'academic' => $review['metrics']['is_academic_technical'] ?? null,
            'creative' => $review['metrics']['is_creative_narrative'] ?? null,
            'expository' => $review['metrics']['is_educational_expository'] ?? null,
        ],
    ];

    printf(
        "%s %s\n  expect=%s score=%.1f level=%s evidence=%s\n  signals: %s\n\n",
        $ok ? '[PASS]' : '[FAIL]',
        $sample['label'],
        $expect,
        $score,
        $level,
        $review['evidence_strength'] ?? 'n/a',
        implode(' | ', array_slice($review['risk_signals'] ?? [], 0, 2))
    );
}

echo "=== PROCESS REVIEW ===\n\n";

foreach ($processCases as $key => $case) {
    $review = portal_integrity_process_review($case['submission'], $case['text']);
    $score = (float) $review['score'];
    $level = (string) $review['level'];
    $expect = $case['expect'];
    $ok = score_band($score, $expect) || level_ok($level, $expect);

    $total++;
    if ($ok) {
        $pass++;
    }

    $results['process'][$key] = [
        'label' => $case['label'],
        'expect' => $expect,
        'score' => $score,
        'level' => $level,
        'ok' => $ok,
        'risk_signals' => array_slice($review['risk_signals'] ?? [], 0, 4),
    ];

    printf(
        "%s %s\n  expect=%s score=%.1f level=%s\n  risks: %s\n\n",
        $ok ? '[PASS]' : '[FAIL]',
        $case['label'],
        $expect,
        $score,
        $level,
        implode(' | ', array_slice($review['risk_signals'] ?? [], 0, 2))
    );
}

echo "=== SIMILARITY (pair score) ===\n\n";

foreach ($similarityPairs as $key => $pair) {
    $subNorm = portal_integrity_normalize_text($pair['submission']);
    $srcNorm = portal_integrity_normalize_text($pair['source']);
    $result = portal_integrity_pair_score($subNorm, $srcNorm);
    $score = (float) $result['score'];
    $expect = $pair['expect'];
    $ok = score_band($score, $expect);

    $total++;
    if ($ok) {
        $pass++;
    }

    $results['similarity'][$key] = [
        'label' => $pair['label'],
        'expect' => $expect,
        'score' => $score,
        'method' => $result['method'],
        'ok' => $ok,
    ];

    printf(
        "%s %s\n  expect=%s score=%.1f method=%s\n\n",
        $ok ? '[PASS]' : '[FAIL]',
        $pair['label'],
        $expect,
        $score,
        $result['method']
    );
}

// False-positive stress: human academic should score lower than AI template
$humanAcademic = portal_integrity_heuristic_ai_review($samples['human_academic_dissertation_style']['text']);
$aiTemplate = portal_integrity_heuristic_ai_review($samples['obvious_ai_template']['text']);
$discriminationOk = (float) $aiTemplate['score'] > (float) $humanAcademic['score'] + 10;
$total++;
if ($discriminationOk) {
    $pass++;
}
printf(
    "%s Discrimination: AI template (%.1f) vs human academic (%.1f) — gap >= 10\n\n",
    $discriminationOk ? '[PASS]' : '[FAIL]',
    $aiTemplate['score'],
    $humanAcademic['score']
);

$accuracy = $total > 0 ? round(($pass / $total) * 100, 1) : 0;
$rating = round(($pass / max(1, $total)) * 5, 1);

echo "=== SUMMARY ===\n";
echo "Passed: {$pass}/{$total} ({$accuracy}%)\n";
echo "Rating: {$rating}/5\n";

echo json_encode(['pass' => $pass, 'total' => $total, 'rating' => $rating, 'results' => $results], JSON_PRETTY_PRINT) . "\n";
