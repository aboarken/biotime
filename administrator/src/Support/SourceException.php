<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Support;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\DbException;

/**
 * ZKBioTime could not answer what a page or an administrator task needed (0.11.0; ZkbException
 * before). The "kind" is stable and selects the user-facing language string
 * COM_BIOTIME_ERROR_<KIND>, shown on portal pages and in User Mappings; the technical reason is
 * for the log only and is never shown to employees.
 */
final class SourceException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const NETWORK        = 'network';
    public const AUTH           = 'auth';
    public const NOT_FOUND      = 'not_found';
    public const SERVER         = 'server';
    public const BAD_RESPONSE   = 'bad_response';

    public const KINDS = [self::NOT_CONFIGURED, self::NETWORK, self::AUTH, self::NOT_FOUND, self::SERVER, self::BAD_RESPONSE];

    public function __construct(private readonly string $kind, string $message, ?\Throwable $previous = null)
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown error kind');
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * The kind shown for a failed read of ZKBioTime's database: not set up, the user or password
     * refused, the database or a query failing (server), anything else (unreachable, SSL, missing
     * driver) as not reachable.
     */
    public static function kindOf(DbException $e): string
    {
        return match ($e->getKind()) {
            DbException::NOT_CONFIGURED                => self::NOT_CONFIGURED,
            DbException::AUTH                          => self::AUTH,
            DbException::DATABASE, DbException::QUERY  => self::SERVER,
            default                                    => self::NETWORK,
        };
    }

    public static function fromDatabase(DbException $e, string $message): self
    {
        return new self(self::kindOf($e), $message . ' (' . $e->getKind() . ')', $e);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * Language key of the friendly message, e.g. COM_BIOTIME_ERROR_NETWORK.
     */
    public function getLanguageKey(): string
    {
        return 'COM_BIOTIME_ERROR_' . strtoupper($this->kind);
    }
}
