<?php
/**
 * GET /api/dashboard
 * Ritorna: slot correnti, saldo totale, giornata corrente.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$slots   = TopSixService::getSlots();
$saldo   = BetService::getSaldoTotale();
$prossima = Importer::prossimaGiornata();

// Ultima giornata elaborata
$ultimaElaborata = (int)Database::scalar(
    "SELECT COALESCE(MAX(id_giornata), 0) FROM slot_storico"
);

json_ok([
    'saldo_totale'      => round($saldo, 2),
    'prossima_giornata' => $prossima,
    'ultima_elaborata'  => $ultimaElaborata,
    'slots'             => $slots,
]);
