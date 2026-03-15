<?php
/**
 * POST /api/sync
 * Scarica dati da Sportradar, importa, elabora giornate completate.
 * Risponde con un riepilogo delle operazioni eseguite.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$log = [];

// 1. Fetch da Sportradar
try {
    $tutteLePartite = Scraper::fetchAll();
    $totPartite  = array_sum(array_map('count', $tutteLePartite));
    $totGiornate = count($tutteLePartite);
    $log[] = "Scaricate $totPartite partite in $totGiornate giornate da Sportradar.";
} catch (Throwable $e) {
    $tutteLePartite = [];
    $log[] = "Fetch Sportradar fallito: " . $e->getMessage();
}

// 2. Import
foreach ($tutteLePartite as $giornata => $partite) {
    try {
        Importer::importaGiornata((int)$giornata, $partite);
    } catch (Throwable $e) {
        $log[] = "Errore import giornata $giornata: " . $e->getMessage();
    }
}
if (!empty($tutteLePartite)) {
    $log[] = "Import completato.";
}

// 3. Elabora giornate completate non ancora processate
$daElaborare = Importer::giornateCompletateNonElaborate();
foreach ($daElaborare as $g) {
    $g = (int)$g;
    try {
        TopSixService::elaboraGiornata($g);
        BetService::calcolaEsiti($g);
        $log[] = "Giornata $g elaborata (TopSix + esiti).";
    } catch (Throwable $e) {
        $log[] = "Errore elaborazione giornata $g: " . $e->getMessage();
    }
}

if (empty($daElaborare)) {
    $log[] = "Nessuna nuova giornata da elaborare.";
}

json_ok([
    'ok'  => true,
    'log' => $log,
]);
