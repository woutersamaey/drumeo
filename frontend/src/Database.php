<?php

declare(strict_types=1);

namespace Drumeo\App;

use PDO;
use PDOException;

final class Database
{
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
        for ($i = 0; $i < 15; $i++) {
            try {
                $this->pdo = new PDO($dsn, $this->config->dbUser, $this->config->dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return $this->pdo;
            } catch (PDOException $e) {
                $last = $e;
                sleep(1);
            }
        }
        throw $last ?? new PDOException('database unavailable');
    }
}
