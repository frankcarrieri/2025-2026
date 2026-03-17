<?php
/**
 * GET /api/classifica
 * Restituisce tutte le squadre con pareggi totali, partite giocate e slot attuale.
 * Ordinata per pareggi DESC, poi per nome ASC.
 */
require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_err('Method not allowed', 405);
}

$rows = Database::query("
    SELECT
        s.id,
        s.nome,
        s.logo_url,
        SUM(CASE WHEN p.stato = 'finished' THEN 1 ELSE 0 END)                         AS partite_giocate,
        SUM(CASE WHEN p.stato = 'finished' AND p.gol_casa = p.gol_trasferta THEN 1 ELSE 0 END) AS pareggi,
        SUM(CASE WHEN p.stato = 'finished' AND p.gol_casa  > p.gol_trasferta
                  AND p.id_casa = s.id THEN 1
             WHEN p.stato = 'finished' AND p.gol_trasferta > p.gol_casa
                  AND p.id_trasferta = s.id THEN 1 ELSE 0 END)                         AS vittorie,
        SUM(CASE WHEN p.stato = 'finished' AND p.gol_casa  < p.gol_trasferta
                  AND p.id_casa = s.id THEN 1
             WHEN p.stato = 'finished' AND p.gol_trasferta < p.gol_casa
                  AND p.id_trasferta = s.id THEN 1 ELSE 0 END)                         AS sconfitte,
        SUM(CASE WHEN p.stato = 'finished' AND p.id_casa = s.id
                 THEN p.gol_casa
                 WHEN p.stato = 'finished' AND p.id_trasferta = s.id
                 THEN p.gol_trasferta ELSE 0 END)                                      AS gol_fatti,
        SUM(CASE WHEN p.stato = 'finished' AND p.id_casa = s.id
                 THEN p.gol_trasferta
                 WHEN p.stato = 'finished' AND p.id_trasferta = s.id
                 THEN p.gol_casa ELSE 0 END)                                           AS gol_subiti,
        sl.id_slot
    FROM squadre s
    LEFT JOIN partite p ON (p.id_casa = s.id OR p.id_trasferta = s.id)
    LEFT JOIN slot sl ON sl.id_squadra = s.id
    GROUP BY s.id, s.nome, s.logo_url, sl.id_slot
    HAVING partite_giocate > 0
    ORDER BY pareggi DESC, partite_giocate DESC, s.nome ASC
");

// Converti tipi e calcola punti
$classifica = array_map(function($r) {
    $v = (int)$r['vittorie'];
    $n = (int)$r['pareggi'];
    return [
        'id'              => (int)$r['id'],
        'nome'            => $r['nome'],
        'logo_url'        => $r['logo_url'],
        'partite_giocate' => (int)$r['partite_giocate'],
        'vittorie'        => $v,
        'pareggi'         => $n,
        'sconfitte'       => (int)$r['sconfitte'],
        'gol_fatti'       => (int)$r['gol_fatti'],
        'gol_subiti'      => (int)$r['gol_subiti'],
        'punti'           => $v * 3 + $n,
        'id_slot'         => $r['id_slot'] !== null ? (int)$r['id_slot'] : null,
    ];
}, $rows);

// Calcola posizione classifica reale (ordinata per punti, poi DR, poi gol fatti)
$byPoints = $classifica;
usort($byPoints, function($a, $b) {
    $pa = $a['punti']; $pb = $b['punti'];
    if ($pa !== $pb) return $pb - $pa;
    $da = $a['gol_fatti'] - $a['gol_subiti'];
    $db = $b['gol_fatti'] - $b['gol_subiti'];
    if ($da !== $db) return $db - $da;
    return $b['gol_fatti'] - $a['gol_fatti'];
});

// Mappa id → posizione
$posMap = [];
foreach ($byPoints as $pos => $r) {
    $posMap[$r['id']] = $pos + 1;
}

// Aggiungi posizione a ogni riga
foreach ($classifica as &$r) {
    $r['pos_classifica'] = $posMap[$r['id']];
}
unset($r);

json_ok(['classifica' => $classifica]);
