<?php
/**
 * GET /api/storico
 * Ritorna il P&L per giornata e il saldo cumulativo.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

// ── Dettaglio per singolo slot ──────────────────────────────────
$slotFilter = isset($_GET['slot']) ? (int)$_GET['slot'] : null;

if ($slotFilter !== null && $slotFilter >= 1 && $slotFilter <= 6) {
    $rows = Database::query("
        SELECT
            b.id_giornata,
            b.giocata,
            b.quota_x,
            b.posta,
            b.esito,
            COALESCE(b.saldo_variazione, 0) AS saldo_variazione,
            sc.nome    AS nome_casa,
            st.nome    AS nome_trasferta,
            p.gol_casa,
            p.gol_trasferta,
            sq_slot.nome AS nome_squadra_slot
        FROM bet b
        LEFT JOIN partite p       ON b.id_partita  = p.id_partita
        LEFT JOIN squadre sc      ON p.id_casa      = sc.id
        LEFT JOIN squadre st      ON p.id_trasferta = st.id
        LEFT JOIN slot_storico ss ON ss.id_giornata = b.id_giornata AND ss.id_slot = b.id_slot
        LEFT JOIN squadre sq_slot ON sq_slot.id     = ss.id_squadra
        WHERE b.id_slot = ?
        ORDER BY b.id_giornata ASC
    ", [$slotFilter]);

    $cumul        = 0.0;
    $detail       = [];
    $prevSquadra  = null;
    foreach ($rows as $r) {
        $sv          = (float)$r['saldo_variazione'];
        $cumul      += $sv;
        $nomeSlot    = $r['nome_squadra_slot'];
        $cambio      = ($prevSquadra !== null && $nomeSlot !== null && $nomeSlot !== $prevSquadra);
        if ($nomeSlot !== null) $prevSquadra = $nomeSlot;

        $detail[] = [
            'giornata'         => (int)$r['id_giornata'],
            'giocata'          => (int)$r['giocata'],
            'quota_x'          => $r['quota_x'] !== null ? round((float)$r['quota_x'], 2) : null,
            'posta'            => round((float)$r['posta'], 2),
            'esito'            => $r['esito'],
            'saldo_variazione' => round($sv, 2),
            'saldo_cumulativo' => round($cumul, 2),
            'nome_casa'        => $r['nome_casa'],
            'nome_trasferta'   => $r['nome_trasferta'],
            'gol_casa'         => $r['gol_casa'] !== null ? (int)$r['gol_casa'] : null,
            'gol_trasferta'    => $r['gol_trasferta'] !== null ? (int)$r['gol_trasferta'] : null,
            'nome_squadra'     => $nomeSlot,
            'cambio_squadra'   => $cambio,
        ];
    }
    json_ok(['detail' => $detail]);
    exit;
}

// ── Storico globale ─────────────────────────────────────────────
$rows = Database::query("
    SELECT
        b.id_giornata,
        ROUND(SUM(COALESCE(b.saldo_variazione, 0)), 2)                          AS saldo_giornata,
        COUNT(CASE WHEN b.esito = 'WIN'  THEN 1 END)                            AS win,
        COUNT(CASE WHEN b.esito = 'LOSS' THEN 1 END)                            AS loss,
        COUNT(CASE WHEN b.esito = 'SKIP' THEN 1 END)                            AS skip,
        ROUND(SUM(CASE WHEN b.esito = 'WIN'  THEN COALESCE(b.saldo_variazione, 0) ELSE 0 END), 2) AS win_totale,
        ROUND(SUM(CASE WHEN b.esito = 'LOSS' THEN COALESCE(b.saldo_variazione, 0) ELSE 0 END), 2) AS loss_totale
    FROM bet b
    GROUP BY b.id_giornata
    ORDER BY b.id_giornata ASC
");

// Calcola saldo cumulativo in PHP (SQLite < 3.25 non ha window functions)
$cumul = 0.0;
$storico = [];
foreach ($rows as $r) {
    $cumul += (float)$r['saldo_giornata'];
    $storico[] = [
        'giornata'         => (int)$r['id_giornata'],
        'saldo_giornata'   => round((float)$r['saldo_giornata'], 2),
        'saldo_cumulativo' => round($cumul, 2),
        'win'              => (int)$r['win'],
        'loss'             => (int)$r['loss'],
        'skip'             => (int)$r['skip'],
        'win_totale'       => round((float)$r['win_totale'], 2),
        'loss_totale'      => round((float)$r['loss_totale'], 2),
    ];
}

json_ok(['storico' => $storico]);
