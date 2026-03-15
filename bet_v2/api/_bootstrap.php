<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Scraper.php';
require_once __DIR__ . '/../src/Importer.php';
require_once __DIR__ . '/../src/TopSixService.php';
require_once __DIR__ . '/../src/BetService.php';

Schema::create();

function json_ok($data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function json_err(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function input(): array
{
    $body = file_get_contents('php://input');
    return json_decode((string)$body, true) ?? [];
}
