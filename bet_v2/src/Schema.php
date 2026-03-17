<?php
declare(strict_types=1);

/**
 * Crea lo schema DB se non esiste (idempotente).
 *
 * Differenze chiave rispetto al vecchio DB:
 *  - pareggi_stats: snapshot cumulativo per giornata (storico completo, no sovrascrittura)
 *  - slot_storico:  quale squadra era in ogni slot ad ogni giornata (audit)
 *  - bet:           verità su cosa è stato giocato/non giocato per ogni slot/giornata
 *  - saldo non salvato sul DB → calcolato sempre via SUM(saldo_variazione)
 */
class Schema
{
    public static function create(): void
    {
        $pdo = Database::get();

        $pdo->exec("
            -- ── SQUADRE ─────────────────────────────────────────────────────
            CREATE TABLE IF NOT EXISTS squadre (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT    NOT NULL UNIQUE
            );

            -- ── GIORNATE ─────────────────────────────────────────────────────
            CREATE TABLE IF NOT EXISTS giornate (
                id             INTEGER PRIMARY KEY,   -- numero giornata 1..38
                stato          TEXT    NOT NULL DEFAULT 'pending'
                                       CHECK(stato IN ('pending', 'completata')),
                importata_alle TEXT                   -- timestamp ultimo import da API
            );

            -- ── PARTITE ──────────────────────────────────────────────────────
            CREATE TABLE IF NOT EXISTS partite (
                id_partita    TEXT    PRIMARY KEY,    -- ID Sportradar
                id_giornata   INTEGER NOT NULL REFERENCES giornate(id),
                id_casa       INTEGER NOT NULL REFERENCES squadre(id),
                id_trasferta  INTEGER NOT NULL REFERENCES squadre(id),
                data_ora      TEXT,
                gol_casa      INTEGER,
                gol_trasferta INTEGER,
                stato         TEXT    NOT NULL DEFAULT 'scheduled'
                                      CHECK(stato IN ('scheduled', 'live', 'finished')),
                UNIQUE(id_giornata, id_casa, id_trasferta)
            );

            -- ── PAREGGI CUMULATIVI (snapshot storico per giornata) ───────────
            -- Una riga per (giornata, squadra): totale pareggi dalla giornata 1 a quella riga.
            -- Permette ricalcoli e audit senza perdere storia.
            CREATE TABLE IF NOT EXISTS pareggi_stats (
                id_giornata INTEGER NOT NULL REFERENCES giornate(id),
                id_squadra  INTEGER NOT NULL REFERENCES squadre(id),
                totale      INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (id_giornata, id_squadra)
            );

            -- ── SLOT TOP-6 (stato corrente) ───────────────────────────────────
            CREATE TABLE IF NOT EXISTS slot (
                id_slot        INTEGER PRIMARY KEY CHECK(id_slot BETWEEN 1 AND 6),
                id_squadra     INTEGER REFERENCES squadre(id),
                posta_corrente REAL    NOT NULL DEFAULT 5.0,
                step           INTEGER NOT NULL DEFAULT 0,
                saldo          REAL    NOT NULL DEFAULT 0.0
            );

            -- ── SLOT STORICO (per giornata) ───────────────────────────────────
            CREATE TABLE IF NOT EXISTS slot_storico (
                id_giornata INTEGER NOT NULL REFERENCES giornate(id),
                id_slot     INTEGER NOT NULL CHECK(id_slot BETWEEN 1 AND 6),
                id_squadra  INTEGER REFERENCES squadre(id),
                PRIMARY KEY (id_giornata, id_slot)
            );

            -- ── BET (una riga per slot per giornata) ─────────────────────────
            -- giocata=0 → non scommessa (SKIP)
            -- giocata=1 → scommessa: esito calcolato dopo i risultati
            CREATE TABLE IF NOT EXISTS bet (
                id_giornata      INTEGER NOT NULL REFERENCES giornate(id),
                id_slot          INTEGER NOT NULL CHECK(id_slot BETWEEN 1 AND 6),
                id_partita       TEXT    REFERENCES partite(id_partita),
                giocata          INTEGER NOT NULL DEFAULT 0,   -- 0=no, 1=si
                quota_x          REAL,
                posta            REAL    NOT NULL DEFAULT 5.0,
                esito            TEXT    CHECK(esito IN ('WIN', 'LOSS', 'SKIP') OR esito IS NULL),
                vincita          REAL,
                saldo_variazione REAL,
                PRIMARY KEY (id_giornata, id_slot)
            );

            -- ── LOG APPLICAZIONE ──────────────────────────────────────────────
            CREATE TABLE IF NOT EXISTS log (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                ts        TEXT    NOT NULL DEFAULT (datetime('now')),
                livello   TEXT    NOT NULL CHECK(livello IN ('INFO', 'WARN', 'ERROR')),
                modulo    TEXT    NOT NULL,
                messaggio TEXT    NOT NULL,
                contesto  TEXT                -- JSON
            );

            -- ── INDICI ────────────────────────────────────────────────────────
            CREATE INDEX IF NOT EXISTS idx_partite_giornata  ON partite(id_giornata);
            CREATE INDEX IF NOT EXISTS idx_bet_giornata      ON bet(id_giornata);
            CREATE INDEX IF NOT EXISTS idx_pareggi_giornata  ON pareggi_stats(id_giornata);
            CREATE INDEX IF NOT EXISTS idx_slot_storico_g    ON slot_storico(id_giornata);
        ");

        // Migrazione: aggiungi logo_url a squadre (idempotente)
        try {
            $pdo->exec("ALTER TABLE squadre ADD COLUMN logo_url TEXT");
        } catch (Throwable $e) { /* già esistente */ }

        // Migrazione: aggiungi minuto a partite (idempotente)
        try {
            $pdo->exec("ALTER TABLE partite ADD COLUMN minuto INTEGER");
        } catch (Throwable $e) { /* già esistente */ }

        // Migrazione: aggiorna CHECK constraint di partite.stato per includere 'postponed'
        // SQLite non supporta ALTER COLUMN: si ricrea la tabella se il constraint è ancora quello vecchio
        $checkInfo = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='partite'")->fetchColumn();
        if ($checkInfo && strpos($checkInfo, "'postponed'") === false) {
            $pdo->exec("PRAGMA foreign_keys = OFF");
            $pdo->exec("
                BEGIN;
                CREATE TABLE partite_v2 (
                    id_partita    TEXT    PRIMARY KEY,
                    id_giornata   INTEGER NOT NULL REFERENCES giornate(id),
                    id_casa       INTEGER NOT NULL REFERENCES squadre(id),
                    id_trasferta  INTEGER NOT NULL REFERENCES squadre(id),
                    data_ora      TEXT,
                    gol_casa      INTEGER,
                    gol_trasferta INTEGER,
                    stato         TEXT NOT NULL DEFAULT 'scheduled'
                                  CHECK(stato IN ('scheduled','live','finished','postponed')),
                    minuto        INTEGER,
                    UNIQUE(id_giornata, id_casa, id_trasferta)
                );
                INSERT INTO partite_v2
                    SELECT id_partita, id_giornata, id_casa, id_trasferta,
                           data_ora, gol_casa, gol_trasferta, stato,
                           NULL
                    FROM partite;
                DROP TABLE partite;
                ALTER TABLE partite_v2 RENAME TO partite;
                CREATE INDEX IF NOT EXISTS idx_partite_giornata ON partite(id_giornata);
                COMMIT;
            ");
            $pdo->exec("PRAGMA foreign_keys = ON");
        }

        // Inizializza i 6 slot se non esistono ancora
        Database::begin();
        try {
            for ($i = 1; $i <= NUM_SLOT; $i++) {
                Database::execute(
                    "INSERT OR IGNORE INTO slot(id_slot, posta_corrente, step, saldo) VALUES (?, ?, 0, 0.0)",
                    [$i, POSTA_BASE]
                );
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollback();
            throw $e;
        }
    }
}
