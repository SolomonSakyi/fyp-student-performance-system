<?php
/**
 * Router.php - Root Level Router
 * Routes all requests to the appropriate handler
 * 
 * @filepath router.php
 */

// Get the request path
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// If this is an API request, route to the API router
if (strpos($path, '/api/') === 0 || $path === '/api') {
    require_once __DIR__ . '/public/api/platform/index.php';
    exit;
}

// If the request is for a public file, serve it
$publicFile = __DIR__ . '/public' . $path;
if (file_exists($publicFile) && !is_dir($publicFile)) {
    // Serve the file
    $ext = pathinfo($publicFile, PATHINFO_EXTENSION);
    switch ($ext) {
        case 'css':
            header('Content-Type: text/css');
            break;
        case 'js':
            header('Content-Type: application/javascript');
            break;
        case 'png':
            header('Content-Type: image/png');
            break;
        case 'jpg':
        case 'jpeg':
            header('Content-Type: image/jpeg');
            break;
        case 'gif':
            header('Content-Type: image/gif');
            break;
        default:
            header('Content-Type: text/html');
    }
    readfile($publicFile);
    exit;
}

// Otherwise, route to public/index.php
require_once __DIR__ . '/public/index.php';