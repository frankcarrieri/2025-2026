<?php
declare(strict_types=1);

/**
 * Entry point principale — da eseguire da CLI.
 *
 * Uso: php main.php
 *
 * Flusso:
 *  [1] Schema DB (idempotente)
 *  [2] Fetch dati da Sportradar per tutta la stagione
 *  [3] Import nel DB di tutte le giornate (idempotente)
 *  [4] Per ogni giornata completata non ancora elaborata:
 *       → chiede retroattivamente se si vuole inserire la bet
 *       → aggiorna top-six (pareggi_stats + swap + slot_storico)
 *       → calcola esiti martingala
 *  [5] Per la prossima giornata non ancora giocata:
 *       → mostra match per slot e chiede quota/giocata
 *  [6] Riepilogo finale
 *
 * Gestione "ritardo":
 *   Se lo script non viene eseguito prima di una giornata,
 *   la fase [4] la intercetta e chiede retroattivamente la bet.
 *   Non si perde mai nessuna giornata, a prescindere da quante se ne saltano.
 */

require __DIR__ . '/config.php';
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Scraper.php';
require __DIR__ . '/src/Importer.php';
require __DIR__ . '/src/TopSixService.php';
require __DIR__ . '/src/BetService.php';
require __DIR__ . '/src/Cli.php';

// ── [1] INIT ──────────────────────────────────────────────────────────────────
Schema::create();

Cli::header("SERIE B DRAW BETTING — " . STAGIONE);

// ── [2] FETCH DA SPORTRADAR ───────────────────────────────────────────────────
Cli::line("\n[1/4] Scarico dati da Sportradar...");

$tutteLePartite = [];
try {
    $tutteLePartite = Scraper::fetchAll();
    $totPartite     = array_sum(array_map('count', $tutteLePartite));
    $totGiornate    = count($tutteLePartite);
    Cli::line("  ✓ $totPartite partite in $totGiornate giornate.");
} catch (Throwable $e) {
    Cli::line("  ✗ Fetch fallito: " . $e->getMessage());
    Cli::line("  Continuo con i dati già presenti nel DB...");
    Database::log('WARN', 'main', 'Fetch Sportradar fallito', ['err' => $e->getMessage()]);
}

// ── [3] IMPORT GIORNATE ───────────────────────────────────────────────────────
Cli::line("\n[2/4] Importo giornate nel DB...");

foreach ($tutteLePartite as $giornata => $partite) {
    try {
        Importer::importaGiornata((int)$giornata, $partite);
    } catch (Throwable $e) {
        Cli::line("  ✗ Errore import giornata $giornata: " . $e->getMessage());
        Database::log('ERROR', 'main', "Import fallito giornata $giornata", ['err' => $e->getMessage()]);
    }
}

Cli::line("  ✓ Import completato.");

// ── [4] ELABORA GIORNATE COMPLETATE NON ANCORA PROCESSATE ────────────────────
Cli::line("\n[3/4] Elaboro giornate completate...");

$giornateNonElaborate = Importer::giornateCompletateNonElaborate();

if (empty($giornateNonElaborate)) {
    Cli::line("  ✓ Nessuna giornata da elaborare.");
} else {
    Cli::line("  Giornate da elaborare: " . implode(', ', $giornateNonElaborate));
}

// Chiedi una volta sola se saltare in blocco tutte le giornate senza bet
$saltaTutte = false;
$giornateConBetMancante = array_filter($giornateNonElaborate, function ($g) {
    $c = (int)Database::scalar("SELECT COUNT(*) FROM bet WHERE id_giornata = ?", [(int)$g]);
    return $c === 0;
});
if (count($giornateConBetMancante) > 1) {
    Cli::line(sprintf(
        "\n  Ci sono %d giornate passate senza bet registrate (%s).",
        count($giornateConBetMancante),
        implode(', ', $giornateConBetMancante)
    ));
    $saltaTutte = Cli::askYN("  Vuoi saltarle TUTTE e marcarle come SKIP?");
}

