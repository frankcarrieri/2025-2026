<?php
/**
 * GET /api/storico
 * Ritorna il P&L per giornata e il saldo cumulativo.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$rows = Database::query("
    SELECT
        b.id_giornata,
        ROUND(SUM(COALESCE(b.saldo_variazione, 0)), 2)  AS saldo_giornata,
        COUNT(CASE WHEN b.esito = 'WIN'  THEN 1 END)    AS win,
        COUNT(CASE WHEN b.esito = 'LOSS' THEN 1 END)    AS loss,
        COUNT(CASE WHEN b.esito = 'SKIP' THEN 1 END)    AS skip
    FROM bet b
    GROUP BY b.id_giornata
    ORDER BY b.id_giornata ASC
");

// Calcola saldo cumulativo in PHP (SQLite < 3.25 non ha window functions)
$cumul = 0.0;
$storico = [];
foreach ($rows as $r) {
    $cumul += (float)$r['saldo_giornata'];
    $storico[] = [
        'giornata'         => (int)$r['id_giornata'],
        'saldo_giornata'   => round((float)$r['saldo_giornata'], 2),
        'saldo_cumulativo' => round($cumul, 2),
        'win'              => (int)$r['win'],
        'loss'             => (int)$r['loss'],
        'skip'             => (int)$r['skip'],
    ];
}

json_ok(['storico' => $storico]);
