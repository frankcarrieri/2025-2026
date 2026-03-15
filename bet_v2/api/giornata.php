<?php
/**
 * GET /api/giornata/{n}
 * Ritorna le bet della giornata n (con partite e stato slot).
 * Se le bet non esistono ancora le prepara (idempotente).
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
if ($n < 1 || $n > MAX_GIORNATA) {
    json_err("Giornata non valida: $n");
}

// Prepara righe bet (idempotente)
BetService::preparaBet($n);
$bets = BetService::getBetGiornata($n);

// Stato giornata
$statoGiornata = Database::scalar(
    "SELECT stato FROM giornate WHERE id = ?",
    [$n]
) ?? 'pending';

json_ok([
    'giornata' => $n,
    'stato'    => $statoGiornata,
    'bets'     => $bets,
]);
