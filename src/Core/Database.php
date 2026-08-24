<?php

namespace Keel\Core;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function resetConnection(): void
    {
        self::$instance = null;
    }

    public static function connection(): PDO
    {
        if (self::$instance === null) {
            $host = Env::get('DB_HOST', '127.0.0.1');
            $port = Env::get('DB_PORT', '3306');
            $name = Env::get('DB_DATABASE');
            $user = Env::get('DB_USERNAME');
            $pass = Env::get('DB_PASSWORD');
            $charset = Env::get('DB_CHARSET', 'utf8mb4');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                error_log('[Keel] DB connection failed: ' . $e->getMessage());
                throw new PDOException('Database connection failed.');
            }
        }

        return self::$instance;
    }

    /**
     * Run a unit of work in a transaction, safely nested.
     *
     * PDO has no nested transactions: calling beginTransaction() while one is
     * already open throws. That makes any method which opens its own
     * transaction impossible to call from inside another one — so a model
     * method that is perfectly correct alone blows up the moment a caller
     * batches several of them.
     *
     * This helper opens a transaction only at the outermost level and lets
     * inner calls join it. The outermost caller commits or rolls back, which
     * is the behaviour every call site here actually wants: all of it, or
     * none of it.
     */
    public static function transaction(callable $work): mixed
    {
        $connection = self::connection();
        $isOutermost = !$connection->inTransaction();

        if ($isOutermost) {
            $connection->beginTransaction();
        }

        try {
            $result = $work($connection);

            if ($isOutermost) {
                $connection->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            // Only the caller that opened the transaction may close it. An
            // inner failure propagates and the outer level rolls the lot back.
            if ($isOutermost && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
