<?php
declare(strict_types=1);

/**
 * Importa (o aggiorna) i dati di una giornata nel DB.
 * Tutte le operazioni sono idempotenti: rieseguire non causa duplicati né errori.
 */
class Importer
{
    /**
     * Importa le partite di una giornata.
     * Usa ON CONFLICT per aggiornare gol/stato se già presenti.
     *
     * @param int   $giornata  Numero giornata (1..38)
     * @param array $partite   Array da Scraper::fetchAll()[$giornata]
     */
    public static function importaGiornata(int $giornata, array $partite): void
    {
        if (empty($partite)) {
            return;
        }

        Database::begin();
        try {
            // Crea riga giornata se non esiste
            Database::execute(
                "INSERT OR IGNORE INTO giornate(id, stato) VALUES (?, 'pending')",
                [$giornata]
            );

            foreach ($partite as $m) {
                // Upsert squadre (safe: UNIQUE su nome)
                Database::execute("INSERT OR IGNORE INTO squadre(nome) VALUES (?)", [$m['home']]);
                Database::execute("INSERT OR IGNORE INTO squadre(nome) VALUES (?)", [$m['away']]);

                $casaId = (int)Database::scalar("SELECT id FROM squadre WHERE nome = ?", [$m['home']]);
                $trasId = (int)Database::scalar("SELECT id FROM squadre WHERE nome = ?", [$m['away']]);

                if ($casaId === 0 || $trasId === 0) {
                    Database::log('WARN', 'Importer', "Squadra non trovata per partita {$m['id']}", $m);
                    continue;
                }

                // Upsert partita.
                // ON CONFLICT(id_partita) gestisce il PK.
                // Il secondo UNIQUE(id_giornata, id_casa, id_trasferta) viene gestito con
                // INSERT OR IGNORE + UPDATE separato, evitando errori su match duplicati
                // (es. partite rinviate/rigiocate con nuovo ID nel feed Sportradar).
                Database::execute("
                    INSERT OR IGNORE INTO partite(id_partita, id_giornata, id_casa, id_trasferta, data_ora, gol_casa, gol_trasferta, stato)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $m['id'], $giornata, $casaId, $trasId,
                    $m['data_ora'], $m['gol_casa'], $m['gol_trasferta'], $m['stato'],
                ]);

                // Aggiorna gol/stato sull'eventuale riga già presente (sia per PK che per UNIQUE secondario)
                Database::execute("
                    UPDATE partite
                    SET gol_casa = ?, gol_trasferta = ?, data_ora = ?, stato = ?
                    WHERE (id_partita = ? OR (id_giornata = ? AND id_casa = ? AND id_trasferta = ?))
                      AND stato != 'finished'
                ", [
                    $m['gol_casa'], $m['gol_trasferta'], $m['data_ora'], $m['stato'],
                    $m['id'], $giornata, $casaId, $trasId,
                ]);
            }

            // Aggiorna stato giornata: 'completata' solo se TUTTE le partite sono 'finished'
            $totale    = (int)Database::scalar("SELECT COUNT(*) FROM partite WHERE id_giornata = ?", [$giornata]);
            $finite    = (int)Database::scalar("SELECT COUNT(*) FROM partite WHERE id_giornata = ? AND stato = 'finished'", [$giornata]);
            $nuovoStato = ($totale > 0 && $totale === $finite) ? 'completata' : 'pending';

            Database::execute(
                "UPDATE giornate SET stato = ?, importata_alle = datetime('now') WHERE id = ?",
                [$nuovoStato, $giornata]
            );

            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Restituisce le giornate completate (tutte le partite 'finished')
     * che non hanno ancora lo slot_storico salvato (= non ancora elaborate).
     *
     * @return int[]
     */
    public static function giornateCompletateNonElaborate(): array
    {
        $rows = Database::query("
            SELECT g.id
            FROM giornate g
            WHERE g.stato = 'completata'
              AND NOT EXISTS (
                  SELECT 1 FROM slot_storico ss WHERE ss.id_giornata = g.id
              )
            ORDER BY g.id ASC
        ");
        return array_column($rows, 'id');
    }

    /**
     * Restituisce la prossima giornata 'pending' (non ancora completata).
     * Se non esiste nel DB, restituisce il numero successivo all'ultima completata.
     */
    public static function prossimaGiornata(): int
    {
        $pending = Database::scalar("SELECT MIN(id) FROM giornate WHERE stato = 'pending'");
        if ($pending !== null) {
            return (int)$pending;
        }

        $ultima = (int)(Database::scalar("SELECT MAX(id) FROM giornate") ?? 0);
        return min($ultima + 1, MAX_GIORNATA);
    }
}
