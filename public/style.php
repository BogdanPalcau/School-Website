<?php
declare(strict_types=1);

// Standalone PHP servers use public/ as their document root, so the canonical
// stylesheet one directory above it is not directly addressable. Keep one CSS
// source of truth and expose only that fixed file path.
$stylesheet = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'style.css';

if (!is_file($stylesheet)) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($stylesheet);
