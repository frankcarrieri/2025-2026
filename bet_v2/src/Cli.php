<?php
declare(strict_types=1);

/**
 * Helper CLI: input/output da terminale.
 * Nessuna logica di business qui — solo presentazione e raccolta input.
 */
class Cli
{
    public static function line(string $text = ''): void
    {
        echo $text . PHP_EOL;
    }

    public static function separator(string $char = '─', int $len = 55): void
    {
        self::line(str_repeat($char, $len));
    }

    public static function header(string $titolo): void
    {
        self::separator('═');
        self::line("  $titolo");
        self::separator('═');
    }

    /** Chiede una stringa (non vuota). */
    public static function ask(string $domanda): string
    {
        $v = readline($domanda);
        return trim((string)$v);
    }

    /** Chiede S o N. Ripete finché non riceve risposta valida. */
    public static function askYN(string $domanda): bool
    {
        while (true) {
            $r = strtoupper(self::ask("$domanda [S/N]: "));
            if ($r === 'S') {
                return true;
            }
            if ($r === 'N') {
                return false;
            }
            self::line("  ⚠ Inserisci S oppure N.");
        }
    }

    /** Chiede un numero decimale maggiore di $min. Ripete finché non riceve un valore valido. */
    public static function askFloat(string $domanda, float $min = 1.0): float
    {
        while (true) {
            $v = self::ask($domanda);
            if (is_numeric($v) && (float)$v > $min) {
                return (float)$v;
            }
            self::line("  ⚠ Valore non valido. Inserisci un numero > $min (es. 3.10).");
        }
    }

    /** Chiede un intero tra $min e $max. */
    public static function askInt(string $domanda, int $min = 1, int $max = MAX_GIORNATA): int
    {
        while (true) {
            $v = self::ask($domanda);
            if (ctype_digit($v) && (int)$v >= $min && (int)$v <= $max) {
                return (int)$v;
            }
            self::line("  ⚠ Inserisci un numero intero tra $min e $max.");
        }
    }

    /** Stampa un riepilogo bet (array da BetService::getBetGiornata). */
    public static function stampaBet(array $bets): void
    {
        foreach ($bets as $b) {
            $esito  = $b['esito'] ?? '—';
            if ($esito === 'WIN')       { $icon = '✓'; }
            elseif ($esito === 'LOSS')  { $icon = '✗'; }
            elseif ($esito === 'SKIP')  { $icon = '○'; }
            else                        { $icon = '?'; }
            $partita = ($b['nome_casa'] ?? '?') . ' - ' . ($b['nome_trasferta'] ?? '?');
            $saldo   = $b['saldo_variazione'] !== null
                ? sprintf('%+.2f€', (float)$b['saldo_variazione'])
                : '';
            $quota   = $b['quota_x'] !== null ? "@{$b['quota_x']}" : '';
            $squad   = $b['nome_squadra'] ?? 'vuoto';

            self::line(sprintf(
                "  %s Slot %d  %-20s | %-40s | %s %s",
                $icon, $b['id_slot'], $squad, $partita, $saldo, $quota
            ));
        }
    }

    /** Stampa i 6 slot con stato martingala. */
    public static function stampaSlots(array $slots): void
    {
        self::separator();
        self::line("  SLOT CORRENTI:");
        self::separator();
        foreach ($slots as $s) {
            $nome = $s['nome_squadra'] ?? '—';
            self::line(sprintf(
                "  Slot %d | %-20s | Posta: %5.2f€ | Step: %d | Saldo slot: %+.2f€",
                $s['id_slot'], $nome, (float)$s['posta_corrente'], (int)$s['step'], (float)$s['saldo']
            ));
        }
        self::separator();
    }
}
