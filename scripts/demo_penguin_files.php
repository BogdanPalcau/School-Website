<?php
declare(strict_types=1);

/**
 * Helpers to build tiny penguin-themed Office/PDF demo files without ZipArchive.
 */

if (!function_exists('demo_zip_store')) {
    /**
     * Write an uncompressed ZIP archive.
     *
     * @param array<string,string> $entries path => binary/string contents
     */
    function demo_zip_store(string $zipPath, array $entries): void
    {
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create directory: ' . $dir);
        }

        $offset = 0;
        $local = '';
        $central = '';
        $count = 0;

        foreach ($entries as $name => $data) {
            $name = str_replace('\\', '/', (string) $name);
            $data = (string) $data;
            $size = strlen($data);
            $crc = crc32($data);
            // PHP crc32 can return signed int on 32-bit; pack as unsigned.
            $crcBin = pack('V', $crc);

            $localHeader =
                "PK\x03\x04"
                . pack('v', 20)   // version needed
                . pack('v', 0)    // flags
                . pack('v', 0)    // method = store
                . pack('v', 0) . pack('v', 0) // time/date
                . $crcBin
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($name))
                . pack('v', 0)
                . $name;

            $central .=
                "PK\x01\x02"
                . pack('v', 20)   // version made by
                . pack('v', 20)   // version needed
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0) . pack('v', 0)
                . $crcBin
                . pack('V', $size)
                . pack('V', $size)
                . pack('v', strlen($name))
                . pack('v', 0)    // extra
                . pack('v', 0)    // comment
                . pack('v', 0)    // disk
                . pack('v', 0)    // int attr
                . pack('V', 0)    // ext attr
                . pack('V', $offset)
                . $name;

            $local .= $localHeader . $data;
            $offset += strlen($localHeader) + $size;
            $count++;
        }

        $end =
            "PK\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', strlen($central))
            . pack('V', strlen($local))
            . pack('v', 0);

        if (file_put_contents($zipPath, $local . $central . $end) === false) {
            throw new RuntimeException('Cannot write zip: ' . $zipPath);
        }
    }
}

