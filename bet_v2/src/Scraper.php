<?php
declare(strict_types=1);

/**
 * Scarica e normalizza i dati da Sportradar.
 * Restituisce un array indicizzato per numero di giornata.
 */
class Scraper
{
    /**
     * Fetch dell'intera stagione da Sportradar.
     *
     * @return array<int, list<array{
     *   id: string,
     *   id_giornata: int,
     *   home: string,
     *   away: string,
     *   data_ora: string,
     *   gol_casa: int|null,
     *   gol_trasferta: int|null,
     *   stato: string
     * }>>
     * Chiavi = numero giornata (1..38), ordinate ascending.
     */
    public static function fetchAll(): array
    {
        $raw  = self::httpGet(SR_URL);
        $json = json_decode($raw, true);

        if (!is_array($json)) {
            throw new RuntimeException("Sportradar: risposta non è JSON valido.");
        }

        // La struttura Sportradar può variare; proviamo i path noti
        $matches = $json['doc'][0]['data']['matches']
            ?? $json['matches']
            ?? null;

        if (!is_array($matches)) {
            throw new RuntimeException("Sportradar: campo 'matches' non trovato nella risposta.");
        }

        $result = [];

        foreach ($matches as $m) {
            // Round: campo intero diretto nella struttura Sportradar
            $round = isset($m['round']) ? (int)$m['round'] : 0;
            if ($round < 1 || $round > MAX_GIORNATA) {
                continue;
            }

            // ID match (chiave dell'array associativo o campo '_id')
            $id   = (string)($m['_id'] ?? $m['id'] ?? '');
            // Teams sotto m['teams']['home/away']['name']
            $home = (string)($m['teams']['home']['name'] ?? '');
            $away = (string)($m['teams']['away']['name'] ?? '');

            if ($id === '' || $home === '' || $away === '') {
                continue;
            }

            // Data/ora: uts (Unix timestamp) è il campo più affidabile
            $dataOra = '';
            if (!empty($m['time']['uts'])) {
                $dataOra = date('d/m/Y H:i', (int)$m['time']['uts']);
            } elseif (!empty($m['time']['date']) && !empty($m['time']['time'])) {
                $dataOra = $m['time']['date'] . ' ' . $m['time']['time'];
            }

            // Stato: determinato dall'esistenza di result (status è null nel JSON)
            $hasResult = !empty($m['result'])
                && isset($m['result']['home'], $m['result']['away']);
            $postponed = !empty($m['postponed']);

            if ($hasResult) {
                $stato = 'finished';
            } elseif ($postponed) {
                $stato = 'scheduled'; // rinviata, non giocata
            } else {
                $stato = 'scheduled';
            }

            // Gol: presenti solo nelle partite finite
            $golCasa = null;
            $golTras = null;
            if ($hasResult) {
                $golCasa = is_numeric($m['result']['home']) ? (int)$m['result']['home'] : null;
                $golTras = is_numeric($m['result']['away']) ? (int)$m['result']['away'] : null;
            }

            $result[$round][] = [
                'id'            => $id,
                'id_giornata'   => $round,
                'home'          => $home,
                'away'          => $away,
                'data_ora'      => $dataOra,
                'gol_casa'      => $golCasa,
                'gol_trasferta' => $golTras,
                'stato'         => $stato,
            ];
        }

        ksort($result);
        return $result;
    }

    private static function normalizeStatus(string $status): string
    {
        if (in_array($status, ['live', 'inprogress', 'in progress', 'halftime'], true)) {
            return 'live';
        }
        if (in_array($status, ['closed', 'finished', 'ended', 'complete', 'aet', 'ap'], true)) {
            return 'finished';
        }
        return 'scheduled';
    }

    private static function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err !== '') {
            throw new RuntimeException("cURL error: $err");
        }
        if ($code !== 200) {
            throw new RuntimeException("Sportradar HTTP $code.");
        }

        return (string)$body;
    }
}
