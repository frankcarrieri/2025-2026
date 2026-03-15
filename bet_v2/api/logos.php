<?php
/**
 * POST /api/logos  → salva i loghi Lega B nel DB (match fuzzy sul nome squadra)
 * GET  /api/logos  → ritorna tutte le squadre con logo_url
 */
require_once __DIR__ . '/_bootstrap.php';

const BASE_URL = 'https://d5rzfs5ck83rq.cloudfront.net/legab.it/img/club/loghi/';

/**
 * Mappa: parola chiave distintiva (lowercase) → file logo.
 * La chiave è una parola presente nel nome Lega B della squadra
 * che la identifica univocamente nel DB.
 */
const LOGHI = [
    'avellino'  => 'avellino.png',
    'bari'      => 'bari.png',
    'carrarese' => 'carrarese.png',
    'catanzaro' => 'catanzaro.png',
    'cesena'    => 'cesena.png',
    'empoli'    => 'empoli.png',
    'frosinone' => 'frosinone.png',
    'stabia'    => 'juve_stabia_scuro.png',
    'mantova'   => 'mantova.png',
    'modena'    => 'modena-blu-b.png',
    'monza'     => 'monza.png',
    'padova'    => 'padova.png',
    'palermo'   => 'palermo.png',
    'pescara'   => 'pescara.png',
    'reggiana'  => 'reggiana_2025.png',
    'sampdoria' => 'sampdoria.png',
    'spezia'    => 'spezia-2025-b.png',
    'sudtirol'  => 'sudtirol.png',
    'venezia'   => 'venezia.png',
    'entella'   => 'entella.png',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = Database::query(
        "SELECT id, nome, logo_url FROM squadre ORDER BY nome"
    );
    json_ok(['squadre' => $rows]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

// Carica tutte le squadre dal DB
$squadre = Database::query("SELECT id, nome FROM squadre");

$aggiornate = 0;
$nonTrovate = [];

foreach ($squadre as $sq) {
    $nomeDb = strtolower($sq['nome']); // es. "juve stabia", "entella", "sudtirol"

    $logoFile = null;
    foreach (LOGHI as $keyword => $file) {
        if (strpos($nomeDb, $keyword) !== false) {
            $logoFile = $file;
            break;
        }
    }

    if ($logoFile !== null) {
        Database::execute(
            "UPDATE squadre SET logo_url = ? WHERE id = ?",
            [BASE_URL . $logoFile, $sq['id']]
        );
        $aggiornate++;
    } else {
        $nonTrovate[] = $sq['nome'];
    }
}

json_ok([
    'ok'          => true,
    'aggiornate'  => $aggiornate,
    'non_trovate' => $nonTrovate,
]);
