<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Opens a read-only connection to ZKBioTime's database. Only SELECT statements are ever sent; on
 * PostgreSQL the connection itself is read only (default_transaction_read_only) as a second guard.
 */
final class DbConnector
{
    /** @var \Closure(string, string, string, array<int, mixed>): \PDO */
    private \Closure $pdo;

    /** @var \Closure(string): bool */
    private \Closure $loaded;

    /**
     * @param (\Closure(string, string, string, array<int, mixed>): \PDO)|null $pdo    Injected for tests
     * @param (\Closure(string): bool)|null                                   $loaded Injected for tests
     */
    public function __construct(?\Closure $pdo = null, ?\Closure $loaded = null)
    {
        $this->pdo    = $pdo ?? static fn (string $dsn, string $user, string $password, array $options): \PDO => new \PDO($dsn, $user, $password, $options);
        $this->loaded = $loaded ?? static fn (string $extension): bool => \extension_loaded($extension);
    }

    /**
     * @throws DbException
     */
    public function connect(DbConfig $config): Query
    {
        if (!($this->loaded)($config->extension())) {
            throw new DbException(DbException::DRIVER_MISSING, 'PHP extension missing', $config->extension());
        }

        $start = microtime(true);

        try {
            $pdo = ($this->pdo)($config->dsn(), $config->user, $config->password(), $config->pdoOptions());
        } catch (\PDOException $e) {
            throw DbException::fromDriver($e, 'connect');
        } finally {
            // For the page's log line (1.0.2): connecting includes the TLS handshake.
            Query::$connectSeconds += microtime(true) - $start;
        }

        return new Query($pdo, $config->engine);
    }
}
