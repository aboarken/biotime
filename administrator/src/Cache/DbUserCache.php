<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Cache;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Per-user cache in the component's own table #__biotime_cache, so it survives logging out and is
 * not loaded with the Joomla session on every page of the site. Keys always contain the Joomla
 * user id and the employee code, so a user never reads another user's entry and a changed mapping
 * never shows the previous employee's data. Every entry has an expiry time; expired entries are
 * deleted now and then, so the table only holds what was read recently.
 *
 * A database error never breaks a page: the entry is simply read from ZKBioTime again.
 */
final class DbUserCache implements UserCacheInterface
{
    private const TABLE = '#__biotime_cache';

    /** About one write in this many also deletes expired entries. */
    private const PURGE_EVERY = 20;

    /** Larger entries are not cached (a month of punches is a few KB). */
    private const MAX_BYTES = 2_000_000;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param int $ttl seconds; 0 disables the cache
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LoggerInterface $logger,
        private readonly int $ttl = 300,
        ?\Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function get(Identity $identity, string $key): ?array
    {
        if ($this->ttl <= 0 || !$identity->isMatched()) {
            return null;
        }

        $id  = $this->key($identity, $key);
        $now = ($this->clock)();

        try {
            $query = $this->db->createQuery()
                ->select($this->db->quoteName('value'))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('cache_key') . ' = :key')
                ->where($this->db->quoteName('expires') . ' > :now')
                ->bind(':key', $id)
                ->bind(':now', $now, ParameterType::INTEGER);

            $raw = $this->db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            $this->logger->warning('ZKBioTime cache could not be read: ' . $e::class);

            return null;
        }

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $value = json_decode($raw, true);

        return \is_array($value) ? $value : null;
    }

    public function set(Identity $identity, string $key, array $value, ?int $ttl = null): void
    {
        if ($this->ttl <= 0 || !$identity->isMatched()) {
            return;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || \strlen($json) > self::MAX_BYTES) {
            return;
        }

        $id  = $this->key($identity, $key);
        $now = ($this->clock)();

        try {
            $this->delete($this->db->quoteName('cache_key') . ' = :key', [':key' => [$id, ParameterType::STRING]]);
            // insertObject() takes the row by reference: it must be a variable.
            $row = (object) [
                'cache_key' => $id,
                'user_id'   => $identity->joomlaUserId,
                'expires'   => $now + max(1, $ttl ?? $this->ttl),
                'value'     => $json,
            ];
            $this->db->insertObject(self::TABLE, $row);

            if (random_int(1, self::PURGE_EVERY) === 1) {
                $this->purge($now);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ZKBioTime cache could not be written: ' . $e::class);
        }
    }

    /** Delete every expired entry. */
    public function purge(?int $now = null): void
    {
        $this->delete($this->db->quoteName('expires') . ' <= :now', [':now' => [$now ?? ($this->clock)(), ParameterType::INTEGER]]);
    }

    /**
     * @param array<string, array{0: int|string, 1: string}> $binds
     */
    private function delete(string $where, array $binds): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($where);

        foreach ($binds as $name => [$value, $type]) {
            $query->bind($name, $value, $type);
        }

        $this->db->setQuery($query)->execute();
    }

    private function key(Identity $identity, string $key): string
    {
        // At most 191 characters (index length); the parts are short and checked elsewhere.
        return substr($identity->joomlaUserId . '|' . $identity->empCode() . '|' . $key, 0, 191);
    }
}
