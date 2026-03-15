<?php
declare(strict_types=1);

/**
 * Singleton PDO wrapper.
 * Una sola connessione per tutta l'esecuzione — nessun reconnect per ogni query.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO('sqlite:' . DB_PATH);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec("PRAGMA foreign_keys = ON");
            self::$pdo->exec("PRAGMA journal_mode  = WAL");
            self::$pdo->exec("PRAGMA busy_timeout  = 5000");
        }
        return self::$pdo;
    }

    /** Esegue una SELECT e restituisce tutte le righe. */
    public static function query(string $sql, array $params = []): array
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Esegue una INSERT/UPDATE/DELETE senza restituire risultati. */
    public static function execute(string $sql, array $params = []): void
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
    }

    /** Restituisce il valore della prima colonna della prima riga, o null. */
    /** @return mixed */
    public static function scalar(string $sql, array $params = [])
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        return $row !== false ? $row[0] : null;
    }

    public static function begin(): void
    {
        self::get()->beginTransaction();
    }

    public static function commit(): void
    {
        self::get()->commit();
    }

    public static function rollback(): void
    {
        if (self::get()->inTransaction()) {
            self::get()->rollBack();
        }
    }

    /** Scrive una riga nel log applicazione (non lancia eccezioni). */
    public static function log(string $livello, string $modulo, string $messaggio, array $contesto = []): void
    {
        try {
            $ctx = $contesto ? json_encode($contesto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            self::execute(
                "INSERT INTO log(livello, modulo, messaggio, contesto) VALUES (?, ?, ?, ?)",
                [strtoupper($livello), $modulo, $messaggio, $ctx]
            );
        } catch (Throwable $e) {
            // il log non deve mai far crashare l'applicazione
        }
    }
}
