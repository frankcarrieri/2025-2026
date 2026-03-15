<?php
declare(strict_types=1);

/**
 * Gestisce la classifica pareggi e i 6 slot.
 *
 * Logica swap (fissa rispetto al vecchio codice):
 *  - Entrambe le squadre (insider e outsider) devono aver PAREGGIATO in questa giornata
 *  - L'outsider deve avere PIÙ pareggi totali dell'insider
 *  - Se più swap sono possibili: si prende il miglior outsider vs il peggior insider
 */
class TopSixService
{
    /**
     * Punto d'ingresso principale: chiama in ordine corretto.
     * Da invocare dopo che Importer ha caricato la giornata con stato = 'completata'.
     */
    public static function elaboraGiornata(int $giornata): void
    {
        self::aggiornaPareggiStats($giornata);
        self::seedSlotsSeVuoti($giornata);
        self::eseguiSwap($giornata);
        self::salvaSlotStorico($giornata);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAREGGI STATS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ricalcola lo snapshot cumulativo pareggi per $giornata.
     * Conta le partite 'finished' con gol_casa = gol_trasferta dalla g.1 alla g.$giornata.
     * Idempotente: cancella e riscrive la riga.
     */
    private static function aggiornaPareggiStats(int $giornata): void
    {
        Database::begin();
        try {
            Database::execute("DELETE FROM pareggi_stats WHERE id_giornata = ?", [$giornata]);

            Database::execute("
                INSERT INTO pareggi_stats (id_giornata, id_squadra, totale)
                SELECT
                    :g            AS id_giornata,
                    sq.id         AS id_squadra,
                    COALESCE(SUM(
                        CASE
                            WHEN p.gol_casa IS NOT NULL
                             AND p.gol_casa = p.gol_trasferta
                             AND p.stato    = 'finished'
                            THEN 1 ELSE 0
                        END
                    ), 0) AS totale
                FROM squadre sq
                LEFT JOIN partite p
                    ON (p.id_casa = sq.id OR p.id_trasferta = sq.id)
                    AND p.id_giornata <= :g
                GROUP BY sq.id
            ", [':g' => $giornata]);

            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEED SLOT (solo prima volta)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Se tutti i 6 slot sono ancora vuoti, popola con le top-6 per numero di pareggi.
     */
    private static function seedSlotsSeVuoti(int $giornata): void
    {
        $vuoti = (int)Database::scalar("SELECT COUNT(*) FROM slot WHERE id_squadra IS NULL");
        if ($vuoti < NUM_SLOT) {
            return; // slot già popolati (anche parzialmente)
        }

        $top = Database::query("
            SELECT ps.id_squadra
            FROM pareggi_stats ps
            JOIN squadre s ON s.id = ps.id_squadra
            WHERE ps.id_giornata = ?
            ORDER BY ps.totale DESC, s.nome ASC
            LIMIT " . NUM_SLOT,
            [$giornata]
        );

        if (empty($top)) {
            return;
        }

        Database::begin();
        try {
            for ($i = 0; $i < count($top); $i++) {
                Database::execute(
                    "UPDATE slot SET id_squadra = ? WHERE id_slot = ? AND id_squadra IS NULL",
                    [(int)$top[$i]['id_squadra'], $i + 1]
                );
            }
            Database::commit();
            Database::log('INFO', 'TopSix', "Seed iniziale slot in giornata $giornata");
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SWAP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Per ogni pareggio di questa giornata:
     * - Se una squadra è outsider (non in slot) e l'altra è insider (in slot):
     *   verifica se l'outsider ha più pareggi totali → swap.
     *
     * Fix rispetto al vecchio codice:
     * - Non usa fk_giornata_id (che veniva aggiornato anche per non-pareggi)
     * - La condizione "entrambi hanno pareggiato" è verificata direttamente su partite
     * - Nessuna variabile di iterazione riusata
     */
    private static function eseguiSwap(int $giornata): void
    {
        // Tutte le partite finite in pareggio in questa giornata
        $pareggiGiornata = Database::query("
            SELECT id_casa, id_trasferta
            FROM partite
            WHERE id_giornata = ?
              AND stato = 'finished'
              AND gol_casa IS NOT NULL
              AND gol_casa = gol_trasferta
        ", [$giornata]);

        // Raccoglie le squadre che hanno pareggiato questa giornata
        $squadreChePareggiano = [];
        foreach ($pareggiGiornata as $p) {
            $squadreChePareggiano[(int)$p['id_casa']]     = true;
            $squadreChePareggiano[(int)$p['id_trasferta']] = true;
        }

        if (empty($squadreChePareggiano)) {
            return;
        }

        // Slot correnti (insider): id_squadra → id_slot
        $slotsCorrente = Database::query("SELECT id_slot, id_squadra FROM slot WHERE id_squadra IS NOT NULL");
        $insiderMap    = []; // id_squadra → id_slot
        foreach ($slotsCorrente as $s) {
            $insiderMap[(int)$s['id_squadra']] = (int)$s['id_slot'];
        }

        // Candidati outsider: hanno pareggiato ma non sono in nessuno slot
        $outsiders = [];
        foreach (array_keys($squadreChePareggiano) as $squadraId) {
            if (!isset($insiderMap[$squadraId])) {
                $totale = (int)Database::scalar(
                    "SELECT totale FROM pareggi_stats WHERE id_giornata = ? AND id_squadra = ?",
                    [$giornata, $squadraId]
                );
                $outsiders[] = ['id_squadra' => $squadraId, 'totale' => $totale];
            }
        }

        if (empty($outsiders)) {
            return;
        }

        // Ordina outsiders: più pareggi prima (candidato più forte ha priorità)
        usort($outsiders, fn($a, $b) => $b['totale'] <=> $a['totale']);

        // Insider che hanno pareggiato questa giornata, ordinati: meno pareggi prima
        $insidersPareggiantiQuery = [];
        foreach (array_keys($insiderMap) as $squadraId) {
            if (isset($squadreChePareggiano[$squadraId])) {
                $totale = (int)Database::scalar(
                    "SELECT totale FROM pareggi_stats WHERE id_giornata = ? AND id_squadra = ?",
                    [$giornata, $squadraId]
                );
                $insidersPareggiantiQuery[] = [
                    'id_squadra' => $squadraId,
                    'id_slot'    => $insiderMap[$squadraId],
                    'totale'     => $totale,
                ];
            }
        }

        if (empty($insidersPareggiantiQuery)) {
            return; // nessun insider ha pareggiato questa giornata → nessuno swap possibile
        }

        usort($insidersPareggiantiQuery, fn($a, $b) => $a['totale'] <=> $b['totale']);

        // Swap: ogni outsider (più forte) tenta di rimpiazzare il peggior insider
        $insiderDisponibili = $insidersPareggiantiQuery; // copia lavorabile

        foreach ($outsiders as $out) {
            foreach ($insiderDisponibili as $idx => $in) {
                if ($out['totale'] <= $in['totale']) {
                    continue; // outsider non più forte: prova il prossimo insider
                }

                // SWAP: outsider prende il posto dell'insider
                Database::begin();
                try {
                    Database::execute(
                        "UPDATE slot SET id_squadra = ? WHERE id_slot = ?",
                        [(int)$out['id_squadra'], (int)$in['id_slot']]
                    );
                    Database::commit();
                } catch (Throwable $e) {
                    Database::rollback();
                    throw $e;
                }

                Database::log('INFO', 'TopSix', "SWAP g$giornata", [
                    'slot'            => $in['id_slot'],
                    'outsider_entra'  => $out['id_squadra'],
                    'outsider_pareggi'=> $out['totale'],
                    'insider_esce'    => $in['id_squadra'],
                    'insider_pareggi' => $in['totale'],
                ]);

                // Questo insider non è più disponibile per altri swap
                unset($insiderDisponibili[$idx]);
                $insiderDisponibili = array_values($insiderDisponibili);
                break; // questo outsider ha già fatto il suo swap
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SLOT STORICO
    // ─────────────────────────────────────────────────────────────────────────

    /** Salva snapshot degli slot per la giornata (idempotente). */
    private static function salvaSlotStorico(int $giornata): void
    {
        Database::begin();
        try {
            Database::execute("DELETE FROM slot_storico WHERE id_giornata = ?", [$giornata]);
            Database::execute(
                "INSERT INTO slot_storico(id_giornata, id_slot, id_squadra)
                 SELECT ?, id_slot, id_squadra FROM slot",
                [$giornata]
            );
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LETTURA
    // ─────────────────────────────────────────────────────────────────────────

    /** Restituisce i 6 slot correnti con nome squadra. */
    public static function getSlots(): array
    {
        return Database::query("
            SELECT sl.id_slot,
                   sl.id_squadra,
                   sl.posta_corrente,
                   sl.step,
                   sl.saldo,
                   sq.nome     AS nome_squadra,
                   sq.logo_url AS logo_url
            FROM slot sl
            LEFT JOIN squadre sq ON sq.id = sl.id_squadra
            ORDER BY sl.id_slot
        ");
    }
}
