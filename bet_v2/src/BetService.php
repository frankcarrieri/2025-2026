<?php
declare(strict_types=1);

/**
 * Gestisce il ciclo di vita delle bet e il calcolo martingala.
 *
 * Martingala per slot:
 *  - WIN  → reset a POSTA_BASE, step = 0
 *  - LOSS → posta *= 2, step++
 *  - SKIP → reset a POSTA_BASE, step = 0 (non si è scommesso: si riparte da base)
 */
class BetService
{
    // ─────────────────────────────────────────────────────────────────────────
    // PREPARAZIONE BET (pre-partita)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea le righe bet per una giornata collegando ogni slot alla sua partita.
     * Idempotente: usa INSERT OR IGNORE, non sovrascrive bet già confermate.
     */
    public static function preparaBet(int $giornata): void
    {
        $slots = Database::query("SELECT id_slot, id_squadra, posta_corrente FROM slot WHERE id_squadra IS NOT NULL");

        if (empty($slots)) {
            return;
        }

        // Assicura che la giornata esista in tabella
        Database::execute("INSERT OR IGNORE INTO giornate(id, stato) VALUES (?, 'pending')", [$giornata]);

        Database::begin();
        try {
            foreach ($slots as $slot) {
                $idSlot    = (int)$slot['id_slot'];
                $squadraId = (int)$slot['id_squadra'];
                $posta     = (float)$slot['posta_corrente'];

                // Trova la partita di questa squadra nella giornata
                $partitaRow = Database::query("
                    SELECT id_partita FROM partite
                    WHERE id_giornata = ? AND (id_casa = ? OR id_trasferta = ?)
                    LIMIT 1
                ", [$giornata, $squadraId, $squadraId]);

                $idPartita = $partitaRow[0]['id_partita'] ?? null;

                // Inserisce solo se non esiste ancora (non sovrascrive bet già confermate)
                Database::execute("
                    INSERT OR IGNORE INTO bet(id_giornata, id_slot, id_partita, giocata, posta)
                    VALUES (?, ?, ?, 0, ?)
                ", [$giornata, $idSlot, $idPartita, $posta]);

                // Se la bet esiste ma non è ancora confermata (giocata=0), aggiorna id_partita e posta
                Database::execute("
                    UPDATE bet
                    SET id_partita = ?, posta = ?
                    WHERE id_giornata = ? AND id_slot = ? AND giocata = 0 AND esito IS NULL
                ", [$idPartita, $posta, $giornata, $idSlot]);

                // Fix id_partita se NULL (bet creata prima che sync importasse le partite)
                if ($idPartita !== null) {
                    Database::execute("
                        UPDATE bet SET id_partita = ?
                        WHERE id_giornata = ? AND id_slot = ? AND id_partita IS NULL
                    ", [$idPartita, $giornata, $idSlot]);
                }
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    /**
     * Registra la scelta dell'utente per una singola bet (giocata = true).
     * Solo per slot dove si intende scommettere.
     *
     * @param float $quotaX  quota decimale
     */
    public static function registraBet(int $giornata, int $idSlot, bool $giocata, ?float $quotaX = null): void
    {
        Database::execute("
            UPDATE bet
            SET giocata = ?, quota_x = ?
            WHERE id_giornata = ? AND id_slot = ?
        ", [$giocata ? 1 : 0, $quotaX, $giornata, $idSlot]);
    }

    /**
     * Marca immediatamente una bet come SKIP e resetta il relativo slot.
     *
     * Usare nella fase "prossima giornata" quando l'utente decide di non giocare:
     * calcolaEsiti non verrà chiamato prima del completamento della giornata,
     * quindi il reset del slot deve avvenire subito.
     * Distingue "utente ha detto N" (esito='SKIP') da "non ancora risposto" (esito IS NULL).
     */
    public static function registraBetSkip(int $giornata, int $idSlot): void
    {
        Database::begin();
        try {
            Database::execute("
                UPDATE bet
                SET giocata = 0, esito = 'SKIP', vincita = 0, saldo_variazione = 0
                WHERE id_giornata = ? AND id_slot = ?
            ", [$giornata, $idSlot]);

            Database::execute("
                UPDATE slot SET step = 0, posta_corrente = ?
                WHERE id_slot = ?
            ", [POSTA_BASE, $idSlot]);

            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CALCOLO ESITI (post-partita)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Calcola esito e aggiorna slot per tutte le bet di una giornata senza esito.
     * Da chiamare DOPO che Importer ha aggiornato i risultati.
     *
     * Fix rispetto al vecchio codice:
     *  - Nessuna doppia variabile $spese + $spese (usa * 2 esplicitamente)
     *  - SKIP = non si è scommesso → non è una perdita, si riparte da base
     *  - Tutto in una transazione atomica
     */
    public static function calcolaEsiti(int $giornata): void
    {
        $bets = Database::query("
            SELECT b.id_giornata, b.id_slot, b.id_partita, b.giocata, b.quota_x, b.posta,
                   sl.posta_corrente, sl.step, sl.saldo
            FROM bet b
            JOIN slot sl ON sl.id_slot = b.id_slot
            WHERE b.id_giornata = ? AND b.esito IS NULL
        ", [$giornata]);

        if (empty($bets)) {
            return;
        }

        Database::begin();
        try {
            foreach ($bets as $bet) {
                $idSlot    = (int)$bet['id_slot'];
                $giocata   = (bool)$bet['giocata'];
                $posta     = (float)$bet['posta'];
                $quotaX    = $bet['quota_x'] !== null ? (float)$bet['quota_x'] : null;
                $idPartita = $bet['id_partita'];

                if (!$giocata) {
                    // SKIP: non si è scommesso → nessuna perdita, reset martingala
                    Database::execute("
                        UPDATE bet SET esito = 'SKIP', vincita = 0, saldo_variazione = 0
                        WHERE id_giornata = ? AND id_slot = ?
                    ", [$giornata, $idSlot]);

                    Database::execute("
                        UPDATE slot SET step = 0, posta_corrente = ?
                        WHERE id_slot = ?
                    ", [POSTA_BASE, $idSlot]);

                    continue;
                }

                // Verifica lo stato della partita
                $pareggio  = false;
                $rinviata  = false;
                if ($idPartita !== null) {
                    $partita = Database::query("
                        SELECT gol_casa, gol_trasferta, stato FROM partite
                        WHERE id_partita = ?
                    ", [$idPartita]);

                    if (!empty($partita)) {
                        $statoPartita = $partita[0]['stato'];
                        if ($statoPartita === 'postponed') {
                            // Partita rinviata: la bet viene annullata come SKIP
                            // (nessuna perdita, martingala si azzera)
                            $rinviata = true;
                        } elseif ($statoPartita === 'finished' && $partita[0]['gol_casa'] !== null) {
                            $pareggio = ((int)$partita[0]['gol_casa'] === (int)$partita[0]['gol_trasferta']);
                        }
                    }
                }

                if ($rinviata) {
                    Database::execute("
                        UPDATE bet SET esito = 'SKIP', vincita = 0, saldo_variazione = 0
                        WHERE id_giornata = ? AND id_slot = ?
                    ", [$giornata, $idSlot]);

                    Database::execute("
                        UPDATE slot SET step = 0, posta_corrente = ?
                        WHERE id_slot = ?
                    ", [POSTA_BASE, $idSlot]);

                    continue;
                }

                if ($pareggio && $quotaX !== null) {
                    // WIN
                    $vincita         = round($posta * $quotaX, 2);
                    $saldoVariazione = round($vincita - $posta, 2);

                    Database::execute("
                        UPDATE bet SET esito = 'WIN', vincita = ?, saldo_variazione = ?
                        WHERE id_giornata = ? AND id_slot = ?
                    ", [$vincita, $saldoVariazione, $giornata, $idSlot]);

                    Database::execute("
                        UPDATE slot SET step = 0, posta_corrente = ?, saldo = saldo + ?
                        WHERE id_slot = ?
                    ", [POSTA_BASE, $saldoVariazione, $idSlot]);
                } else {
                    // LOSS
                    $saldoVariazione = -$posta;
                    $nuovaPosta      = round($posta * 2, 2);

                    Database::execute("
                        UPDATE bet SET esito = 'LOSS', vincita = 0, saldo_variazione = ?
                        WHERE id_giornata = ? AND id_slot = ?
                    ", [$saldoVariazione, $giornata, $idSlot]);

                    Database::execute("
                        UPDATE slot SET step = step + 1, posta_corrente = ?, saldo = saldo + ?
                        WHERE id_slot = ?
                    ", [$nuovaPosta, $saldoVariazione, $idSlot]);
                }
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LETTURA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Restituisce le bet di una giornata con dettagli completi
     * (slot, squadra, partita, risultato).
     */
    public static function getBetGiornata(int $giornata): array
    {
        return Database::query("
            SELECT b.*,
                   sq.nome     AS nome_squadra,
                   sq.logo_url AS logo_url,
                   s1.nome     AS nome_casa,
                   s2.nome     AS nome_trasferta,
                   p.data_ora,
                   p.gol_casa,
                   p.gol_trasferta,
                   p.stato     AS stato_partita
            FROM bet b
            JOIN slot sl       ON sl.id_slot      = b.id_slot
            LEFT JOIN squadre sq ON sq.id          = sl.id_squadra
            LEFT JOIN partite p  ON p.id_partita   = b.id_partita
            LEFT JOIN squadre s1 ON s1.id           = p.id_casa
            LEFT JOIN squadre s2 ON s2.id           = p.id_trasferta
            WHERE b.id_giornata = ?
            ORDER BY b.id_slot
        ", [$giornata]);
    }

    /**
     * Saldo complessivo calcolato live dalla tabella slot.
     * NON viene salvato: è sempre la somma dei saldi slot correnti.
     */
    public static function getSaldoTotale(): float
    {
        return round((float)(Database::scalar("SELECT SUM(saldo) FROM slot") ?? 0.0), 2);
    }
}
