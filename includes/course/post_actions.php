<?php
declare(strict_types=1);

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals($csrfToken, $token)) {
        portal_redirect('course.php?course=' . urlencode($slug));
    }

    if ($preEnrollBlocks) {
        portal_redirect('course.php?course=' . urlencode($slug));
    }

    $action   = (string) ($_POST['action'] ?? '');
    $courseId = (int) $course['id'];
    $db       = portal_db();
    $me       = portal_current_user();
    $maxUploadBytes = 40 * 1024 * 1024;
    $maxVideoUploadBytes = 400 * 1024 * 1024;

    $uploadErrorMessage = static function (int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large for the server/upload form limit.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temp directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload failed due to an unknown error.',
        };
    };

    if ($action === 'check_external_link') {
        $url = portal_course_normalize_external_url((string) ($_POST['url'] ?? ''));
        if ($url === '') {
            portal_json_response(['ok' => false, 'error' => 'Invalid external link.'], 400);
        }
        $verdict = portal_course_google_safe_browsing_url_check($url);
        portal_json_response([
            'ok' => true,
            'url' => $url,
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
            'verdict' => $verdict,
        ]);
    }

    // ── AJAX: reorder folders (JSON, exits immediately) ──────────────────────
    if ($action === 'reorder_folders' && portal_can_manage_course($courseId)) {
        $order = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (is_array($order)) {
            $upd = $db->prepare(
                "UPDATE course_folders SET sort_order = ? WHERE id = ? AND course_id = ?"
            );
            foreach ($order as $i => $fid) {
                $upd->execute([$i * 10, (int) $fid, $courseId]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: reorder items within a folder ──────────────────────────────────
    if ($action === 'reorder_items' && portal_can_manage_course($courseId)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        $order    = json_decode((string) ($_POST['order'] ?? '[]'), true);
        if (is_array($order) && $folderId > 0) {
            $upd = $db->prepare(
                "UPDATE course_folder_items SET sort_order = ? WHERE id = ? AND folder_id = ? AND course_id = ?"
            );
            foreach ($order as $i => $iid) {
                $upd->execute([$i * 10, (int) $iid, $folderId, $courseId]);
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: toggle folder lock state ───────────────────────────────────────
    if ($action === 'toggle_folder_lock' && portal_can_manage_course($courseId)) {
        $folderId = (int) ($_POST['folder_id'] ?? 0);
        if ($folderId > 0) {
            $row = $db->prepare("SELECT locked FROM course_folders WHERE id = ? AND course_id = ?");
            $row->execute([$folderId, $courseId]);
            $current = $row->fetch();
            if ($current) {
                $newLocked = $current['locked'] ? 0 : 1;
                $db->prepare("UPDATE course_folders SET locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newLocked, $folderId, $courseId]);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'locked' => $newLocked]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    // ── AJAX: toggle item lock state ─────────────────────────────────────────
    if ($action === 'toggle_item_lock' && portal_can_manage_course($courseId)) {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        if ($itemId > 0) {
            $row = $db->prepare("SELECT locked FROM course_folder_items WHERE id = ? AND course_id = ?");
            $row->execute([$itemId, $courseId]);
            $current = $row->fetch();
            if ($current) {
                $newLocked = $current['locked'] ? 0 : 1;
                $db->prepare("UPDATE course_folder_items SET locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newLocked, $itemId, $courseId]);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'locked' => $newLocked]);
                exit;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }

    // ── AJAX: move item to another folder ────────────────────────────────────
    if ($action === 'move_item' && portal_can_manage_course($courseId)) {
        $itemId     = (int) ($_POST['item_id'] ?? 0);
        $toFolderId = (int) ($_POST['folder_id'] ?? 0);
        $itemChk    = $db->prepare("SELECT id FROM course_folder_items WHERE id = ? AND course_id = ?");
        $itemChk->execute([$itemId, $courseId]);
        $folderChk  = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
        $folderChk->execute([$toFolderId, $courseId]);
        if ($itemChk->fetch() && $folderChk->fetch()) {
            $maxOrd = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) FROM course_folder_items WHERE folder_id = ?");
            $maxOrd->execute([$toFolderId]);
            $newOrder = (int) $maxOrd->fetchColumn() + 10;
            $db->prepare("UPDATE course_folder_items SET folder_id = ?, sort_order = ? WHERE id = ? AND course_id = ?")
               ->execute([$toFolderId, $newOrder, $itemId, $courseId]);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── AJAX: mark announcements as read ─────────────────────────────────────
    if ($action === 'mark_announcements_read') {
        $ids = array_filter(array_map('intval', (array)($_POST['announcement_ids'] ?? [])));
        if (!empty($ids)) {
            $ins  = $db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)");
            $chk  = $db->prepare("SELECT id FROM course_announcements WHERE id = ? AND course_id = ?");
            foreach ($ids as $aid) {
                $chk->execute([$aid, $courseId]);
                if ($chk->fetch()) {
                    $ins->execute([(int) $me['id'], $aid]);
                }
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Admin-only: assign / remove / update course staff ─────────────────────
    if (portal_is_admin()) {
        if ($action === 'assign_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $assignmentRole = (string) ($_POST['assignment_role'] ?? 'teacher');
            if (!portal_valid_assignment_role($assignmentRole)) {
                $assignmentRole = 'teacher';
            }
            $chk = $db->prepare(
                "SELECT id FROM users WHERE id = ? AND role IN ('owner', 'admin', 'teacher')"
            );
            $chk->execute([$uid]);
            if ($chk->fetch()) {
                $db->prepare("
                    INSERT INTO course_teachers (course_id, user_id, assignment_role)
                    VALUES (?,?,?)
                    ON CONFLICT(course_id, user_id) DO UPDATE SET assignment_role = excluded.assignment_role
                ")->execute([$courseId, $uid, $assignmentRole]);
            }
        } elseif ($action === 'change_assignment_role') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $assignmentRole = (string) ($_POST['assignment_role'] ?? 'teacher');
            if (!portal_valid_assignment_role($assignmentRole)) {
                $assignmentRole = 'teacher';
            }
            $db->prepare("
                UPDATE course_teachers SET assignment_role = ?
                WHERE course_id = ? AND user_id = ?
            ")->execute([$assignmentRole, $courseId, $uid]);
        } elseif ($action === 'remove_teacher') {
            $uid = (int) ($_POST['user_id'] ?? 0);
            $db->prepare('DELETE FROM course_teachers WHERE course_id = ? AND user_id = ?')
               ->execute([$courseId, $uid]);
        }
    }

    // ── Course managers (admin OR assigned teacher) ───────────────────────────
    if (portal_can_manage_course($courseId)) {

        if ($action === 'create_folder') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc  = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $locked = isset($_POST['locked']) && $_POST['locked'] === '1' ? 1 : 0;
            if ($title !== '') {
                $db->prepare("INSERT INTO course_folders (course_id, title, description, locked) VALUES (?,?,?,?)")
                   ->execute([$courseId, $title, $desc, $locked]);
            }

        } elseif ($action === 'update_folder_settings') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $locked = isset($_POST['locked']) && $_POST['locked'] === '1' ? 1 : 0;
            if ($folderId > 0 && $title !== '') {
                $db->prepare("UPDATE course_folders SET title = ?, description = ?, locked = ? WHERE id = ? AND course_id = ?")
                   ->execute([$title, $desc, $locked, $folderId, $courseId]);
                $_SESSION['course_flash'] = ['success', 'Folder settings saved.'];
            }

        } elseif ($action === 'delete_folder') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $hiddenFolder = $db->prepare(
                'SELECT COALESCE(is_pre_enroll, 0) FROM course_folders WHERE id = ? AND course_id = ?'
            );
            $hiddenFolder->execute([$folderId, $courseId]);
            if ((int) $hiddenFolder->fetchColumn() === 1) {
                $_SESSION['course_flash'] = ['error', 'The pre-enrolment quiz is managed from course settings.'];
            } else {
            $folderItemIds = [];
            if ($folderId > 0) {
                $idStmt = $db->prepare(
                    "SELECT id FROM course_folder_items WHERE folder_id = ? AND course_id = ?"
                );
                $idStmt->execute([$folderId, $courseId]);
                $folderItemIds = array_map('intval', $idStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
            }
            // Delete any uploaded files in this folder
            $fiStmt = $db->prepare(
                "SELECT file_path FROM course_folder_items WHERE folder_id = ? AND course_id = ? AND file_path != ''"
            );
            $fiStmt->execute([$folderId, $courseId]);
            foreach ($fiStmt->fetchAll(PDO::FETCH_COLUMN) as $_fp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_fp;
                if (is_file($abs)) @unlink($abs);
            }
            // Delete any submission files in this folder's slots
            $ssStmt = $db->prepare(
                "SELECT cs.filepath FROM course_submissions cs
                 JOIN course_folder_items cfi ON cfi.id = cs.item_id
                 WHERE cfi.folder_id = ? AND cfi.course_id = ? AND cs.course_id = ?"
            );
            $ssStmt->execute([$folderId, $courseId, $courseId]);
            foreach ($ssStmt->fetchAll(PDO::FETCH_COLUMN) as $_sp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_sp;
                if (is_file($abs)) @unlink($abs);
            }
            $db->prepare("DELETE FROM course_folders WHERE id = ? AND course_id = ?")
               ->execute([$folderId, $courseId]);
            foreach ($folderItemIds as $deletedItemId) {
                if ($deletedItemId > 0) {
                    portal_notifications_unsend('lesson-viewer.php?item=' . $deletedItemId, true, 'lesson_answer');
                }
            }
            }

        } elseif ($action === 'create_item') {
            $folderId = (int) ($_POST['folder_id'] ?? 0);
            $type     = (string) ($_POST['type'] ?? 'document');
            if (!in_array($type, ['document', 'link', 'submission', 'video', 'activity'], true)) {
                $type = 'document';
            }
            $title    = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $desc     = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
            $url      = portal_course_normalize_external_url(substr(trim((string) ($_POST['url'] ?? '')), 0, 2000));
            $submissionDeadline = '';
            $submissionAiDetection = 0;
            $submissionMaxAttempts = 0;
            $submissionWeight = 100.0;
            $filePath = '';
            $fileName = '';
            $createItemError = null;

            if ($type === 'activity') {
                $chk = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
                $chk->execute([$folderId, $courseId]);
                if (!$chk->fetch() || $title === '') {
                    $_SESSION['course_flash'] = ['error', 'Could not add activity. Check the folder and title, then try again.'];
                } else {
                    $mode = (string) ($_POST['activity_mode'] ?? 'quiz');
                    if (!in_array($mode, portal_activity_modes(), true)) {
                        $mode = 'quiz';
                    }
                    $created = portal_activity_create($courseId, $folderId, $title, $mode, (int) ($me['id'] ?? 0));
                    if (!empty($created['ok']) && !empty($created['activity_id'])) {
                        portal_redirect('activity-builder.php?id=' . (int) $created['activity_id']);
                    }
                    $_SESSION['course_flash'] = ['error', (string) ($created['error'] ?? 'Could not create activity.')];
                }
            } else {
            // Handle file upload for document/video types. A video item may instead point
            // at an external link (YouTube/Vimeo) — only enter the upload branch when a
            // file was actually attempted, so a video-link-only submission falls through
            // to the external-video validation below instead of failing with "no file".
            $videoFileAttempted = $type === 'video'
                && isset($_FILES['file'])
                && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $documentFileAttempted = $type === 'document' && isset($_FILES['file']);

            if ($documentFileAttempted || $videoFileAttempted) {
                $isVideoUpload = $type === 'video';
                $fileError = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
                $fileSize = (int) ($_FILES['file']['size'] ?? 0);
                $ext = strtolower(pathinfo((string) ($_FILES['file']['name'] ?? ''), PATHINFO_EXTENSION));
                $allowedExts = $isVideoUpload ? portal_video_extensions() : portal_supported_upload_extensions();
                $sizeLimit = $isVideoUpload ? $maxVideoUploadBytes : $maxUploadBytes;
                $sizeLimitLabel = $isVideoUpload ? '400 MB' : '40 MB';

                if ($fileError !== UPLOAD_ERR_OK) {
                    $createItemError = $uploadErrorMessage($fileError);
                } elseif (!in_array($ext, $allowedExts, true)) {
                    $createItemError = $isVideoUpload
                        ? 'Unsupported video format. Use ' . portal_supported_video_upload_hint() . '.'
                        : 'Unsupported file type. Use ' . portal_supported_upload_hint() . '.';
                } elseif ($fileSize <= 0) {
                    $createItemError = 'Uploaded file is empty (0 bytes). Please export/download it again and re-upload.';
                } elseif (!portal_upload_mime_ok((string) ($_FILES['file']['tmp_name'] ?? ''), $ext)) {
                    $createItemError = $isVideoUpload
                        ? 'This file content does not match its extension. Please upload a genuine video file.'
                        : 'This file content does not match its extension. Please upload a genuine document.';
                } elseif ($fileSize > $sizeLimit) {
                    $createItemError = 'File is too large. Maximum allowed size is ' . $sizeLimitLabel . '.';
                } else {
                    $dir = portal_uploads_base() . DIRECTORY_SEPARATOR . 'courses' . DIRECTORY_SEPARATOR . $courseId;
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $safe = bin2hex(random_bytes(16)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['file']['tmp_name'], $dir . DIRECTORY_SEPARATOR . $safe)) {
                        $filePath = 'courses' . DIRECTORY_SEPARATOR . $courseId . DIRECTORY_SEPARATOR . $safe;
                        $fileName = substr((string) $_FILES['file']['name'], 0, 255);
                        $url      = ''; // file takes precedence
                    } else {
                        $createItemError = 'Upload failed while saving the file. Please try again.';
                    }
                }
            } elseif ($type === 'video' && $url !== '') {
                $videoMeta = portal_parse_external_video_url($url);
                if ($videoMeta === null) {
                    $createItemError = 'That link is not a supported video source. Please use a ' . portal_supported_video_source_hint() . ', or upload a video file.';
                } else {
                    $url = $videoMeta['watch_url'];
                }
            } elseif ($type === 'link' && $url === '') {
                $createItemError = 'Please enter a valid link URL.';
            } elseif ($type === 'document' && $url === '') {
                $createItemError = 'Please upload a file or provide a URL for this document item.';
            } elseif ($type === 'video' && $filePath === '') {
                $createItemError = 'Please upload a video file or paste a ' . portal_supported_video_source_hint() . '.';
            } elseif ($type === 'submission') {
                $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                if ($deadlineRaw === '') {
                    $submissionDeadline = '';
                } else {
                    $deadlineTs = strtotime($deadlineRaw);
                    if ($deadlineTs === false) {
                        $createItemError = 'Please enter a valid deadline, or leave it blank for no deadline.';
                    } elseif ($deadlineTs <= time()) {
                        $createItemError = 'Deadline must be in the future, or leave it blank for no deadline.';
                    } else {
                        $submissionDeadline = date('Y-m-d H:i:s', $deadlineTs);
                    }
                }
                if ($createItemError === null) {
                    $submissionAiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                    $submissionMaxAttempts = (int) ($_POST['submission_max_attempts'] ?? 0);
                    if (!in_array($submissionMaxAttempts, [0, 1, 2], true)) {
                        $submissionMaxAttempts = 0;
                    }
                    $submissionWeight = portal_normalize_submission_weight($_POST['submission_weight'] ?? 100);
                    $weightFit = portal_course_gradebook_weight_fits($courseId, $submissionWeight);
                    if (empty($weightFit['ok'])) {
                        $createItemError = (string) ($weightFit['error'] ?? 'Gradebook weights cannot exceed 100%.');
                    }
                    $url = '';
                }
            }

            $chk = $db->prepare("SELECT id FROM course_folders WHERE id = ? AND course_id = ?");
            $chk->execute([$folderId, $courseId]);
            if ($createItemError !== null) {
                if (
                    str_contains($createItemError, 'Unsupported file type')
                    || str_contains($createItemError, 'Unsupported video format')
                    || str_contains($createItemError, 'does not match')
                    || str_contains($createItemError, 'too large')
                    || str_contains($createItemError, 'blocked the upload')
                ) {
                    portal_log_blocked_upload($createItemError);
                }
                $_SESSION['course_flash'] = ['error', $createItemError];
            } elseif ($chk->fetch() && $title !== '') {
                $allowDl = (isset($_POST['allow_download']) && $_POST['allow_download'] === '1') ? 1 : 0;
                $db->prepare(
                    "INSERT INTO course_folder_items
                     (folder_id, course_id, type, title, description, url, file_path, file_name, allow_download, submission_deadline, submission_ai_detection, submission_max_attempts, submission_weight)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([$folderId, $courseId, $type, $title, $desc, $url, $filePath, $fileName, $allowDl, $submissionDeadline, $submissionAiDetection, $submissionMaxAttempts, $submissionWeight]);
                $_SESSION['course_flash'] = ['success', 'Item added successfully.'];
            } else {
                $_SESSION['course_flash'] = ['error', 'Could not add item. Check the folder and title, then try again.'];
            }
            } // end non-activity create_item

        } elseif ($action === 'update_item_settings') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            $itemStmt = $db->prepare("SELECT * FROM course_folder_items WHERE id = ? AND course_id = ?");
            $itemStmt->execute([$itemId, $courseId]);
            $itemRow = $itemStmt->fetch();
            if ($itemRow) {
                $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
                $desc = substr(trim((string) ($_POST['description'] ?? '')), 0, 500);
                $url = portal_course_normalize_external_url(substr(trim((string) ($_POST['url'] ?? '')), 0, 2000));
                $allowDl = isset($_POST['allow_download']) && $_POST['allow_download'] === '1' ? 1 : 0;
                $deadline = (string) ($itemRow['submission_deadline'] ?? '');
                $aiDetection = (int) ($itemRow['submission_ai_detection'] ?? 0);
                $maxAttempts = (int) ($itemRow['submission_max_attempts'] ?? 0);
                $submissionWeight = portal_normalize_submission_weight($itemRow['submission_weight'] ?? 100);
                $error = null;

                if ($title === '') {
                    $error = 'Item title is required.';
                }

                if ($error === null && $itemRow['type'] === 'link') {
                    if ($url === '') {
                        $error = 'Please enter a valid link URL.';
                    }
                } elseif ($error === null && $itemRow['type'] === 'document' && $itemRow['file_path'] === '') {
                    if ($url === '') {
                        $error = 'Please enter a valid file URL.';
                    }
                } elseif ($error === null && $itemRow['type'] === 'video' && $itemRow['file_path'] === '') {
                    $videoMeta = $url !== '' ? portal_parse_external_video_url($url) : null;
                    if ($videoMeta === null) {
                        $error = 'Please use a ' . portal_supported_video_source_hint() . '.';
                    } else {
                        $url = $videoMeta['watch_url'];
                    }
                } elseif ($itemRow['type'] !== 'link') {
                    $url = (string) ($itemRow['url'] ?? '');
                }

                if ($error === null && $itemRow['type'] === 'submission') {
                    $deadlineRaw = trim((string) ($_POST['submission_deadline'] ?? ''));
                    $previousDeadline = trim((string) ($itemRow['submission_deadline'] ?? ''));
                    $previousTs = $previousDeadline !== '' ? strtotime($previousDeadline) : false;
                    if ($deadlineRaw === '') {
                        $deadline = '';
                    } else {
                        $deadlineTs = strtotime($deadlineRaw);
                        if ($deadlineTs === false) {
                            $error = 'Please enter a valid deadline, or leave it blank for no deadline.';
                        } elseif ($deadlineTs <= time()) {
                            // Teachers may close a slot by moving the deadline slightly into the
                            // past. Reject absurd historical dates (e.g. 2006) on new changes.
                            $oldestAllowedPast = time() - (7 * 24 * 3600);
                            $unchangedPast = $previousTs !== false && abs($deadlineTs - $previousTs) < 60;
                            if ($deadlineTs < $oldestAllowedPast && !$unchangedPast) {
                                $error = 'Deadline must be in the future, leave blank for none, or set a recent time (within 7 days) to close submissions.';
                            } else {
                                $deadline = date('Y-m-d H:i:s', $deadlineTs);
                            }
                        } else {
                            $deadline = date('Y-m-d H:i:s', $deadlineTs);
                        }
                    }
                    if ($error === null) {
                        $aiDetection = isset($_POST['submission_ai_detection']) && $_POST['submission_ai_detection'] === '1' ? 1 : 0;
                        $maxAttempts = (int) ($_POST['submission_max_attempts'] ?? 0);
                        if (!in_array($maxAttempts, [0, 1, 2], true)) {
                            $maxAttempts = 0;
                        }
                        $submissionWeight = portal_normalize_submission_weight($_POST['submission_weight'] ?? 100);
                        $weightFit = portal_course_gradebook_weight_fits(
                            $courseId,
                            $submissionWeight,
                            ['exclude_item_id' => $itemId]
                        );
                        if (empty($weightFit['ok'])) {
                            $error = (string) ($weightFit['error'] ?? 'Gradebook weights cannot exceed 100%.');
                        }
                    }
                }

                if ($error !== null) {
                    $_SESSION['course_flash'] = ['error', $error];
                } else {
                    $db->prepare(
                        "UPDATE course_folder_items
                         SET title = ?, description = ?, url = ?, allow_download = ?,
                             submission_deadline = ?, submission_ai_detection = ?, submission_max_attempts = ?, submission_weight = ?
                         WHERE id = ? AND course_id = ?"
                    )->execute([$title, $desc, $url, $allowDl, $deadline, $aiDetection, $maxAttempts, $submissionWeight, $itemId, $courseId]);
                    $_SESSION['course_flash'] = ['success', 'Item settings saved.'];
                }
            }

        } elseif ($action === 'toggle_download' && portal_can_manage_course($courseId)) {
            $itemId  = (int) ($_POST['item_id'] ?? 0);
            $current = $db->prepare("SELECT allow_download FROM course_folder_items WHERE id = ? AND course_id = ?");
            $current->execute([$itemId, $courseId]);
            $row    = $current->fetch();
            $newVal = 0;
            if ($row) {
                $newVal = $row['allow_download'] ? 0 : 1;
                $db->prepare("UPDATE course_folder_items SET allow_download = ? WHERE id = ? AND course_id = ?")
                   ->execute([$newVal, $itemId, $courseId]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'allowed' => $newVal]);
            exit;

        } elseif ($action === 'delete_item') {
            $itemId = (int) ($_POST['item_id'] ?? 0);
            // Delete uploaded material file
            $fpStmt = $db->prepare(
                "SELECT file_path FROM course_folder_items WHERE id = ? AND course_id = ?"
            );
            $fpStmt->execute([$itemId, $courseId]);
            $fpRow = $fpStmt->fetch();
            if ($fpRow && $fpRow['file_path'] !== '') {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $fpRow['file_path'];
                if (is_file($abs)) @unlink($abs);
            }
            // Delete any student submission files for this slot
            $subStmt = $db->prepare("SELECT filepath FROM course_submissions WHERE item_id = ? AND course_id = ?");
            $subStmt->execute([$itemId, $courseId]);
            foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $_sp) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $_sp;
                if (is_file($abs)) @unlink($abs);
            }
            $db->prepare("DELETE FROM course_folder_items WHERE id = ? AND course_id = ?")
               ->execute([$itemId, $courseId]);
            if ($itemId > 0) {
                portal_notifications_unsend('lesson-viewer.php?item=' . $itemId, true, 'lesson_answer');
            }

        } elseif ($action === 'post_announcement') {
            $title = substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
            $body  = substr(portal_sanitize_rich_text(trim((string) ($_POST['body'] ?? ''))), 0, 2000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_announcements (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int) $me['id'], $title, $body]);
                $newAnnId = (int) $db->lastInsertId();
                if ($newAnnId > 0) {
                    $posterId = (int) $me['id'];
                    $db->prepare("INSERT OR IGNORE INTO announcement_reads (user_id, announcement_id) VALUES (?,?)")
                       ->execute([$posterId, $newAnnId]);

                    // Personal alerts for enrolled students + course managers
                    // (respects notify_announcements; skips the poster).
                    $recipientIds = [];
                    $enrolledStmt = $db->prepare(
                        "SELECT user_id FROM enrollments WHERE course_id = ?"
                    );
                    $enrolledStmt->execute([$courseId]);
                    foreach ($enrolledStmt->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                        $studentId = (int) $studentId;
                        if ($studentId > 0) {
                            $recipientIds[$studentId] = true;
                        }
                    }
                    $managerStmt = $db->prepare(
                        "SELECT user_id FROM course_teachers WHERE course_id = ?"
                    );
                    $managerStmt->execute([$courseId]);
                    foreach ($managerStmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
                        $managerId = (int) $managerId;
                        if ($managerId > 0) {
                            $recipientIds[$managerId] = true;
                        }
                    }
                    foreach ($db->query(
                        "SELECT id FROM users WHERE role IN ('owner','admin')"
                    )->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                        $adminId = (int) $adminId;
                        if ($adminId > 0) {
                            $recipientIds[$adminId] = true;
                        }
                    }
                    $annLink = 'course.php?course=' . urlencode((string) $course['slug'])
                        . '&section=announcements&ann=' . $newAnnId;
                    $courseTitle = (string) ($course['title'] ?? 'Module');
                    $notifTitle = 'New announcement in “' . substr($courseTitle, 0, 80) . '”';
                    $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
                    $snippet = $title . ($plainBody !== '' ? ' — ' . substr($plainBody, 0, 100) : '');
                    foreach (array_keys($recipientIds) as $rid) {
                        $rid = (int) $rid;
                        if ($rid <= 0 || $rid === $posterId) {
                            continue;
                        }
                        portal_notify_user(
                            $rid,
                            'announcement',
                            $notifTitle,
                            substr($snippet, 0, 200),
                            $annLink,
                            $courseId
                        );
                    }
                }
            }

        } elseif ($action === 'delete_announcement') {
            $annId = (int) ($_POST['announcement_id'] ?? 0);
            $annMeta = null;
            if ($annId > 0) {
                $annMetaStmt = $db->prepare(
                    "SELECT id, title FROM course_announcements WHERE id = ? AND course_id = ?"
                );
                $annMetaStmt->execute([$annId, $courseId]);
                $annMeta = $annMetaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
            $annDeleted = false;
            if ($annMeta) {
                if (portal_is_admin()) {
                    $delAnn = $db->prepare("DELETE FROM course_announcements WHERE id = ? AND course_id = ?");
                    $delAnn->execute([$annId, $courseId]);
                    $annDeleted = $delAnn->rowCount() > 0;
                } else {
                    $delAnn = $db->prepare(
                        "DELETE FROM course_announcements WHERE id = ? AND course_id = ? AND user_id = ?"
                    );
                    $delAnn->execute([$annId, $courseId, (int) $me['id']]);
                    $annDeleted = $delAnn->rowCount() > 0;
                }
            }
            if ($annDeleted && $annMeta) {
                $slug = (string) ($course['slug'] ?? '');
                $annLink = 'course.php?course=' . urlencode($slug)
                    . '&section=announcements&ann=' . $annId;
                portal_notifications_unsend($annLink, true, 'announcement');
                // Legacy alerts (no ann= id in the link) matched by course + body prefix.
                $annTitle = trim((string) ($annMeta['title'] ?? ''));
                if ($annTitle !== '') {
                    $legacyLink = 'course.php?course=' . urlencode($slug) . '&section=announcements';
                    $db->prepare(
                        "DELETE FROM portal_notifications
                         WHERE type = 'announcement' AND course_id = ? AND link = ?
                           AND (body = ? OR body LIKE ?)"
                    )->execute([$courseId, $legacyLink, $annTitle, $annTitle . ' —%']);
                }
            }

        } elseif ($action === 'save_tab_settings') {
            $allKeys     = ['content','calendar','announcements','discussions','gradebook','groups'];
            $enabledRaw  = (array) ($_POST['tab_keys'] ?? []);
            $enabledRaw[] = 'content'; // always on
            $db->prepare("DELETE FROM course_tab_settings WHERE course_id = ?")->execute([$courseId]);
            $insT = $db->prepare(
                "INSERT INTO course_tab_settings (course_id, tab_key, enabled) VALUES (?,?,?)"
            );
            foreach ($allKeys as $k) {
                $insT->execute([$courseId, $k, in_array($k, $enabledRaw, true) ? 1 : 0]);
            }

        } elseif ($action === 'mark_submission') {
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $score = max(0, min(100, (int) ($_POST['score'] ?? 0)));
            $feedback = substr(trim((string) ($_POST['feedback'] ?? '')), 0, 2000);
            $chk = $db->prepare("SELECT id FROM course_submissions WHERE id = ? AND course_id = ?");
            $chk->execute([$subId, $courseId]);
            if ($chk->fetch()) {
                $before = $db->prepare(
                    'SELECT score, feedback, user_id, grades_released_at FROM course_submissions WHERE id = ? AND course_id = ?'
                );
                $before->execute([$subId, $courseId]);
                $prev = $before->fetch(PDO::FETCH_ASSOC) ?: [];
                $wasReleased = portal_submission_grades_released($prev);
                $db->prepare(
                    "UPDATE course_submissions
                     SET score = ?, feedback = ?, marked_at = datetime('now'), marked_by = ?, grade_seen_at = ''
                     WHERE id = ? AND course_id = ?"
                )->execute([$score, $feedback, (int) $me['id'], $subId, $courseId]);
                $prevScore = $prev['score'] ?? null;
                $studentId = (int) ($prev['user_id'] ?? 0);
                portal_log_security_event(
                    'grade_changed',
                    'medium',
                    'Marked submission #' . $subId
                        . ' (course #' . $courseId
                        . ', student #' . $studentId
                        . ') score '
                        . ($prevScore === null || $prevScore === '' ? 'none' : (string) $prevScore)
                        . '→' . $score
                        . ($wasReleased ? ' (already released)' : ' (held)')
                );
                if ($wasReleased && $studentId > 0) {
                    $slotTitleStmt = $db->prepare(
                        "SELECT cfi.title
                         FROM course_submissions cs
                         JOIN course_folder_items cfi ON cfi.id = cs.item_id
                         WHERE cs.id = ? AND cs.course_id = ?"
                    );
                    $slotTitleStmt->execute([$subId, $courseId]);
                    $slotTitle = trim((string) ($slotTitleStmt->fetchColumn() ?: 'Assignment'));
                    portal_notify_file_grade_released($studentId, $courseId, $slug, $slotTitle, $score, $subId);
                }
                if (portal_is_fetch_request()) {
                    portal_json_response([
                        'ok'            => true,
                        'submission_id' => $subId,
                        'score'         => $score,
                        'feedback'      => $feedback,
                        'released'      => $wasReleased,
                    ]);
                }
                $_SESSION['course_flash'] = [
                    'success',
                    $wasReleased
                        ? 'Submission marked. The student can already see this grade.'
                        : 'Grade saved. Release it when you want students to see it.',
                ];
            } elseif (portal_is_fetch_request()) {
                portal_json_response(['ok' => false, 'error' => 'Submission not found.'], 404);
            }

        } elseif ($action === 'release_submission_grades') {
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $itemId = (int) ($_POST['item_id'] ?? 0);

            $sql = "SELECT cs.id, cs.user_id, cs.score, cs.item_id, cfi.title AS slot_title
                    FROM course_submissions cs
                    JOIN course_folder_items cfi ON cfi.id = cs.item_id
                    WHERE cs.course_id = ?
                      AND cs.score IS NOT NULL
                      AND cs.marked_at != ''
                      AND (cs.grades_released_at IS NULL OR trim(cs.grades_released_at) = '')";
            $params = [$courseId];
            if ($subId > 0) {
                $sql .= ' AND cs.id = ?';
                $params[] = $subId;
            } elseif ($itemId > 0) {
                $sql .= ' AND cs.item_id = ?';
                $params[] = $itemId;
            } else {
                $sql = '';
            }

            $toRelease = [];
            if ($sql !== '') {
                $list = $db->prepare($sql);
                $list->execute($params);
                $toRelease = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            if ($toRelease === []) {
                $_SESSION['course_flash'] = ['error', 'No held grades to release.'];
            } else {
                $ids = array_map(static fn(array $row): int => (int) $row['id'], $toRelease);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare(
                    "UPDATE course_submissions
                     SET grades_released_at = datetime('now'), grade_seen_at = ''
                     WHERE course_id = ? AND id IN ($placeholders)"
                )->execute(array_merge([$courseId], $ids));
                foreach ($toRelease as $row) {
                    portal_notify_file_grade_released(
                        (int) $row['user_id'],
                        $courseId,
                        $slug,
                        (string) ($row['slot_title'] ?? 'Assignment'),
                        (int) $row['score'],
                        (int) $row['id']
                    );
                }
                $n = count($toRelease);
                $_SESSION['course_flash'] = [
                    'success',
                    $n === 1
                        ? 'Grade released. The student can now see it.'
                        : $n . ' grades released. Students can now see them.',
                ];
            }

        } elseif ($action === 'save_annotation' && portal_can_manage_course($courseId)) {
            header('Content-Type: application/json');
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $chk = $db->prepare("SELECT id FROM course_submissions WHERE id = ? AND course_id = ?");
            $chk->execute([$subId, $courseId]);
            if (!$chk->fetch()) {
                echo json_encode(['ok' => false, 'error' => 'Submission not found.']);
                exit;
            }

            $annId      = (int) ($_POST['annotation_id'] ?? 0);
            $anchorType = (string) ($_POST['anchor_type'] ?? 'text');
            if (!in_array($anchorType, ['text', 'image', 'general'], true)) {
                $anchorType = 'text';
            }
            $comment = trim((string) ($_POST['comment'] ?? ''));
            $comment = mb_substr($comment, 0, 2000);
            if ($comment === '') {
                echo json_encode(['ok' => false, 'error' => 'Comment cannot be empty.']);
                exit;
            }
            $quote      = mb_substr((string) ($_POST['quote'] ?? ''), 0, 2000);
            $rangeStart = isset($_POST['range_start']) && $_POST['range_start'] !== '' ? (int) $_POST['range_start'] : null;
            $rangeEnd   = isset($_POST['range_end']) && $_POST['range_end'] !== '' ? (int) $_POST['range_end'] : null;
            $posX       = isset($_POST['pos_x']) && $_POST['pos_x'] !== '' ? max(0.0, min(100.0, (float) $_POST['pos_x'])) : null;
            $posY       = isset($_POST['pos_y']) && $_POST['pos_y'] !== '' ? max(0.0, min(100.0, (float) $_POST['pos_y'])) : null;

            if ($annId > 0) {
                $own = $db->prepare(
                    "SELECT id FROM course_submission_annotations WHERE id = ? AND submission_id = ? AND course_id = ?"
                );
                $own->execute([$annId, $subId, $courseId]);
                if ($own->fetch()) {
                    $db->prepare(
                        "UPDATE course_submission_annotations
                         SET comment = ?, updated_at = datetime('now')
                         WHERE id = ?"
                    )->execute([$comment, $annId]);
                }
            } else {
                $db->prepare(
                    "INSERT INTO course_submission_annotations
                     (submission_id, course_id, author_id, anchor_type, range_start, range_end, quote, pos_x, pos_y, comment)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute([$subId, $courseId, (int) $me['id'], $anchorType, $rangeStart, $rangeEnd, $quote, $posX, $posY, $comment]);
                $annId = (int) $db->lastInsertId();
            }

            $row = $db->prepare(
                "SELECT a.*, u.name AS author_name
                 FROM course_submission_annotations a
                 LEFT JOIN users u ON u.id = a.author_id
                 WHERE a.id = ?"
            );
            $row->execute([$annId]);
            echo json_encode(['ok' => true, 'annotation' => $row->fetch() ?: null]);
            exit;

        } elseif ($action === 'delete_annotation' && portal_can_manage_course($courseId)) {
            header('Content-Type: application/json');
            $annId = (int) ($_POST['annotation_id'] ?? 0);
            $db->prepare(
                "DELETE FROM course_submission_annotations WHERE id = ? AND course_id = ?"
            )->execute([$annId, $courseId]);
            echo json_encode(['ok' => true]);
            exit;

        } elseif ($action === 'delete_submission') {
            $subId = (int) ($_POST['submission_id'] ?? 0);
            $sStmt = $db->prepare(
                "SELECT filepath FROM course_submissions WHERE id = ? AND course_id = ?"
            );
            $sStmt->execute([$subId, $courseId]);
            $sRow = $sStmt->fetch();
            if ($sRow) {
                $abs = portal_uploads_base() . DIRECTORY_SEPARATOR . $sRow['filepath'];
                if (is_file($abs)) @unlink($abs);
                // Removed submissions should not remain active plagiarism sources.
                $db->prepare("DELETE FROM integrity_sentence_index WHERE source_type = 'submission' AND source_id = ?")
                   ->execute([$subId]);
                $db->prepare("DELETE FROM course_submission_annotations WHERE submission_id = ? AND course_id = ?")
                   ->execute([$subId, $courseId]);
                $db->prepare("DELETE FROM course_submissions WHERE id = ? AND course_id = ?")
                   ->execute([$subId, $courseId]);
            }

        } elseif ($action === 'create_schedule_slot') {
            $day   = substr(trim((string)($_POST['day_of_week'] ?? '')), 0, 20);
            $start = substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
            $end   = substr(trim((string)($_POST['end_time'] ?? '')), 0, 10);
            $room  = substr(trim((string)($_POST['room'] ?? '')), 0, 500);
            $notes = substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $days  = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            if ($day !== '' && in_array($day, $days, true)) {
                $db->prepare(
                    "INSERT INTO course_schedule (course_id, day_of_week, start_time, end_time, room, notes)
                     VALUES (?,?,?,?,?,?)"
                )->execute([$courseId, $day, $start, $end, $room, $notes]);
            }

        } elseif ($action === 'update_schedule_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $day    = substr(trim((string)($_POST['day_of_week'] ?? '')), 0, 20);
            $start  = substr(trim((string)($_POST['start_time'] ?? '')), 0, 10);
            $end    = substr(trim((string)($_POST['end_time'] ?? '')), 0, 10);
            $room   = substr(trim((string)($_POST['room'] ?? '')), 0, 500);
            $notes  = substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $days   = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            if ($slotId > 0 && $day !== '' && in_array($day, $days, true)) {
                $db->prepare(
                    "UPDATE course_schedule SET day_of_week = ?, start_time = ?, end_time = ?, room = ?, notes = ?
                     WHERE id = ? AND course_id = ?"
                )->execute([$day, $start, $end, $room, $notes, $slotId, $courseId]);
            }

        } elseif ($action === 'delete_schedule_slot') {
            $slotId = (int)($_POST['slot_id'] ?? 0);
            $db->prepare("DELETE FROM course_schedule WHERE id = ? AND course_id = ?")
               ->execute([$slotId, $courseId]);

        } elseif ($action === 'create_topic') {
            if (portal_user_is_muted($me)) {
                $_SESSION['course_flash'] = ['error', 'Your account is muted and cannot start discussions.'];
            } else {
            $title = substr(trim((string)($_POST['title'] ?? '')), 0, 200);
            $body  = substr(portal_sanitize_rich_text(trim((string)($_POST['body'] ?? ''))), 0, 3000);
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_discussion_topics (course_id, user_id, title, body) VALUES (?,?,?,?)"
                )->execute([$courseId, (int)$me['id'], $title, $body]);
            }
            }

        } elseif ($action === 'delete_topic') {
            $topicId = (int)($_POST['topic_id'] ?? 0);
            $db->prepare("DELETE FROM course_discussion_topics WHERE id = ? AND course_id = ?")
               ->execute([$topicId, $courseId]);
            if ($topicId > 0) {
                $topicLink = 'course.php?course=' . urlencode((string) $course['slug'])
                    . '&section=discussions&topic=' . $topicId;
                portal_notifications_unsend($topicLink, true, 'discussion_reply');
            }

        } elseif ($action === 'create_group') {
            $title  = substr(trim((string)($_POST['title'] ?? '')), 0, 150);
            $desc   = substr(trim((string)($_POST['description'] ?? '')), 0, 400);
            $maxM   = max(0, (int)($_POST['max_members'] ?? 0));
            if ($title !== '') {
                $db->prepare(
                    "INSERT INTO course_groups (course_id, title, description, max_members) VALUES (?,?,?,?)"
                )->execute([$courseId, $title, $desc, $maxM]);
            }

        } elseif ($action === 'delete_group') {
            $gid = (int)($_POST['group_id'] ?? 0);
            $db->prepare("DELETE FROM course_groups WHERE id = ? AND course_id = ?")
               ->execute([$gid, $courseId]);

        } elseif ($action === 'update_course_description') {
            $summary = substr(trim((string)($_POST['summary'] ?? '')), 0, 500);
            if ($summary !== '') {
                $db->prepare("UPDATE courses SET summary = ? WHERE id = ?")
                   ->execute([$summary, $courseId]);
            }

        } elseif ($action === 'create_pre_enroll_quiz') {
            $created = portal_pre_enroll_create_or_open($courseId, (int) $me['id']);
            if (!empty($created['ok']) && (int) ($created['activity_id'] ?? 0) > 0) {
                portal_redirect('activity-builder.php?id=' . (int) $created['activity_id']);
            }
            $_SESSION['course_flash'] = ['error', (string) ($created['error'] ?? 'Could not set up the pre-enrolment quiz.')];

        } elseif ($action === 'remove_pre_enroll_quiz') {
            if (portal_pre_enroll_disable($courseId)) {
                $_SESSION['course_flash'] = ['success', 'The pre-enrolment quiz is off. Students can open this module without it.'];
            } else {
                $_SESSION['course_flash'] = ['error', 'Could not turn off the pre-enrolment quiz.'];
            }
        }
    }

    // ── Any logged-in user: reply to topic ───────────────────────────────────
    if ($action === 'post_reply') {
        if (portal_user_is_muted($me)) {
            $_SESSION['course_flash'] = ['error', 'Your account is muted and cannot reply to discussions.'];
        } else {
        $topicId = (int)($_POST['topic_id'] ?? 0);
        $body    = substr(portal_sanitize_rich_text(trim((string)($_POST['body'] ?? ''))), 0, 3000);
        // Verify topic belongs to this course
        $tChk = $db->prepare(
            "SELECT id, user_id, title FROM course_discussion_topics WHERE id = ? AND course_id = ?"
        );
        $tChk->execute([$topicId, $courseId]);
        $topicRow = $tChk->fetch();
        if ($topicRow && $body !== '') {
            $posterId = (int) $me['id'];

            // Soft de-dupe for double-clicks: two identical POSTs can arrive
            // before the browser navigates away. Same user + topic + body
            // within 10 seconds counts as one reply (no extra notifications).
            $dupStmt = $db->prepare(
                "SELECT id FROM course_discussion_replies
                 WHERE topic_id = ? AND user_id = ? AND body = ?
                   AND created_at >= datetime('now', '-10 seconds')
                 LIMIT 1"
            );
            $dupStmt->execute([$topicId, $posterId, $body]);
            $isDuplicateReply = (bool) $dupStmt->fetchColumn();

            if (!$isDuplicateReply) {
                $db->prepare(
                    "INSERT INTO course_discussion_replies (topic_id, course_id, user_id, body) VALUES (?,?,?,?)"
                )->execute([$topicId, $courseId, $posterId, $body]);
                $newReplyId = (int) $db->lastInsertId();

                // Personal alerts for thread starter, prior participants, and
                // course managers (notify_qa). Poster is excluded below.
                $recipientIds = [];
                $topicAuthorId = (int) $topicRow['user_id'];
                if ($topicAuthorId > 0 && $topicAuthorId !== $posterId) {
                    $recipientIds[$topicAuthorId] = true;
                }
                $partStmt = $db->prepare(
                    "SELECT DISTINCT user_id FROM course_discussion_replies
                     WHERE topic_id = ? AND user_id != ?"
                );
                $partStmt->execute([$topicId, $posterId]);
                foreach ($partStmt->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $pid = (int) $pid;
                    if ($pid > 0) {
                        $recipientIds[$pid] = true;
                    }
                }
                $managerStmt = $db->prepare(
                    "SELECT user_id FROM course_teachers WHERE course_id = ?"
                );
                $managerStmt->execute([$courseId]);
                foreach ($managerStmt->fetchAll(PDO::FETCH_COLUMN) as $managerId) {
                    $managerId = (int) $managerId;
                    if ($managerId > 0 && $managerId !== $posterId) {
                        $recipientIds[$managerId] = true;
                    }
                }
                // Site owners/admins manage every course — include them so staff
                // dashboards receive the same personal alerts students do.
                foreach ($db->query(
                    "SELECT id FROM users WHERE role IN ('owner','admin')"
                )->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                    $adminId = (int) $adminId;
                    if ($adminId > 0 && $adminId !== $posterId) {
                        $recipientIds[$adminId] = true;
                    }
                }

                if (!empty($recipientIds) && $newReplyId > 0) {
                    $posterName = trim((string) ($me['name'] ?? 'Someone')) ?: 'Someone';
                    $topicTitle = (string) $topicRow['title'];
                    $discLink = 'course.php?course=' . urlencode((string) $course['slug'])
                        . '&section=discussions&topic=' . $topicId
                        . '&reply=' . $newReplyId;
                    $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags($body)) ?? '');
                    $snippet = substr($plainBody !== '' ? $plainBody : 'New reply in the discussion.', 0, 160);
                    $notifTitle = $posterName . ' replied in “' . substr($topicTitle, 0, 80) . '”';
                    foreach (array_keys($recipientIds) as $rid) {
                        portal_notify_user(
                            (int) $rid,
                            'discussion_reply',
                            $notifTitle,
                            $snippet,
                            $discLink,
                            $courseId
                        );
                    }
                }
            }
        }
        }
    }

    if ($action === 'delete_reply') {
        $replyId = (int)($_POST['reply_id'] ?? 0);
        $replyMeta = null;
        if ($replyId > 0) {
            $replyMetaStmt = $db->prepare(
                "SELECT id, topic_id, body FROM course_discussion_replies WHERE id = ? AND course_id = ?"
            );
            $replyMetaStmt->execute([$replyId, $courseId]);
            $replyMeta = $replyMetaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $replyDeleted = false;
        if ($replyMeta) {
            if (portal_can_manage_course($courseId)) {
                $delReply = $db->prepare("DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ?");
                $delReply->execute([$replyId, $courseId]);
                $replyDeleted = $delReply->rowCount() > 0;
            } else {
                $delReply = $db->prepare(
                    "DELETE FROM course_discussion_replies WHERE id = ? AND course_id = ? AND user_id = ?"
                );
                $delReply->execute([$replyId, $courseId, (int)$me['id']]);
                $replyDeleted = $delReply->rowCount() > 0;
            }
        }
        if ($replyDeleted && $replyMeta) {
            $topicId = (int) ($replyMeta['topic_id'] ?? 0);
            $topicBase = 'course.php?course=' . urlencode((string) $course['slug'])
                . '&section=discussions&topic=' . $topicId;
            portal_notifications_unsend($topicBase . '&reply=' . $replyId, false, 'discussion_reply');
            // Legacy alerts (no reply= id): match the same topic link + body snippet.
            $plainBody = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($replyMeta['body'] ?? ''))) ?? '');
            $snippet = substr($plainBody !== '' ? $plainBody : 'New reply in the discussion.', 0, 160);
            $db->prepare(
                "DELETE FROM portal_notifications
                 WHERE type = 'discussion_reply' AND course_id = ? AND link = ? AND body = ?"
            )->execute([$courseId, $topicBase, $snippet]);
        }
    }

    // ── Students: join / leave group ─────────────────────────────────────────
    if ($action === 'join_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $uid = (int)$me['id'];
        $gStmt = $db->prepare("SELECT max_members FROM course_groups WHERE id = ? AND course_id = ?");
        $gStmt->execute([$gid, $courseId]);
        $gRow = $gStmt->fetch();
        if (!$gRow) {
            $_SESSION['course_flash'] = ['error', 'That group could not be found.'];
        } else {
            $alreadyIn = $db->prepare(
                "SELECT cgm.group_id
                 FROM course_group_members cgm
                 JOIN course_groups cg ON cg.id = cgm.group_id
                 WHERE cg.course_id = ? AND cgm.user_id = ?
                 LIMIT 1"
            );
            $alreadyIn->execute([$courseId, $uid]);
            $existingGroupId = (int) ($alreadyIn->fetchColumn() ?: 0);

            if ($existingGroupId > 0 && $existingGroupId !== $gid) {
                $_SESSION['course_flash'] = ['error', 'You are already in a group for this module. Leave it before joining another.'];
            } elseif ($existingGroupId === $gid) {
                $_SESSION['course_flash'] = ['success', 'You are already in that group.'];
            } else {
                $maxM = (int)$gRow['max_members'];
                $canJoin = true;
                if ($maxM > 0) {
                    $cntStmt = $db->prepare("SELECT COUNT(*) FROM course_group_members WHERE group_id = ?");
                    $cntStmt->execute([$gid]);
                    $canJoin = ((int) $cntStmt->fetchColumn()) < $maxM;
                }
                if (!$canJoin) {
                    $_SESSION['course_flash'] = ['error', 'That group is full.'];
                } else {
                    $db->prepare("INSERT OR IGNORE INTO course_group_members (group_id, user_id) VALUES (?,?)")
                       ->execute([$gid, $uid]);
                    $_SESSION['course_flash'] = ['success', 'You joined the group.'];
                }
            }
        }
    }

    if ($action === 'leave_group') {
        $gid = (int)($_POST['group_id'] ?? 0);
        $db->prepare(
            "DELETE FROM course_group_members
             WHERE group_id = ?
               AND user_id = ?
               AND EXISTS (
                   SELECT 1 FROM course_groups
                   WHERE course_groups.id = course_group_members.group_id
                     AND course_groups.course_id = ?
               )"
        )->execute([$gid, (int)$me['id'], $courseId]);
        $_SESSION['course_flash'] = ['success', 'You left the group.'];
    }

    // ── Teacher: re-run originality / AI checks on a submission ───────────────
    if ($action === 'rerun_integrity' && portal_can_manage_course($courseId)) {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        if ($submissionId > 0) {
            $owner = $db->prepare("SELECT course_id FROM course_submissions WHERE id = ?");
            $owner->execute([$submissionId]);
            $ownerRow = $owner->fetch();
            if ($ownerRow && (int) $ownerRow['course_id'] === $courseId) {
                portal_rerun_submission_integrity($db, $submissionId, true);
                $_SESSION['course_flash'] = ['success', 'Originality and AI checks were re-run for this submission.'];
            }
        }
    }

    // ── Student: submit work to a submission slot ─────────────────────────────
    if ($action === 'submit_work') {
        $submitJsonExit = static function (bool $ok, array $data = [], string $error = '') use ($slug): void {
            if (portal_is_fetch_request()) {
                portal_json_response($ok ? array_merge(['ok' => true], $data) : ['ok' => false, 'error' => $error]);
            }
            portal_redirect('course.php?course=' . urlencode($slug));
        };

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $uid = (int) $me['id'];

        // Authorisation: must be able to access course; students submit work.
        if (!portal_can_access_course($courseId) || portal_current_user_role() !== 'student') {
            portal_log_security_event('unauthorised_course_access', 'medium', 'Blocked submit_work');
            $_SESSION['course_flash'] = ['error', 'You are not allowed to submit work for this course.'];
            $submitJsonExit(false, [], 'You are not allowed to submit work for this course.');
        }

        if (portal_user_is_restricted($me)) {
            $_SESSION['course_flash'] = ['error', 'Your account is restricted and cannot submit coursework.'];
            $submitJsonExit(false, [], 'Your account is restricted and cannot submit coursework.');
        }

        $slotChk = $db->prepare(
            "SELECT cfi.id, cfi.title, cfi.submission_deadline, cfi.submission_ai_detection,
                    cfi.submission_max_attempts, cfi.description, cfi.locked,
                    COALESCE(cf.locked, 0) AS folder_locked
             FROM course_folder_items cfi
             LEFT JOIN course_folders cf ON cf.id = cfi.folder_id
             WHERE cfi.id = ? AND cfi.course_id = ? AND cfi.type = 'submission'"
        );
        $slotChk->execute([$itemId, $courseId]);
        $slot = $slotChk->fetch();
        if (!$slot) {
            $_SESSION['course_flash'] = ['error', 'Submission slot not found.'];
            $submitJsonExit(false, [], 'Submission slot not found.');
        }

        if (portal_folder_item_content_locked($slot)) {
            portal_log_security_event(
                'unauthorised_course_access',
                'medium',
                'Blocked submit_work on locked item ' . $itemId
            );
            $_SESSION['course_flash'] = ['error', 'This submission is locked by your teacher.'];
            $submitJsonExit(false, [], 'This submission is locked by your teacher.');
        }

        $maxAttempts = (int) ($slot['submission_max_attempts'] ?? 0);
        $attemptsUsed = 0;
        if ($maxAttempts > 0) {
            $attemptsStmt = $db->prepare(
                "SELECT COUNT(*) FROM course_submission_versions WHERE item_id = ? AND user_id = ?"
            );
            $attemptsStmt->execute([$itemId, $uid]);
            $attemptsUsed = (int) $attemptsStmt->fetchColumn();
        }

        $pastedText = portal_integrity_normalize_text((string) ($_POST['submission_text'] ?? ''));
        $fileField = isset($_FILES['submission_file']) && is_array($_FILES['submission_file'])
            ? $_FILES['submission_file']
            : null;
        $subError = (int) ($fileField['error'] ?? UPLOAD_ERR_NO_FILE);
        $hasFile = $fileField !== null && $subError !== UPLOAD_ERR_NO_FILE;
        $declaredType = strtolower(trim((string) ($_POST['submission_type'] ?? '')));
        if (!$hasFile && $pastedText !== '') {
            $declaredType = 'txt';
        }

        $deadlineTs = $slot['submission_deadline'] !== '' ? strtotime((string) $slot['submission_deadline']) : false;
        $processEditSeconds = max(0, min(86400, (int) ($_POST['process_edit_seconds'] ?? 0)));
        $processPasteEvents = max(0, min(1000, (int) ($_POST['process_paste_events'] ?? 0)));
        $processPastedChars = max(0, min(1000000, (int) ($_POST['process_pasted_chars'] ?? 0)));

        $validated = null;
        $originalName = 'pasted-text-submission.txt';
        $ext = 'txt';
        $subSize = 0;
        $tmpPath = '';

        if ($deadlineTs !== false && time() > $deadlineTs) {
            $_SESSION['course_flash'] = ['error', 'This submission deadline has passed. Ask your teacher if you need an extension.'];
            $submitJsonExit(false, [], 'This submission deadline has passed. Ask your teacher if you need an extension.');
        } elseif (!$hasFile && $pastedText === '') {
            $_SESSION['course_flash'] = ['error', 'Upload a document or paste your submission text before submitting.'];
            $submitJsonExit(false, [], 'Upload a document or paste your submission text before submitting.');
        } elseif ($hasFile) {
            $validated = portal_validate_submission_upload($fileField, $declaredType);
            if (!$validated['ok']) {
                $reason = (string) ($validated['reason'] ?? 'upload_error');
                $phpDetail = $subError !== UPLOAD_ERR_OK ? $uploadErrorMessage($subError) : '';
                portal_log_blocked_upload_reason($reason, $phpDetail);
                $msg = (string) ($validated['public_message'] ?? portal_submission_upload_generic_message());
                $_SESSION['course_flash'] = ['error', $msg];
                $submitJsonExit(false, [], $msg);
            }
            $originalName = (string) $validated['display_name'];
            $ext = (string) $validated['extension'];
            $subSize = (int) $validated['size'];
            $tmpPath = (string) $validated['tmp_path'];
            $declaredType = (string) $validated['declared_type'];
        } elseif ($declaredType !== 'txt') {
            portal_log_blocked_upload_reason('missing_type');
            $msg = portal_submission_type_mismatch_message();
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }

        // Pre-check content length before replacing an existing submission.
        $extractableExts = ['txt', 'doc', 'docx', 'pdf'];
        $precheckText = $pastedText;
        if ($hasFile && in_array($ext, $extractableExts, true) && $tmpPath !== '' && is_file($tmpPath)) {
            $precheckFileText = portal_extract_submission_text($tmpPath, $originalName);
            $precheckText = trim($pastedText . "\n\n" . $precheckFileText);
        }
        $precheckWordCount = count(portal_integrity_words($precheckText));
        $precheckCharCount = mb_strlen(portal_integrity_normalize_text($precheckText));
        $minWords = portal_submission_min_words();
        $minChars = portal_submission_min_chars();
        $shouldCheckLength = !$hasFile || (in_array($ext, $extractableExts, true) && $precheckCharCount > 0);
        $submissionTooShort = $shouldCheckLength
            && ($precheckWordCount < $minWords || $precheckCharCount < $minChars);

        if ($submissionTooShort) {
            $msg = 'Your submission is too short (' . $precheckWordCount . ' word' . ($precheckWordCount === 1 ? '' : 's')
                 . '). Please write a more complete response of at least ' . $minWords . ' words before submitting.';
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }
        if ($maxAttempts > 0 && $attemptsUsed >= $maxAttempts) {
            $msg = 'You have already used all ' . $maxAttempts . ' allowed submission attempt' . ($maxAttempts === 1 ? '' : 's') . ' for this assignment.';
            $_SESSION['course_flash'] = ['error', $msg];
            $submitJsonExit(false, [], $msg);
        }

        $prevStmt = $db->prepare(
            "SELECT id, filepath FROM course_submissions WHERE item_id = ? AND user_id = ?"
        );
        $prevStmt->execute([$itemId, $uid]);
        $prev = $prevStmt->fetch();
        $prevAbs = '';
        if ($prev && $prev['filepath'] !== '') {
            $prevAbs = portal_uploads_base() . DIRECTORY_SEPARATOR . $prev['filepath'];
        }

        $dir = portal_submissions_storage_dir($itemId, $uid);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            portal_log_blocked_upload_reason('move_failed', 'mkdir');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $safe = portal_new_submission_storage_name($ext);
        $savedAbs = $dir . DIRECTORY_SEPARATOR . $safe;
        if (is_file($savedAbs)) {
            // Collision — generate again once.
            $safe = portal_new_submission_storage_name($ext);
            $savedAbs = $dir . DIRECTORY_SEPARATOR . $safe;
        }
        if (is_file($savedAbs)) {
            portal_log_blocked_upload_reason('move_failed', 'exists');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $relPath = 'submissions' . DIRECTORY_SEPARATOR . $itemId
                 . DIRECTORY_SEPARATOR . $uid . DIRECTORY_SEPARATOR . $safe;
        $receiptNumber = portal_generate_unique_receipt_number($db);
        $tempHash = ($hasFile && $tmpPath !== '') ? hash_file('sha256', $tmpPath) : '';

        $saved = false;
        if ($hasFile) {
            $saved = move_uploaded_file($tmpPath, $savedAbs);
        } else {
            $saved = file_put_contents($savedAbs, $pastedText) !== false;
            $subSize = is_file($savedAbs) ? (int) filesize($savedAbs) : 0;
        }

        if (!$saved) {
            portal_log_blocked_upload_reason('move_failed');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        @chmod($savedAbs, 0640);

        $fileHash = is_file($savedAbs) ? (string) hash_file('sha256', $savedAbs) : '';
        if ($hasFile && $tempHash !== '' && $fileHash !== '' && !hash_equals($tempHash, $fileHash)) {
            @unlink($savedAbs);
            portal_log_blocked_upload_reason('move_failed', 'hash_mismatch');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        $extraction = $hasFile
            ? portal_extract_submission_text_detailed($savedAbs, $originalName)
            : [
                'text' => '',
                'extractor' => 'paste',
                'char_count' => 0,
                'word_count' => 0,
                'confidence' => 'high',
                'note' => '',
            ];
        $fileText = (string) ($extraction['text'] ?? '');
        $combinedText = portal_integrity_normalize_text(trim($pastedText . "\n\n" . $fileText));
        if ($combinedText !== '' && function_exists('mb_substr')) {
            $combinedText = mb_substr($combinedText, 0, 200000);
        } elseif ($combinedText !== '') {
            $combinedText = substr($combinedText, 0, 200000);
        }
        if ($pastedText !== '' && count(portal_integrity_words($pastedText)) >= 25) {
            $extraction['confidence'] = 'high';
            $extraction['note'] = '';
        } elseif (!$hasFile) {
            $extraction['confidence'] = 'high';
            $extraction['extractor'] = 'paste';
        }

        $fileMetadata = is_file($savedAbs)
            ? portal_extract_submission_file_metadata($savedAbs, $originalName)
            : ['available' => false, 'format' => $ext];
        $slotInstructions = trim((string) ($slot['description'] ?? ''));
        $submissionContext = [
            'course_id' => $courseId,
            'submission_ai_detection' => (int) ($slot['submission_ai_detection'] ?? 0),
            'process_edit_seconds' => $processEditSeconds,
            'process_paste_events' => $processPasteEvents,
            'process_pasted_chars' => $processPastedChars,
            'file_metadata' => $fileMetadata,
            'student_name' => (string) ($me['name'] ?? ''),
            'extraction' => $extraction,
            'slot_instructions' => $slotInstructions,
        ];
        $similarity = portal_integrity_check_similarity(
            $db,
            $combinedText,
            $courseId,
            $itemId,
            $uid,
            $fileHash,
            null,
            $submissionContext
        );

        $dbOk = false;
        $submissionId = 0;
        try {
            $db->beginTransaction();

            if ($prev && (int) ($prev['id'] ?? 0) > 0) {
                $prevSubId = (int) $prev['id'];
                $db->prepare("DELETE FROM integrity_sentence_index WHERE source_type = 'submission' AND source_id = ?")
                   ->execute([$prevSubId]);
                $db->prepare("DELETE FROM course_submission_annotations WHERE submission_id = ? AND course_id = ?")
                   ->execute([$prevSubId, $courseId]);
            }

            $db->prepare(
                "INSERT INTO course_submissions
                 (item_id, course_id, user_id, filename, filepath, filesize,
                  receipt_number, file_sha256, submission_text, text_word_count,
                  similarity_status, similarity_score, similarity_report, similarity_checked_at,
                  process_edit_seconds, process_paste_events, process_pasted_chars, declared_file_type)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'),?,?,?,?)
                 ON CONFLICT(item_id, user_id) DO UPDATE
                 SET filename=excluded.filename, filepath=excluded.filepath,
                     filesize=excluded.filesize, submitted_at=datetime('now'),
                     receipt_number=excluded.receipt_number,
                     file_sha256=excluded.file_sha256,
                     submission_text=excluded.submission_text,
                     text_word_count=excluded.text_word_count,
                     similarity_status=excluded.similarity_status,
                     similarity_score=excluded.similarity_score,
                     similarity_report=excluded.similarity_report,
                     similarity_checked_at=datetime('now'),
                     process_edit_seconds=excluded.process_edit_seconds,
                     process_paste_events=excluded.process_paste_events,
                     process_pasted_chars=excluded.process_pasted_chars,
                     declared_file_type=excluded.declared_file_type,
                     score=NULL, feedback='', marked_at='', marked_by=NULL, grade_seen_at='', grades_released_at='',
                     ai_status='', ai_score=NULL, ai_report='', ai_checked_at=''"
            )->execute([
                $itemId, $courseId, $uid,
                $originalName,
                $relPath,
                $subSize,
                $receiptNumber,
                $fileHash,
                $combinedText,
                (int) $similarity['word_count'],
                $similarity['status'],
                $similarity['score'],
                $similarity['report'],
                $processEditSeconds,
                $processPasteEvents,
                $processPastedChars,
                $declaredType,
            ]);

            $subIdStmt = $db->prepare("SELECT id FROM course_submissions WHERE item_id = ? AND user_id = ?");
            $subIdStmt->execute([$itemId, $uid]);
            $submissionId = (int) ($subIdStmt->fetchColumn() ?: 0);

            if ($submissionId > 0) {
                $db->prepare(
                    "INSERT INTO course_submission_versions
                     (submission_id, item_id, course_id, user_id, filename, filesize, file_sha256,
                      text_word_count, receipt_number, similarity_status, similarity_score,
                      process_edit_seconds, process_paste_events, process_pasted_chars)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $submissionId,
                    $itemId,
                    $courseId,
                    $uid,
                    $originalName,
                    $subSize,
                    $fileHash,
                    (int) $similarity['word_count'],
                    $receiptNumber,
                    $similarity['status'],
                    $similarity['score'],
                    $processEditSeconds,
                    $processPasteEvents,
                    $processPastedChars,
                ]);
            }

            $db->commit();
            $dbOk = true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (is_file($savedAbs) && !@unlink($savedAbs)) {
                portal_log_security_event('blocked_upload', 'medium', 'Cleanup failed after DB error');
            }
            portal_log_blocked_upload_reason('move_failed', 'db');
            $_SESSION['course_flash'] = ['error', portal_submission_upload_generic_message()];
            $submitJsonExit(false, [], portal_submission_upload_generic_message());
        }

        if ($dbOk && $prevAbs !== '' && is_file($prevAbs) && realpath($prevAbs) !== realpath($savedAbs)) {
            @unlink($prevAbs);
        }

        if ($submissionId > 0) {
            $studentName = (string) ($me['name'] ?? 'Student');
            portal_integrity_index_document(
                $db,
                $combinedText,
                'submission',
                $submissionId,
                $studentName . ' - submission #' . $submissionId,
                $courseId
            );
        }

        if (portal_external_ai_should_run([
            'course_id' => $courseId,
            'submission_ai_detection' => (int) ($slot['submission_ai_detection'] ?? 0),
        ])) {
            $isPptxSubmission = in_array((string) ($extraction['extractor'] ?? ''), ['pptx', 'pptx-soffice'], true)
                || in_array($ext, ['ppt', 'pptx', 'pps', 'ppsx'], true)
                || $declaredType === 'pptx';
            if ($isPptxSubmission) {
                $db->prepare(
                    "UPDATE course_submissions
                     SET ai_status = ?, ai_score = NULL, ai_report = ?, ai_checked_at = datetime('now')
                     WHERE item_id = ? AND user_id = ?"
                )->execute([
                    'disabled',
                    'AI detection is disabled for PowerPoint submissions.',
                    $itemId,
                    $uid,
                ]);
            } else {
                $ai = portal_gptzero_detection($combinedText);
                $db->prepare(
                    "UPDATE course_submissions
                     SET ai_status = ?, ai_score = ?, ai_report = ?, ai_checked_at = datetime('now')
                     WHERE item_id = ? AND user_id = ?"
                )->execute([
                    $ai['status'],
                    $ai['score'],
                    $ai['report'],
                    $itemId,
                    $uid,
                ]);
            }
        }

        $submittedAt = '';
        if ($submissionId > 0) {
            $subAtStmt = $db->prepare("SELECT submitted_at FROM course_submissions WHERE id = ?");
            $subAtStmt->execute([$submissionId]);
            $submittedAt = (string) ($subAtStmt->fetchColumn() ?: '');
        }
        $submittedAtLabel = $submittedAt !== '' ? date('j M Y H:i', strtotime($submittedAt)) : '';
        $hashPrefix = $fileHash !== '' ? strtoupper(substr($fileHash, 0, 12)) : '';

        $_SESSION['course_flash'] = ['success', 'Submission received.'];

        $submitJsonExit(true, [
            'submission_id'      => $submissionId,
            'item_id'            => $itemId,
            'filename'           => $originalName,
            'declared_type'      => $declaredType,
            'submitted_at'       => $submittedAt,
            'submitted_at_label' => $submittedAtLabel,
            'receipt_number'     => $receiptNumber,
            'file_sha256_prefix' => $hashPrefix,
            'student_name'       => (string) ($me['name'] ?? ''),
            'student_username'   => (string) ($me['username'] ?? ''),
            'course_title'       => (string) ($course['title'] ?? ''),
            'assignment_title'   => (string) ($slot['title'] ?? 'Assignment'),
            'is_resubmit'        => (bool) $prev,
            'message'            => 'Submission received.',
            'max_attempts'       => $maxAttempts,
            'attempts_used'      => $maxAttempts > 0 ? $attemptsUsed + 1 : 0,
            'attempts_reached'   => $maxAttempts > 0 && ($attemptsUsed + 1) >= $maxAttempts,
        ]);
    }

    $rBase = 'course.php?course=' . urlencode($slug);
    if ($action === 'create_item' && portal_is_fetch_request()) {
        $flash = $_SESSION['course_flash'] ?? null;
        $ok = is_array($flash) && ($flash[0] ?? '') === 'success';
        $message = is_array($flash) ? (string) ($flash[1] ?? '') : '';
        // Keep success flash so the redirected page can show the toast.
        if (!$ok) {
            unset($_SESSION['course_flash']);
        }
        portal_json_response([
            'ok' => $ok,
            'message' => $message,
            'error' => $ok ? '' : ($message !== '' ? $message : 'Could not add item.'),
            'redirect' => $ok ? ($rBase . '&section=content') : '',
        ]);
    }
    if (portal_is_fetch_request() && in_array($action, ['mark_submission', 'submit_work'], true)) {
        portal_json_response(['ok' => false, 'error' => 'Unexpected response.'], 500);
    }
    if (in_array($action, ['mark_submission', 'release_submission_grades'], true)) {
        portal_redirect($rBase . '&section=gradebook');
    } elseif (in_array($action, ['create_schedule_slot','update_schedule_slot','delete_schedule_slot'])) {
        // Keep courses.meeting / courses.room aligned with live calendar slots.
        portal_sync_course_meeting_from_schedule($courseId);
        portal_redirect($rBase . '&section=calendar');
    } elseif ($action === 'update_course_description') {
        $retSec = (string)($_POST['return_section'] ?? 'content');
        $validSecs = ['content','calendar','announcements','discussions','gradebook','groups'];
        portal_redirect($rBase . '&section=' . (in_array($retSec, $validSecs, true) ? $retSec : 'content'));
    } elseif (in_array($action, ['post_announcement','delete_announcement'])) {
        portal_redirect($rBase . '&section=announcements');
    } elseif (in_array($action, ['create_topic','delete_topic'])) {
        portal_redirect($rBase . '&section=discussions');
    } elseif (in_array($action, ['post_reply','delete_reply'])) {
        $tid = (int)($_POST['topic_id'] ?? 0);
        portal_redirect($rBase . '&section=discussions' . ($tid > 0 ? '&topic=' . $tid : ''));
    } elseif (in_array($action, ['create_group','delete_group','join_group','leave_group'])) {
        portal_redirect($rBase . '&section=groups');
    } else {
        portal_redirect($rBase . '&section=content');
    }
}
