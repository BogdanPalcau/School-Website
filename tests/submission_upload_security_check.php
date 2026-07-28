<?php
declare(strict_types=1);

/**
 * Unit checks for hardened submission upload validation and receipts.
 * Run: php tests/submission_upload_security_check.php
 */

require_once __DIR__ . '/../bootstrap.php';

$failures = 0;

function expect_true(bool $cond, string $label): void
{
    global $failures;
    if ($cond) {
        echo "PASS  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL  {$label}\n";
}

function expect_eq($a, $b, string $label): void
{
    expect_true($a === $b, $label . ' (got ' . var_export($a, true) . ')');
}

// Filename validation
$ok = portal_validate_submission_filename('work.final.pdf', 'pdf');
expect_true($ok['ok'] === true, 'harmless multi-dot pdf allowed');
expect_eq($ok['extension'] ?? '', 'pdf', 'final extension is pdf');

$bad = portal_validate_submission_filename('work.pdf.php', 'pdf');
expect_true($bad['ok'] === false, 'rejects work.pdf.php');

$bad2 = portal_validate_submission_filename('work.docx.exe', 'docx');
expect_true($bad2['ok'] === false, 'rejects work.docx.exe');

$bad3 = portal_validate_submission_filename('work.pdf.', 'pdf');
expect_true($bad3['ok'] === false, 'rejects trailing-dot name');

$bad4 = portal_validate_submission_filename("evil\0.pdf", 'pdf');
expect_true($bad4['ok'] === false, 'rejects NUL in filename');

$bad5 = portal_validate_submission_filename('../x.pdf', 'pdf');
expect_true($bad5['ok'] === false, 'rejects path separators');

$bad6 = portal_validate_submission_filename('notes.PDF', 'pdf');
expect_true($bad6['ok'] === true, 'uppercase extension normalized');

$mismatch = portal_validate_submission_filename('essay.docx', 'pdf');
expect_true($mismatch['ok'] === false && ($mismatch['reason'] ?? '') === 'type_mismatch', 'ext must match declared type');

// Upload orchestration — missing type
$r = portal_validate_submission_upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'name' => 'a.pdf', 'size' => 1], '');
expect_true($r['ok'] === false && ($r['reason'] ?? '') === 'missing_type', 'missing declared type');

$r = portal_validate_submission_upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => '', 'name' => 'a.pdf', 'size' => 1], 'exe');
expect_true($r['ok'] === false && ($r['reason'] ?? '') === 'invalid_type', 'invalid declared type');

// Receipt format
$receipt = portal_integrity_receipt_number();
expect_true(portal_receipt_format_ok($receipt), 'generated receipt matches format');
expect_true(str_starts_with($receipt, 'RIEO-') && strlen($receipt) === 37, 'RIEO- + 32 hex');

$norm = portal_normalize_receipt_number('  rieo-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  ');
expect_eq($norm, 'RIEO-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'normalize trims and uppercases');

expect_true(portal_find_submission_by_receipt('RIEO-NOT-A-REAL-RECEIPT-000000000000') === null, 'malformed receipt → null');
expect_true(portal_find_submission_by_receipt('RIEO-' . str_repeat('0', 32)) === null, 'nonexistent exact receipt → null');

$masked = portal_mask_receipt_number($receipt);
expect_true(!str_contains($masked, substr($receipt, 12, 16)), 'mask hides middle of receipt');

// Signature: PDF
$tmpPdf = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($tmpPdf, "%PDF-1.4\n%âãÏÓ\n");
$sig = portal_submission_signature_ok($tmpPdf, 'pdf');
expect_true($sig['ok'] === true, 'PDF signature accepted');
file_put_contents($tmpPdf, 'not a pdf');
$sig = portal_submission_signature_ok($tmpPdf, 'pdf');
expect_true($sig['ok'] === false, 'non-PDF rejected as pdf');
@unlink($tmpPdf);

// TXT binary
$tmpTxt = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($tmpTxt, "hello world\n");
expect_true(portal_submission_signature_ok($tmpTxt, 'txt')['ok'] === true, 'plain text ok');
file_put_contents($tmpTxt, "hello\0world");
expect_true(portal_submission_signature_ok($tmpTxt, 'txt')['ok'] === false, 'NUL in txt rejected');
@unlink($tmpTxt);

// Plain ZIP renamed as docx should fail structure check
$tmpZip = tempnam(sys_get_temp_dir(), 'zip') . '.zip';
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE) === true) {
    $zip->addFromString('readme.txt', 'hello');
    $zip->close();
    $docx = portal_docx_structure_ok($tmpZip);
    expect_true($docx['ok'] === false, 'ordinary zip is not a docx');
    @unlink($tmpZip);
} else {
    echo "SKIP  could not create zip for docx negative test\n";
}

// Minimal fake docx structure
$tmpDocx = tempnam(sys_get_temp_dir(), 'docx') . '.docx';
$zip = new ZipArchive();
if ($zip->open($tmpDocx, ZipArchive::CREATE) === true) {
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships></Relationships>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document></w:document>');
    $zip->close();
    $docx = portal_docx_structure_ok($tmpDocx);
    expect_true($docx['ok'] === true, 'minimal OOXML structure accepted');

    // Traversal entry
    $tmpTrav = tempnam(sys_get_temp_dir(), 'trav') . '.docx';
    $z2 = new ZipArchive();
    $z2->open($tmpTrav, ZipArchive::CREATE);
    $z2->addFromString('[Content_Types].xml', 'x');
    $z2->addFromString('_rels/.rels', 'x');
    $z2->addFromString('word/document.xml', 'x');
    $z2->addFromString('../evil.txt', 'x');
    $z2->close();
    expect_true(portal_docx_structure_ok($tmpTrav)['ok'] === false, 'docx traversal entry rejected');
    @unlink($tmpTrav);
    @unlink($tmpDocx);
} else {
    echo "SKIP  could not create docx fixtures\n";
}

// MIME fail-closed for missing file
expect_true(portal_upload_mime_ok('', 'pdf') === false, 'mime_ok fails closed on missing path');

// Generic mismatch message
expect_eq(
    portal_submission_type_mismatch_message(),
    'File does not match the selected type.',
    'public mismatch wording'
);

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
