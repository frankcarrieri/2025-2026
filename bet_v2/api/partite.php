<?php
/**
 * GET /api/partite?n={giornata}
 * Ritorna tutte le partite di una giornata con la mappa slot per evidenziare
 * le squadre monitorate.
 *
 * slot_map: { id_squadra => id_slot } — se la giornata è già stata elaborata usa
 * slot_storico (stato storico corretto), altrimenti i slot correnti.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
if ($n < 1 || $n > MAX_GIORNATA) {
    json_err("Giornata non valida: $n");
}

// Tutte le partite della giornata ordinate per data/ora
$partite = Database::query("
    SELECT p.id_partita, p.data_ora, p.gol_casa, p.gol_trasferta, p.stato, p.minuto,
           sc.nome AS nome_casa,   sc.id AS id_casa,   sc.logo_url AS logo_casa,
           st.nome AS nome_trasferta, st.id AS id_trasferta, st.logo_url AS logo_trasferta
    FROM partite p
    JOIN squadre sc ON sc.id = p.id_casa
    JOIN squadre st ON st.id = p.id_trasferta
    WHERE p.id_giornata = ?
    ORDER BY p.data_ora, p.id_partita
", [$n]);

// Slot per questa giornata: usa slot_storico se disponibile (giornata elaborata),
// altrimenti i slot correnti (giornata futura/pending)
$slotRows = Database::query("
    SELECT id_slot, id_squadra FROM slot_storico WHERE id_giornata = ?
", [$n]);

if (empty($slotRows)) {
    $slotRows = Database::query("
        SELECT id_slot, id_squadra FROM slot WHERE id_squadra IS NOT NULL
    ");
}

// Mappa id_squadra → id_slot
$slotMap = [];
foreach ($slotRows as $s) {
    $slotMap[(int)$s['id_squadra']] = (int)$s['id_slot'];
}

$stato = Database::scalar("SELECT stato FROM giornate WHERE id = ?", [$n]) ?? 'pending';

json_ok([
    'giornata' => $n,
    'stato'    => $stato,
    'partite'  => $partite,
    'slot_map' => $slotMap,
]);
