<?php
declare(strict_types=1);

const DB_PATH      = __DIR__ . '/serie_b.sqlite';
const STAGIONE     = '2025-2026';
const NUM_SLOT     = 6;
const POSTA_BASE   = 5.0;
const MAX_GIORNATA = 38;

// Sportradar: endpoint stagione completa Serie B 2025/26
// (stesso usato in serieB_draw/sync_giornata.php)
const SR_URL = 'https://stats.fn.sportradar.com/snai/it/Europe:Berlin/gismo/stats_season_fixtures2/130973/1';