if (!function_exists('demo_xml_escape')) {
    function demo_xml_escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('demo_penguin_docx_bytes')) {
    function demo_penguin_docx_bytes(): string
    {
        $paras = [
            'All About Penguins — Class Notes',
            'Penguins are flightless birds adapted for life in the water. Their wings work like flippers for swimming.',
            'Most species live in the Southern Hemisphere. Emperor penguins breed on Antarctic sea ice.',
            'Countershading (dark back, light belly) helps them hide from predators above and below.',
            'A group of penguins on land is often called a rookery or colony. They mainly eat fish, krill, and squid.',
            'Revision tip: watch the unit video, then complete the flashcards before the quiz.',
        ];
        $body = '';
        foreach ($paras as $i => $p) {
            $sz = $i === 0 ? 32 : 22;
            $body .= '<w:p><w:pPr><w:spacing w:after="160"/></w:pPr>'
                . '<w:r><w:rPr><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>'
                . '<w:t>' . demo_xml_escape($p) . '</w:t></w:r></w:p>';
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $body
            . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr>'
            . '</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';

        $tmp = tempnam(sys_get_temp_dir(), 'pgdocx');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }
        @unlink($tmp);
        $tmp .= '.zip';
        demo_zip_store($tmp, [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $document,
        ]);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}

if (!function_exists('demo_penguin_pptx_bytes')) {
    function demo_penguin_pptx_bytes(): string
    {
        $slides = [
            ['All About Penguins', 'A short classroom presentation for the Penguins unit.'],
            ['Where they live', 'Mostly the Southern Hemisphere. Emperors breed on Antarctic ice.'],
            ['How they swim', 'Wings act as flippers. Dense bones and waterproof feathers help diving.'],
            ['What they eat', 'Fish, krill, squid, and other small sea animals.'],
            ['Quick check', 'Name one penguin fact from the video before you open the quiz.'],
        ];

        $slideFiles = [];
        $presentationRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $sldIdLst = '';
        foreach ($slides as $i => $pair) {
            $n = $i + 1;
            $rid = 'rId' . $n;
            $presentationRels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $n . '.xml"/>';
            $sldIdLst .= '<p:sldId id="' . (256 + $n) . '" r:id="' . $rid . '"/>';
            $slideFiles['ppt/slides/slide' . $n . '.xml'] =
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
                . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
                . '<p:grpSpPr/><p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr>'
                . '<p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>'
                . demo_xml_escape($pair[0]) . '</a:t></a:r></a:p></p:txBody></p:sp>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr>'
                . '<p:spPr/><p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:t>'
                . demo_xml_escape($pair[1]) . '</a:t></a:r></a:p></p:txBody></p:sp>'
                . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
            $slideFiles['ppt/slides/_rels/slide' . $n . '.xml.rels'] =
                '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
        }
        $presentationRels .= '</Relationships>';

        $presentation = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
            . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            . '<p:sldIdLst>' . $sldIdLst . '</p:sldIdLst>'
            . '<p:sldSz cx="9144000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/>'
            . '</p:presentation>';

        $overrides = '';
        foreach (array_keys($slides) as $i) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/ppt/slides/slide' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . $overrides
            . '</Types>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            . '</Relationships>';

        $entries = [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rootRels,
            'ppt/presentation.xml' => $presentation,
            'ppt/_rels/presentation.xml.rels' => $presentationRels,
        ] + $slideFiles;

        $tmp = tempnam(sys_get_temp_dir(), 'pgpptx');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }
        @unlink($tmp);
        $tmp .= '.zip';
        demo_zip_store($tmp, $entries);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}

if (!function_exists('demo_penguin_xlsx_bytes')) {
    function demo_penguin_xlsx_bytes(): string
    {
        $rows = [
            ['Species', 'Region', 'Typical diet', 'Fun fact'],
            ['Emperor', 'Antarctica', 'Fish / krill', 'Deepest divers'],
            ['King', 'Sub-Antarctic islands', 'Fish / squid', 'Tall second species'],
            ['Adélie', 'Antarctic coast', 'Krill', 'Build pebble nests'],
            ['Gentoo', 'Sub-Antarctic', 'Fish / krill', 'Fastest underwater'],
            ['African', 'Southern Africa', 'Fish', 'One of few northern-ish species'],
        ];

        $sheetData = '';
        foreach ($rows as $rIdx => $cols) {
            $rowNum = $rIdx + 1;
            $sheetData .= '<row r="' . $rowNum . '">';
            foreach ($cols as $cIdx => $val) {
                $col = chr(ord('A') + $cIdx);
                $ref = $col . $rowNum;
                $sheetData .= '<c r="' . $ref . '" t="inlineStr"><is><t>'
                    . demo_xml_escape((string) $val) . '</t></is></c>';
            }
            $sheetData .= '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetData . '</sheetData></worksheet>';

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Penguin species" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';

        $workbookRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>';

        $rootRels = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>';

        $tmp = tempnam(sys_get_temp_dir(), 'pgxlsx');
        if ($tmp === false) {
            throw new RuntimeException('tempnam failed');
        }
        @unlink($tmp);
        $tmp .= '.zip';
        demo_zip_store($tmp, [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rootRels,
            'xl/workbook.xml' => $workbook,
            'xl/_rels/workbook.xml.rels' => $workbookRels,
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }
}

if (!function_exists('demo_penguin_pdf_bytes')) {
    function demo_penguin_pdf_bytes(): string
    {
        $lines = [
            'Penguins — Fact Sheet',
            '',
            '1. Penguins are birds that cannot fly but swim expertly.',
            '2. Emperor penguins live and breed in Antarctica.',
            '3. Countershading camouflage protects them in open water.',
            '4. Diet: fish, krill, squid and similar prey.',
            '5. Use this sheet with the unit video and flashcards.',
        ];
        $content = "BT /F1 12 Tf 50 780 Td 16 TL\n";
        foreach ($lines as $i => $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            if ($i === 0) {
                $content .= "/F1 18 Tf ({$safe}) Tj T*\n/F1 12 Tf\n";
            } else {
                $content .= "({$safe}) Tj T*\n";
            }
        }
        $content .= "ET";

        $objs = [];
        $objs[] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
        $objs[] = "2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n";
        $objs[] = "3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] "
            . "/Contents 4 0 R /Resources<< /Font<< /F1 5 0 R >> >> >>endobj\n";
        $objs[] = '4 0 obj<< /Length ' . strlen($content) . " >>stream\n" . $content . "\nendstream\nendobj\n";
        $objs[] = "5 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objs as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefPos = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objs) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objs); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer<< /Size ' . (count($objs) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xrefPos . "\n%%EOF";
        return $pdf;
    }
}

if (!function_exists('demo_penguin_txt_bytes')) {
    function demo_penguin_txt_bytes(): string
    {
        return <<<TXT
Penguins — Quick Revision Notes
================================

Habitat
- Southern Hemisphere for most species
- Emperor penguins: Antarctic sea ice

Adaptations
- Flipper-like wings for swimming
- Waterproof feathers
- Countershading camouflage

Diet
- Fish, krill, squid

Study plan
1) Watch the unit video
2) Read the fact sheet (PDF)
3) Review the species table (XLSX)
4) Complete flashcards, then the quiz

TXT;
    }
}

