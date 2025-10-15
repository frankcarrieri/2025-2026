<?php
//PARTE 1
$saldo_partenza = 0;

for ($top_team = 1; $top_team <= 6; $top_team++){
    $giornata_partenza = selectQuery("SELECT MIN(p.giornata_id) as id_giornata from partite_2025_26 p  where p.casa_gol is NULL;");
    $giornata_partenza = $giornata_partenza[0]["id_giornata"];
    
    for ($giornata_partenza; $giornata_partenza <= 38; $giornata_partenza++) {
        $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                    SET fk_id_partita=(
                                        SELECT p.id
                                        from partite_2025_26 p
                                        JOIN top_draw_teams tdt1 ON tdt1.squadra_id = p.casa_id
                                        JOIN top_draw_teams tdt2 ON tdt2.squadra_id = p.trasferta_id 
                                        where giornata_id = $giornata_partenza
                                        AND (tdt1.id_draw = $top_team OR tdt2.id_draw = $top_team)
                                    )
                                    WHERE id=(SELECT id FROM bet_2025_2026 WHERE fk_id_giornata = $giornata_partenza AND fk_id_draw = $top_team);");
    }
  
}

$giornata_partenza = selectQuery("SELECT MIN(p.giornata_id) as id_giornata from partite_2025_26 p  where p.casa_gol is NULL;");
$giornata_partenza = $giornata_partenza[0]["id_giornata"];

