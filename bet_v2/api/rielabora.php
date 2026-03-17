<?php
/**
 * POST /api/rielabora
 * Ricalcola esiti e stato slot per tutte le giornate completate in ordine.
 * Riparte sempre dall'assegnazione iniziale fissa della stagione 2025-2026.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_err('Method not allowed', 405);
}

$log = [];

// 1. Ripristina assegnazione iniziale fissa (stagione 2025-2026)
//    Slot1=Bari(14), Slot2=Catanzaro(15), Slot3=Frosinone(17),
//    Slot4=Mantova(8), Slot5=Modena(20), Slot6=Sampdoria(19)
$slotIniziali = [1 => 14, 2 => 15, 3 => 17, 4 => 8, 5 => 20, 6 => 19];
foreach ($slotIniziali as $idSlot => $idSquadra) {
    Database::execute(
        "UPDATE slot SET id_squadra = ?, posta_corrente = ?, step = 0, saldo = 0.0 WHERE id_slot = ?",
        [$idSquadra, POSTA_BASE, $idSlot]
    );
}
$log[] = "Slot resettati all'assegnazione iniziale (Bari/Catanzaro/Frosinone/Mantova/Modena/Sampdoria).";

// 3. Cancella storico slot e statistiche pareggi
Database::execute("DELETE FROM slot_storico");
Database::execute("DELETE FROM pareggi_stats");
$log[] = "Storico slot e statistiche azzerati.";

// 4. Reset esiti su TUTTE le bet (verranno ricalcolati)
Database::execute("UPDATE bet SET esito = NULL, vincita = NULL, saldo_variazione = NULL");
$log[] = "Esiti azzerati su tutte le bet.";

// 5. Rielabora ogni giornata completata in ordine crescente
$giornate = Database::query(
    "SELECT id FROM giornate WHERE stato = 'completata' ORDER BY id ASC"
);

$elaborate = 0;
foreach ($giornate as $row) {
    $g = (int)$row['id'];
    try {
        // a) Swap slot e salva slot_storico (aggiorna slot table allo stato post-swap di g)
        TopSixService::elaboraGiornata($g);

        // b) Fix id_partita e posta per tutte le bet di questa giornata
        //    usando lo stato slot CORRENTE (dopo lo swap di g)
        $currentSlots = Database::query(
            "SELECT id_slot, id_squadra, posta_corrente FROM slot WHERE id_squadra IS NOT NULL"
        );
        foreach ($currentSlots as $sl) {
            $idSlot     = (int)$sl['id_slot'];
            $idSquadra  = (int)$sl['id_squadra'];
            $posta      = (float)$sl['posta_corrente'];

            $partitaRow = Database::query("
                SELECT id_partita FROM partite
                WHERE id_giornata = ? AND (id_casa = ? OR id_trasferta = ?)
                LIMIT 1
            ", [$g, $idSquadra, $idSquadra]);
            $idPartita = $partitaRow[0]['id_partita'] ?? null;

            // Crea la bet se non esiste
            Database::execute("
                INSERT OR IGNORE INTO bet(id_giornata, id_slot, id_partita, giocata, posta)
                VALUES (?, ?, ?, 0, ?)
            ", [$g, $idSlot, $idPartita, $posta]);

            // Aggiorna id_partita e posta su bet con esito ancora NULL
            Database::execute("
                UPDATE bet SET id_partita = ?, posta = ?
                WHERE id_giornata = ? AND id_slot = ? AND esito IS NULL
            ", [$idPartita, $posta, $g, $idSlot]);
        }

        // c) Calcola esiti WIN/LOSS/SKIP e aggiorna slot per prossima giornata
        BetService::calcolaEsiti($g);
        $elaborate++;
    } catch (Throwable $e) {
        $log[] = "Errore giornata $g: " . $e->getMessage();
    }
}

if ($elaborate > 0) {
    $log[] = "$elaborate giornate rielaborate con successo.";
} else {
    $log[] = "Nessuna giornata completata trovata. Inserisci prima le quote.";
}

json_ok(['ok' => true, 'log' => $log]);
