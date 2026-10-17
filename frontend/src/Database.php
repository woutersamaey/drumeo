<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;
use PDOException;

/** Lazy PDO connection: nothing connects until a caller needs the database. */
final class Database
{
    private const RETRIES = 2;
    private const CONNECT_TIMEOUT_SEC = 2;
    private const RETRY_DELAY_USEC = 300_000;

    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $this->config->dbHost,
            $this->config->dbPort,
            $this->config->dbName,
        );
        $last = null;
        for ($attempt = 0; $attempt <= self::RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(self::RETRY_DELAY_USEC);
            }
            try {
                $this->pdo = new PDO($dsn, $this->config->dbUser, $this->config->dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SEC,
                ]);
                return $this->pdo;
            } catch (PDOException $e) {
                $last = $e;
            }
        }
        throw $last ?? new PDOException('database unavailable');
    }
}
