<?php
/**
 * Router per PHP built-in server.
 * Uso: php -S localhost:8080 server.php
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Serve file statici da public/
if ($path !== '/' && file_exists(__DIR__ . '/public' . $path)) {
    $mime = [
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mime[$ext] ?? 'application/octet-stream'));
    readfile(__DIR__ . '/public' . $path);
    exit;
}

// Route API
if (strpos($path, '/api/') === 0) {
    $segments = explode('/', trim($path, '/'));
    // $segments[0] = 'api', $segments[1] = endpoint name, $segments[2..] = params

    $endpoint = $segments[1] ?? '';

    // Inietta parametri route in $_GET per comodità
    if ($endpoint === 'giornata' && isset($segments[2])) {
        $_GET['n'] = $segments[2];
    }

    $file = __DIR__ . '/api/' . $endpoint . '.php';
    if (file_exists($file)) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
        require $file;
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => "Endpoint '$endpoint' non trovato"]);
    exit;
}

// Tutto il resto → index.html (SPA)
readfile(__DIR__ . '/public/index.html');