//GIORNATA DA GIOCARE
$bet_row = selectQuery("
    SELECT s1.nome_squadra || ' - ' || s2.nome_squadra AS name_match,
           s1.nome_squadra as squadra_casa,
           s2.nome_squadra as squadra_tras,
           p.data_ora, 
           b.fk_id_draw AS id_draw,
           b.spese_giornata
    FROM bet_2025_2026 AS b
    JOIN partite_2025_26 p ON p.id = b.fk_id_partita 
    JOIN squadre_2025_26 s1 ON s1.id = p.casa_id 
    JOIN squadre_2025_26 s2 ON s2.id = p.trasferta_id
    WHERE b.fk_id_giornata = $giornata_partenza
      AND b.giocato = 'NO'
");
if (!empty($bet_row)){
    $p = 1;
    foreach ($bet_row as $row){
        echo "**************** SQUADRA $p ****************\n";
        echo "Partita: ".$row["name_match"]."\n";
        echo "Data: ".$row["data_ora"]."\n";
        echo "Devi giocare: ".$row["spese_giornata"]."€\n";
        $giocato = "";

        while (empty($giocato)) {
            $giocato = strtoupper(trim(readline("Vuoi giocare questa partita? [S/N]: \t")));
            if ($giocato === "S" || $giocato === "N") {
                if ($giocato === "S"){
                    $quota = "";

                    while (empty($quota)) {
                        $quota = trim(readline("Inserisci la quota decimale: \t"));

                        // Controllo che sia un numero valido (intero o decimale) e maggiore di 0
                        if (is_numeric($quota) && floatval($quota) > 0) {
                            echo "Hai inserito la quota: $quota\n";

                            $aggiorno_quota = updateQuery("UPDATE bet_2025_2026
                                SET giocato='SI', quota=$quota
                                WHERE fk_id_giornata=$giornata_partenza 
                                AND fk_id_draw=".$row["id_draw"].";"
                            );

                            //AGGIORNO_BET_IN_BACKGROUND
                            for ($giornata_partenza; $giornata_partenza <= 38; $giornata_partenza++){
                                $idPartita = selectQuery("
                                SELECT p.id
                                from partite_2025_26 p
                                join squadre_2025_26 s1 ON s1.id = p.casa_id 
                                JOIN squadre_2025_26 s2 ON s2.id = p.trasferta_id
                                JOIN top_draw_teams tdt1 ON tdt1.squadra_id = s1.id
                                JOIN top_draw_teams tdt2 ON tdt2.squadra_id = s2.id 
                                where giornata_id = $giornata_partenza
                                AND (tdt1.id_draw in (".$row["id_draw"].") OR tdt2.id_draw in (".$row["id_draw"]."))
                                ");
                                $idPartita = $idPartita[0]["id"];

                                $bet_row = selectQuery("SELECT b.* FROM  bet_2025_2026 b WHERE b.fk_id_giornata = $giornata_partenza AND b.fk_id_draw = ".$row["id_draw"]);
                                $bet_row_prec = selectQuery("SELECT * FROM  bet_2025_2026 b WHERE b.fk_id_giornata = ".($bet_row[0]["fk_id_giornata"] -1)." and b.fk_id_draw = ".$row["id_draw"]);
                                
                                if ($giornata_partenza == 1){
                                    $saldo_squadra_pareggi = -5;
                                    $capitale_investito = -5;
                                    $spese_giornata = 5;
                                }
                                else {
                                    if ($bet_row_prec[0]["vincita"] == null){
                                        $capitale_investito_prec = $bet_row_prec[0]["capitale_investito"];
                                        $spese_giornata = $bet_row_prec[0]["spese_giornata"]+$bet_row_prec[0]["spese_giornata"];
                                        $capitale_investito = $capitale_investito_prec-$spese_giornata;
                                    }
                                    else {
                                        $capitale_investito = -5;
                                        $spese_giornata = 5;
                                    }
                                    $saldo_squadra_pareggi_prec = $bet_row_prec[0]["saldo_squadra_pareggi"];
                                    $saldo_squadra_pareggi = $saldo_squadra_pareggi_prec-$spese_giornata;
                                    }

                                $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                    SET fk_id_partita=$idPartita,saldo_squadra_pareggi=$saldo_squadra_pareggi, capitale_investito=$capitale_investito, spese_giornata=$spese_giornata
                                    WHERE id=".$bet_row[0]["id"].";"
                                );
                            }
                            $giornata_partenza = selectQuery("SELECT MIN(p.giornata_id) as id_giornata from partite_2025_26 p  where p.casa_gol is NULL;");
                            $giornata_partenza = $giornata_partenza[0]["id_giornata"];

                            $p++;
                        } else {
                            echo "\n************ATTENZIONE************\n";
                            echo "Valore non valido! Devi inserire una quota numerica > 0 (es. 1.50).\nRiprova...\n";
                            $quota = ""; // reset per ripetere il ciclo
                        }
                    }
                }
                else{
                    $p++;
                    continue;
                }
            } else {
                echo "\n************ATTENZIONE************\n";
                echo "Valore non valido! Devi inserire solo 'S' o 'N'.\nRiprova...\n";
                $giocato = ""; // reset per ripetere il ciclo
            }
        }
    }
}
else{
    echo "Sono state giocate tutte le partite per la giornata $giornata_partenza.\n";
}

//PARTE 2
$giornata_fine = $giornata_partenza +1;

for ($giornata_partenza; $giornata_partenza <= $giornata_fine; $giornata_partenza++) {
    // URL della pagina da scaricare
    $url = "https://www.legab.it/seriebkt/calendario/2025-2026/stagione-regolare/".$giornata_partenza ;
    echo "Sto lavorando su " . $url . PHP_EOL;

    $ch = curl_init();
    // Imposta l'URL di destinazione
    curl_setopt($ch, CURLOPT_URL, $url);
    // Restituisce il risultato come stringa
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Timeout totale (in secondi)
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    // Timeout per la connessione (in secondi)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    // User-Agent aggiornato (Chrome 2025)
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36");
    // Segue eventuali redirect
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    // Gestione SSL (non sicuro disattivare, ma utile in test)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

//     Indirizzo e porta del proxy
//     curl_setopt($ch, CURLOPT_PROXY, "http://proxy-coll.dt.tesoro.it:8080"); // IP:porta

// Tipo di proxy (HTTP, HTTPS, SOCKS4, SOCKS5)
//     curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP); // o CURLPROXY_SOCKS5

    // Esegui la richiesta
    $html = curl_exec($ch);

    // Gestione errori
    if (curl_errno($ch)) {
        echo "Errore cURL: " . curl_error($ch);
        die;
    }

    curl_close($ch);

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $giornate = $xpath->query("//div[contains(@class, 'giornata-title')]");

    // Trova tutte le partite
    $partite = $xpath->query("//div[contains(@class,'versus')]");

    foreach ($partite as $partita) {
        // Squadra casa
        $casaImg = $xpath->query(".//div[contains(@class,'club')][1]//img", $partita)->item(0);
        $squadraCasa = $casaImg ? $casaImg->getAttribute("title") : "";

        // Squadra trasferta
        $trasfertaImg = $xpath->query(".//div[contains(@class,'club')][2]//img", $partita)->item(0);
        $squadraTrasferta = $trasfertaImg ? $trasfertaImg->getAttribute("title") : "";

        // Gol
        $golTags = $xpath->query(".//span[@class='gol']", $partita);
        $golCasa = $golTags->item(0) ? trim($golTags->item(0)->nodeValue) : "";
        $golTrasferta = $golTags->item(1) ? trim($golTags->item(1)->nodeValue) : "";

        $dataOraNode = $xpath->query(".//span[contains(@class, 'giornata-title-hour')]");
        if ($dataOraNode->length > 0){
            $dataOra = trim($dataOraNode->item(0)->textContent);
            $dataOra = preg_replace('/\s+/', ' ', $dataOra); // Rimuove spazi multipli
            // Estrai la data nel formato gg/mm/aaaa
            $dateParts = explode(" ", $dataOra);
            $ora = $dateParts[1];
        }
        else {
            $ora = "15:00";
        }
    
        $dataMeseNode = $xpath->query(".//span[contains(@class, 'giornata-title-day')]");
        $dataMese = trim($dataMeseNode->item(0)->textContent);
        $dataMese = preg_replace('/\s+/', ' ', $dataMese); // Rimuove spazi multipli
        // Estrai la data nel formato gg/mm/aaaa
        $dateMeseParts = explode(" ", $dataMese);

        // Mappa dei mesi in italiano a numeri
        $mese = [
            'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4,
            'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8,
            'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12
        ];
        // Ottieni il numero del mese
        $numeroMese = $mese[strtolower($dateMeseParts[2])];
        $date = $dateMeseParts[1]."/".$numeroMese; // 18 agosto
        $anno = ($numeroMese >= 8 && $numeroMese <= 12) ? 2025 : 2026;
        // Aggiungi l'anno scelto alla data
        $dataCompleta = $date . '/' . $anno.' '.$ora;

        // Crea un oggetto DateTime utilizzando un formato personalizzato
        $data = DateTime::createFromFormat('d/n/Y H:i', $dataCompleta);
        $dateFormatted = $data ? $data->format('d/m/Y H:i') : 'Data non valida';

        $squadraCasaId = selectQuery("SELECT id FROM squadre_2025_26 WHERE nome_squadra = '$squadraCasa';");
        $squadraCasaId = $squadraCasaId[0]["id"];
        $squadraTrasfertaId = selectQuery("SELECT id FROM squadre_2025_26 WHERE nome_squadra = '$squadraTrasferta';");
        $squadraTrasfertaId = $squadraTrasfertaId[0]["id"];
        $idPartita = selectQuery("SELECT id FROM partite_2025_26 WHERE giornata_id = $giornata_partenza AND (casa_id = $squadraCasaId OR trasferta_id = $squadraTrasfertaId);");
        $idPartita = $idPartita[0]["id"];

        if ($golCasa === "-"){
            continue;
        }
        else{
            if ($golCasa == $golTrasferta){
                //AGGIORNO_PARTITA
                $aggiorno_partita = updateQuery("
                    UPDATE partite_2025_26
                    SET data_ora='$dateFormatted', casa_gol=$golCasa, trasferta_gol=$golTrasferta
                    WHERE id = $idPartita;
                ");
                //AGGIORNO_DRAW
                $aggiorno_draw_teams = updateQuery("
                    UPDATE top_draw_teams
                    SET numero_pareggi = numero_pareggi + 1, fk_giornata_id = $giornata_partenza 
                    WHERE squadra_id IN ($squadraCasaId, $squadraTrasfertaId);
                ");

                //AGGIORNO_BET
                //controllo se è nella top six    
                $idDraw = selectQuery("SELECT tdt.squadra_id, tdt.id_draw from top_draw_teams tdt where id_draw in (1,2,3,4,5,6)");
                $arr_top_team = [];
                foreach ($idDraw as $row){
                    $arr_top_team[$row["id_draw"]] = $row["squadra_id"];
                }

                if (in_array($squadraCasaId, $arr_top_team) || in_array($squadraTrasfertaId, $arr_top_team)) {
                    $arr_draw_match =[];
                    $arr_draw_match[] = array_search($squadraCasaId, $arr_top_team);
                    $arr_draw_match[] = array_search($squadraTrasfertaId, $arr_top_team);

                    foreach ($arr_draw_match as $id_draw){
                        if ($id_draw !== false){
                            $bet_row = selectQuery("SELECT b.* FROM  bet_2025_2026 b WHERE b.fk_id_giornata = $giornata_partenza AND b.fk_id_draw = ".$id_draw);
                            if ($bet_row[0]["giocato"] == "SI"){
                                if ($giornata_partenza == 1){
                                    $vincita = $bet_row[0]["spese_giornata"]*$bet_row[0]["quota"];
                                    $saldo_squadra_pareggi = $vincita-$bet_row[0]["spese_giornata"];

                                    $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                        SET vincita=$vincita, saldo_squadra_pareggi=$saldo_squadra_pareggi
                                        WHERE id=".$bet_row[0]["id"].";"
                                    );
                                }
                                else {
                                    $bet_row_prec = selectQuery("SELECT * FROM  bet_2025_2026 b WHERE b.fk_id_giornata = ".($giornata_partenza -1)." and b.fk_id_draw = ".$bet_row[0]["fk_id_draw"]);
                                    if ($bet_row_prec[0]["vincita"] == null){
                                        $capitale_investito_prec = $bet_row_prec[0]["capitale_investito"];
                                        $spese_giornata = $bet_row_prec[0]["spese_giornata"]+$bet_row_prec[0]["spese_giornata"];
                                        $capitale_investito = $capitale_investito_prec-$spese_giornata;
                                        $vincita = $spese_giornata*$bet_row[0]["quota"];
                                    }
                                    else {
                                        $capitale_investito = -5;
                                        $spese_giornata = 5;
                                        $vincita = $spese_giornata*$bet_row[0]["quota"];
                                    }
                                    $saldo_squadra_pareggi_prec = $bet_row_prec[0]["saldo_squadra_pareggi"];
                                    $saldo_squadra_pareggi = $saldo_squadra_pareggi_prec+($vincita-$spese_giornata);

                                    $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                        SET fk_id_partita=$idPartita,vincita=$vincita, saldo_squadra_pareggi=$saldo_squadra_pareggi, capitale_investito=$capitale_investito, spese_giornata=$spese_giornata
                                        WHERE id=".$bet_row[0]["id"].";"
                                    );
                                    $aggiorno_bet_next = updateQuery("UPDATE bet_2025_2026 
                                        SET spese_giornata=5
                                        WHERE id=(SELECT b.id FROM  bet_2025_2026 b WHERE b.fk_id_giornata = $giornata_partenza+1 AND b.fk_id_draw = $id_draw)
                                        "
                                    );
                                }
                            }
                        }
                    }
                }
            }
            else {
                //AGGIORNO_PARTITA
                $aggiorno_partita = updateQuery("
                    UPDATE partite_2025_26
                    SET data_ora='$dateFormatted', casa_gol=$golCasa, trasferta_gol=$golTrasferta
                    WHERE id = $idPartita;
                ");
                //AGGIORNO_DRAW
                $aggiorno_draw_teams = updateQuery("
                    UPDATE top_draw_teams
                    SET fk_giornata_id = $giornata_partenza 
                    WHERE squadra_id IN ($squadraCasaId, $squadraTrasfertaId);
                ");
                //AGGIORNO_BET
                //controllo se è nella top six    
                $idDraw = selectQuery("SELECT tdt.squadra_id, tdt.id_draw from top_draw_teams tdt where id_draw in (1,2,3,4,5,6)");
                $arr_top_team = [];
                foreach ($idDraw as $row){
                    $arr_top_team[$row["id_draw"]] = $row["squadra_id"];
                }
                if (in_array($squadraCasaId, $arr_top_team) || in_array($squadraTrasfertaId, $arr_top_team)) {
                    $arr_draw_match =[];
                    $arr_draw_match[] = array_search($squadraCasaId, $arr_top_team);
                    $arr_draw_match[] = array_search($squadraTrasfertaId, $arr_top_team);

                    foreach ($arr_draw_match as $id_draw){
                        if ($id_draw !== false){
                            $bet_row = selectQuery("SELECT b.* FROM  bet_2025_2026 b WHERE b.fk_id_giornata = $giornata_partenza AND b.fk_id_draw = ".$id_draw);
                            if ($bet_row[0]["giocato"] == "SI"){
                                if ($giornata_partenza == 1){
                                    $saldo_squadra_pareggi = -5;

                                    $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                        SET vincita=null, saldo_squadra_pareggi=$saldo_squadra_pareggi
                                        WHERE id=".$bet_row[0]["id"].";"
                                    );
                                }
                                else {
                                    $bet_row_prec = selectQuery("SELECT * FROM  bet_2025_2026 b WHERE b.fk_id_giornata = ".($giornata_partenza -1)." and b.fk_id_draw = ".$bet_row[0]["fk_id_draw"]);
                                    if ($bet_row_prec[0]["vincita"] == null){
                                        $capitale_investito_prec = $bet_row_prec[0]["capitale_investito"];
                                        $spese_giornata = $bet_row_prec[0]["spese_giornata"]+$bet_row_prec[0]["spese_giornata"];
                                        $capitale_investito = $capitale_investito_prec-$spese_giornata;
                                    }
                                    else {
                                        $capitale_investito = -5;
                                        $spese_giornata = 5;
                                    }
                                    $saldo_squadra_pareggi_prec = $bet_row_prec[0]["saldo_squadra_pareggi"];
                                    $saldo_squadra_pareggi = $saldo_squadra_pareggi_prec-$spese_giornata;

                                    $aggiorno_bet = updateQuery("UPDATE bet_2025_2026 
                                        SET fk_id_partita=$idPartita,saldo_squadra_pareggi=$saldo_squadra_pareggi, capitale_investito=$capitale_investito, spese_giornata=$spese_giornata
                                        WHERE id=".$bet_row[0]["id"].";"
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    $aggiorno_saldo_squadra_pareggi = updateQuery("UPDATE bet_2025_2026 
        SET saldo_totale = (SELECT SUM(saldo_squadra_pareggi) FROM bet_2025_2026 where fk_id_giornata = $giornata_partenza )
        WHERE fk_id_giornata = $giornata_partenza ;"
    );
    aggiornaTopSix($giornata_partenza );
}



function getPDO() {
    //$db_path = "C:\\Users\\francesco1.carrieri\\OneDrive - Dipartimento\\FRANK\\serie_b.sqlite";
    $db_path = "C:\\Users\\Francesco Carrieri\\Documents\\CODING\\FRANK\\serie_b.sqlite";
    try {
        $pdo = new PDO("sqlite:" . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        echo "Errore connessione DB: " . $e->getMessage();
        return false;
    }
}

function selectQuery($query, $params = []) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        echo "Errore SELECT: " . $e->getMessage();
        return false;
    }
}

function insertQuery($query, $params = []) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        echo "Errore INSERT: " . $e->getMessage();
        return false;
    }
}

function updateQuery($query, $params = []) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare($query);
        return $stmt->execute($params);
    } catch (PDOException $e) {
        echo "Errore UPDATE: " . $e->getMessage();
        return false;
    }
}

function aggiornaTopSix($giornata) {
    // Squadre che hanno pareggiato proprio in questa giornata
    $teams = selectQuery("
        SELECT squadra_id, numero_pareggi, in_top_six, id_draw
        FROM top_draw_teams
        WHERE fk_giornata_id = ?
    ", [$giornata]);

    if (empty($teams)) {
        echo "Nessuna squadra ha pareggiato in giornata $giornata\n";
        return;
    }

    // Divido insider e outsider
    $insiders = array_filter($teams, fn($t) => $t['in_top_six'] == 1);
    $outsiders = array_filter($teams, fn($t) => $t['in_top_six'] == 0);

    if (empty($insiders) || empty($outsiders)) {
        echo "Nessuna coppia insider/outside ha pareggiato insieme in giornata $giornata\n";
        return;
    }

    // Ordino outsider (più pareggi prima) e insider (meno pareggi prima)
    usort($outsiders, fn($a, $b) => $b['numero_pareggi'] <=> $a['numero_pareggi']);
    usort($insiders, fn($a, $b) => $a['numero_pareggi'] <=> $b['numero_pareggi']);

    foreach ($outsiders as $out) {
        foreach ($insiders as $idx => $in) {
            $in_select = selectQuery("SELECT * FROM partite_2025_26 WHERE giornata_id = $giornata AND (casa_id = ".$in["squadra_id"]." OR trasferta_id = ".$in["squadra_id"].");");
            $ingolCasa = $in_select[0]["casa_gol"];
            $ingolTras = $in_select[0]["trasferta_gol"];
            $out_select = selectQuery("SELECT * FROM partite_2025_26 WHERE giornata_id = $giornata AND (casa_id = ".$out["squadra_id"]." OR trasferta_id = ".$out["squadra_id"].");");
            $outgolCasa = $out_select[0]["casa_gol"];
            $outgolTras = $out_select[0]["trasferta_gol"];

            if ($ingolCasa == $ingolTras && $outgolCasa == $outgolTras){
                if ($out['numero_pareggi'] > $in['numero_pareggi']) {
                    // swap
                    updateQuery("UPDATE top_draw_teams SET in_top_six = 0, id_draw = NULL WHERE squadra_id = ?", [$in['squadra_id']]);
                    updateQuery("UPDATE top_draw_teams SET in_top_six = 1, id_draw = ?, fk_giornata_id = ? WHERE squadra_id = ?", [
                        $in['id_draw'], $giornata, $out['squadra_id']
                    ]);

                    echo "SWAP giornata $giornata: outsider {$out['squadra_id']} (pareggi={$out['numero_pareggi']}) ".
                        "entra al posto di insider {$in['squadra_id']} (pareggi={$in['numero_pareggi']})\n";

                    // aggiorno la lista in memoria
                    $insiders[$idx] = [
                        'squadra_id' => $out['squadra_id'],
                        'numero_pareggi' => $out['numero_pareggi'],
                        'id_draw' => $in['id_draw']
                    ];
                    // riordino insiders
                    usort($insiders, fn($a, $b) => $a['numero_pareggi'] <=> $b['numero_pareggi']);
                    break; // un outsider può sostituire un insider al massimo una volta
                }
            }
        }
    }
}

?>