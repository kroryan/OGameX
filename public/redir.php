<?php

/**
 * Redirect handler for AJAX requests.
 * This file handles URL redirection for legacy AJAX calls.
 */

// Parse the URL parameter
$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing URL parameter']);
    exit;
}

// Security: only allow relative URLs or same-origin URLs
$parsedUrl = parse_url($url);
if ($parsedUrl === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid URL']);
    exit;
}

// Check if it's a relative URL or same-origin
if (isset($parsedUrl['host']) && $parsedUrl['host'] !== $_SERVER['HTTP_HOST']) {
    http_response_code(403);
    echo json_encode(['error' => 'External URLs not allowed']);
    exit;
}

// Return JSON response with the redirect URL
header('Content-Type: application/json');
echo json_encode([
    'redirect' => $url,
    'success' => true,
]);
