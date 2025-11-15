<?php

namespace App\Util;

class CachePG {
    private $pdo;

    function __construct($dbname, $user, $password, $address, $port) {
      $this->connect($dbname, $user, $password, $address, $port);
    }

    public function connect($dbname, $user, $password, $address, $port) {
        $dsn = "pgsql:host={$address};port={$port};dbname={$dbname};sslmode=prefer";
        $this->pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false, // Protection injection SQL
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // Création de la table 'cache' si elle n'existe pas
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS cache (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                expire_at TIMESTAMP NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_expire_at ON cache(expire_at);
        ");
        $this->cleanup();
    }

    public function set($key, $value, $exp = 600) {
        $expireAt = (new \DateTime())->modify("+{$exp} seconds")->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            INSERT INTO cache (key, value, expire_at) 
            VALUES (:key, :value, :expire_at)
            ON CONFLICT (key) DO UPDATE
            SET value = EXCLUDED.value, expire_at = EXCLUDED.expire_at
        ");
        $stmt->execute([
            ':key' => $key,
            ':value' => serialize($value),
            ':expire_at' => $expireAt
        ]);
    }

    public function get($key) {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            SELECT value FROM cache 
            WHERE key = :key AND expire_at > :now
        ");
        $stmt->execute([':key' => $key, ':now' => $now]);
        $result = $stmt->fetchColumn();
        return $result !== false ? unserialize($result) : null;
    }

    public function add($key, $value, $exp = 600) {
        $this->set($key, $value, $exp);
    }

    public function expire($key, $exp) {
        $expireAt = (new \DateTime())->modify("+{$exp} seconds")->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("
            UPDATE cache SET expire_at = :expire_at WHERE key = :key
        ");
        $stmt->execute([
            ':expire_at' => $expireAt,
            ':key' => $key
        ]);
    }
    
    public function incr($key, $value = 1) {
        $existing = $this->get($key);
        if ($existing === null) {
            $this->set($key, $value);
        } else {
            $this->set($key, (int)$existing + $value);
        }
    }

    public function delete($key) {
        $stmt = $this->pdo->prepare("DELETE FROM cache WHERE key = :key");
        $stmt->execute([':key' => $key]);
    }

    // Méthode dump() supprimée pour sécurité (exposition de données sensibles)

    public function cleanup() {
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare("DELETE FROM cache WHERE expire_at <= :now");
        $stmt->execute([':now' => $now]);
    }
    
    public function ttl($key) {
        $stmt = $this->pdo->prepare("SELECT expire_at FROM cache WHERE key = :key");
        $stmt->execute([':key' => $key]);
        $expireAt = $stmt->fetchColumn();
        if (!$expireAt) return null;

        $expire = new \DateTime($expireAt);
        $now = new \DateTime();
        $diff = $expire->getTimestamp() - $now->getTimestamp();
        return $diff > 0 ? $diff : null;
    }
}
