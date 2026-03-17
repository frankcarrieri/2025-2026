<?php
/**
 * POST /api/bet
 * Body JSON: { "giornata": int, "id_slot": int, "giocata": bool, "quota_x": float|null }
 * Registra la bet per uno slot di una giornata.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$body = input();

$giornata = isset($body['giornata']) ? (int)$body['giornata'] : 0;
$idSlot   = isset($body['id_slot'])  ? (int)$body['id_slot']  : 0;
$giocata  = isset($body['giocata'])  ? (bool)$body['giocata'] : false;
$quotaX   = isset($body['quota_x'])  ? (float)$body['quota_x'] : null;

if ($giornata < 1 || $giornata > MAX_GIORNATA) {
    json_err("Giornata non valida: $giornata");
}
if ($idSlot < 1 || $idSlot > NUM_SLOT) {
    json_err("Slot non valido: $idSlot");
}

if ($giocata && ($quotaX === null || $quotaX <= 1.0)) {
    json_err("quota_x deve essere > 1.0 quando giocata=true");
}

// Salva solo la preferenza (giocata + quota_x).
// L'esito (WIN/LOSS/SKIP) viene impostato da calcolaEsiti dopo le partite.
BetService::registraBet($giornata, $idSlot, $giocata, $giocata ? $quotaX : null);

json_ok(['ok' => true, 'giornata' => $giornata, 'id_slot' => $idSlot]);
