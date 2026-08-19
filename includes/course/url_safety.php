<?php
declare(strict_types=1);

function portal_course_normalize_external_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('/^https?:\/\//i', $url)
        && preg_match('/^(?:www\.)?[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)+(?:[\/?#].*)?$/i', $url)) {
        $url = 'https://' . $url;
    }
    if (!preg_match('/^https?:\/\//i', $url)) {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    return $url;
}

function portal_google_safe_browsing_api_key(): string
{
    $fromDb = function_exists('portal_site_setting_get') ? portal_site_setting_get('google_safe_browsing_api_key', '') : '';
    if ($fromDb !== '') {
        return $fromDb;
    }
    return trim((string) getenv('GOOGLE_SAFE_BROWSING_API_KEY'));
}

function portal_course_google_safe_browsing_url_check(string $url): array
{
    $url = portal_course_normalize_external_url($url);
    if ($url === '') {
        return ['status' => 'invalid', 'configured' => false, 'message' => 'This is not a valid external URL.'];
    }

    $apiKey = portal_google_safe_browsing_api_key();
    if ($apiKey === '') {
        return ['status' => 'unchecked', 'configured' => false, 'message' => 'Google Safe Browsing is not configured, so this link could not be verified automatically.'];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 'unchecked', 'configured' => true, 'message' => 'Google Safe Browsing could not be contacted because cURL is unavailable on this server.'];
    }

    $ch = curl_init('https://safebrowsing.googleapis.com/v4/threatMatches:find?key=' . rawurlencode($apiKey));
    $payload = json_encode([
        'client' => [
            'clientId' => 'schoolwebsite',
            'clientVersion' => '1.0',
        ],
        'threatInfo' => [
            'threatTypes' => [
                'MALWARE',
                'SOCIAL_ENGINEERING',
                'UNWANTED_SOFTWARE',
                'POTENTIALLY_HARMFUL_APPLICATION',
            ],
            'platformTypes' => ['ANY_PLATFORM'],
            'threatEntryTypes' => ['URL'],
            'threatEntries' => [
                ['url' => $url],
            ],
        ],
    ], JSON_UNESCAPED_SLASHES);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 15,
    ]);
    $raw = (string) curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '' || $http < 200 || $http >= 300) {
        return [
            'status' => 'unchecked',
            'configured' => true,
            'message' => 'Google Safe Browsing could not be reached. Treat this external link with caution.',
            'http' => $http,
        ];
    }

    $json = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($json)) {
        return [
            'status' => 'unchecked',
            'configured' => true,
            'message' => 'Google Safe Browsing returned an unreadable response. Treat this external link with caution.',
            'http' => $http,
        ];
    }

    $matches = is_array($json['matches'] ?? null) ? $json['matches'] : [];
    if (empty($matches)) {
        return [
            'status' => 'safe',
            'configured' => true,
            'message' => 'Google Safe Browsing did not report safety threats for this URL.',
            'threat_types' => [],
        ];
    }

    $threatTypes = array_values(array_unique(array_filter(array_map(
        static fn($match): string => is_array($match) ? (string) ($match['threatType'] ?? '') : '',
        $matches
    ))));
    $status = in_array('MALWARE', $threatTypes, true) ? 'malicious' : 'suspicious';
    $message = !empty($threatTypes)
        ? 'Google Safe Browsing reports a possible threat: ' . implode(', ', $threatTypes) . '.'
        : 'Google Safe Browsing reports a possible safety threat for this URL.';
    return [
        'status' => $status,
        'configured' => true,
        'message' => $message,
        'threat_types' => $threatTypes,
    ];
}

// Integrity helpers live in ../integrity.php (loaded via bootstrap.php).