if (!function_exists('demo_attach_course_document')) {
    /**
     * Create or refresh a downloadable document item with generated file bytes.
     *
     * @return array{item_id:int, created:bool, path:string}
     */
    function demo_attach_course_document(
        PDO $db,
        int $courseId,
        int $folderId,
        string $title,
        string $description,
        string $fileName,
        string $bytes,
        int $sortOrder = 1,
        int $allowDownload = 1
    ): array {
        $dirRel = 'courses' . DIRECTORY_SEPARATOR . $courseId;
        $absDir = portal_uploads_base() . DIRECTORY_SEPARATOR . $dirRel;
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            throw new RuntimeException('Cannot create upload dir: ' . $absDir);
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName) ?: 'penguin-file.bin';
        $relPath = $dirRel . DIRECTORY_SEPARATOR . $safe;
        $absPath = portal_uploads_base() . DIRECTORY_SEPARATOR . $relPath;
        if (file_put_contents($absPath, $bytes) === false) {
            throw new RuntimeException('Cannot write file: ' . $absPath);
        }

        $find = $db->prepare(
            'SELECT id, file_path FROM course_folder_items WHERE folder_id = ? AND title = ? LIMIT 1'
        );
        $find->execute([$folderId, $title]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $old = (string) ($row['file_path'] ?? '');
            if ($old !== '' && $old !== $relPath) {
                $oldAbs = portal_uploads_base() . DIRECTORY_SEPARATOR . $old;
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
            }
            $db->prepare(
                "UPDATE course_folder_items
                 SET type = 'document', description = ?, file_path = ?, file_name = ?,
                     url = '', allow_download = ?, sort_order = ?
                 WHERE id = ?"
            )->execute([$description, $relPath, $fileName, $allowDownload, $sortOrder, (int) $row['id']]);

            return ['item_id' => (int) $row['id'], 'created' => false, 'path' => $relPath];
        }

        $db->prepare(
            "INSERT INTO course_folder_items
                (folder_id, course_id, type, title, description, file_path, file_name, url, allow_download, sort_order)
             VALUES (?,?, 'document', ?,?,?,?,?,?,?)"
        )->execute([
            $folderId, $courseId, $title, $description, $relPath, $fileName, '', $allowDownload, $sortOrder,
        ]);

        return ['item_id' => (int) $db->lastInsertId(), 'created' => true, 'path' => $relPath];
    }
}
