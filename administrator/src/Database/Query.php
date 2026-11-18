<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Runs prepared SELECT statements on a database connection. Anything else is refused before it
 * reaches the database.
 */
class Query
{
    /** Queries run and the time they took this request, for the page's log line (1.0.2). */
    public static int $count = 0;

    public static float $seconds = 0.0;

    /** Time spent connecting (with the TLS handshake) this request. */
    public static float $connectSeconds = 0.0;

    public function __construct(private readonly ?\PDO $pdo, public readonly string $engine)
    {
    }

    /**
     * @param array<string, scalar|null> $params
     *
     * @return list<array<string, mixed>>
     *
     * @throws DbException
     */
    public function rows(string $sql, array $params = []): array
    {
        if (!preg_match('/^\s*(SELECT|SHOW|WITH)\b/i', $sql) || preg_match('/;\s*\S/', $sql)) {
            throw new \LogicException('Only single SELECT statements may be run');
        }

        if ($this->pdo === null) {
            throw new \LogicException('No connection');
        }

        $start = microtime(true);

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);

            return array_values($statement->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            throw DbException::fromDriver($e, 'query');
        } finally {
            self::$count++;
            self::$seconds += microtime(true) - $start;
        }
    }

    /**
     * The first value of the first row, or null.
     *
     * @param array<string, scalar|null> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->rows($sql, $params)[0] ?? null;

        return $row === null ? null : (array_values($row)[0] ?? null);
    }
}