foreach ($giornateNonElaborate as $g) {
    $g = (int)$g;
    Cli::separator();
    Cli::line("  ► Giornata $g");

    // Verifica se la bet è già stata inserita per questa giornata
    $betGiaPresenti = Database::query(
        "SELECT COUNT(*) AS c FROM bet WHERE id_giornata = ?",
        [$g]
    );
    $nBet = (int)($betGiaPresenti[0]['c'] ?? 0);

    // 1. Aggiorna top-six PRIMA di tutto: seed slot (se vuoti), swap, snapshot.
    //    Deve essere il primo passo perché preparaBet ha bisogno degli slot popolati.
    try {
        TopSixService::elaboraGiornata($g);
    } catch (Throwable $e) {
        Cli::line("  ✗ Errore TopSix giornata $g: " . $e->getMessage());
        Database::log('ERROR', 'TopSix', "Errore elaboraGiornata $g", ['err' => $e->getMessage()]);
    }

    // 2. Gestisci input bet (slot ora popolati)
    if ($nBet === 0) {
        Cli::line("  ⚠ Giornata $g già conclusa senza bet registrate.");

        if ($saltaTutte) {
            $inserisci = false;
        } else {
            $inserisci = Cli::askYN("  Vuoi inserire le bet retroattivamente?");
        }

        if ($inserisci) {
            BetService::preparaBet($g);
            $bets = BetService::getBetGiornata($g);

            foreach ($bets as $bet) {
                $partita = ($bet['nome_casa'] ?? '?') . ' - ' . ($bet['nome_trasferta'] ?? '?');
                Cli::line("\n  Slot {$bet['id_slot']} — {$bet['nome_squadra']}");
                Cli::line("  Partita: $partita");

                if ($bet['gol_casa'] !== null) {
                    Cli::line("  Risultato: {$bet['gol_casa']} - {$bet['gol_trasferta']}");
                }

                Cli::line(sprintf("  Posta: %.2f€", (float)$bet['posta']));

                $giocata = Cli::askYN("  Hai giocato questa partita?");
                $quotaX  = null;
                if ($giocata) {
                    $quotaX = Cli::askFloat("  Quota X: ", 1.0);
                }

                BetService::registraBet($g, (int)$bet['id_slot'], $giocata, $quotaX);
            }
        } else {
            // Segna tutto come SKIP per non bloccare l'elaborazione delle giornate successive
            BetService::preparaBet($g);
            Cli::line("  → Giornata saltata: tutte le bet marcate come SKIP.");
        }
    }

    // 3. Calcola esiti martingala
    try {
        BetService::calcolaEsiti($g);
    } catch (Throwable $e) {
        Cli::line("  ✗ Errore calcolo esiti giornata $g: " . $e->getMessage());
        Database::log('ERROR', 'BetService', "Errore calcolaEsiti $g", ['err' => $e->getMessage()]);
    }

    // Stampa risultati giornata
    $betsFinali = BetService::getBetGiornata($g);
    Cli::stampaBet($betsFinali);
    Cli::line(sprintf("  Saldo dopo giornata $g: %+.2f€", BetService::getSaldoTotale()));
}

// ── [5] BET PROSSIMA GIORNATA ─────────────────────────────────────────────────
Cli::line("\n[4/4] Bet prossima giornata...");

$prossima = Importer::prossimaGiornata();

if ($prossima > MAX_GIORNATA) {
    Cli::line("  Stagione completata!");
} else {
    Cli::line("  Giornata: $prossima");

    // Prepara righe bet (idempotente: INSERT OR IGNORE)
    BetService::preparaBet($prossima);
    $bets = BetService::getBetGiornata($prossima);

    if (empty($bets)) {
        Cli::line("  Nessuno slot disponibile per la prossima giornata.");
        Cli::line("  → Eseguire prima init.php oppure attendere i dati da Sportradar.");
    } else {
        // Bet ancora in attesa di risposta: giocata=0 e esito IS NULL
        // (esito='SKIP' = utente ha già risposto N; giocata=1 = utente ha già risposto S)
        $betsInAttesa = array_values(array_filter($bets, function ($b) {
            return !(bool)$b['giocata'] && $b['esito'] === null;
        }));

        if (empty($betsInAttesa)) {
            Cli::line("  ✓ Bet già inserite per questa giornata.");
            Cli::stampaBet($bets);
        } else {
            Cli::separator();
            foreach ($betsInAttesa as $bet) {
                $partita = ($bet['nome_casa'] ?? '?') . ' - ' . ($bet['nome_trasferta'] ?? '?');

                Cli::line(sprintf("\n  ★ Slot %d — %s", $bet['id_slot'], $bet['nome_squadra'] ?? '—'));
                Cli::line("    Partita:  $partita");
                Cli::line("    Data:     " . ($bet['data_ora'] ?? '—'));
                Cli::line(sprintf("    Posta:    %.2f€", (float)$bet['posta']));

                $giocata = Cli::askYN("    Giochi?");
                if ($giocata) {
                    $quotaX = Cli::askFloat("    Quota X: ", 1.0);
                    BetService::registraBet($prossima, (int)$bet['id_slot'], true, $quotaX);
                } else {
                    // Segna subito SKIP e resetta slot: calcolaEsiti non verrà
                    // chiamato finché la giornata non è completata
                    BetService::registraBetSkip($prossima, (int)$bet['id_slot']);
                }
            }

            Cli::line("\n  ✓ Bet registrate per giornata $prossima.");
        }
    }
}

// ── [6] RIEPILOGO FINALE ──────────────────────────────────────────────────────
$slots = TopSixService::getSlots();
Cli::stampaSlots($slots);
Cli::line(sprintf("  SALDO TOTALE: %+.2f€", BetService::getSaldoTotale()));
Cli::separator('═');
