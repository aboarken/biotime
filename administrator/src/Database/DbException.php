<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Database;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A direct database read failed. The kind decides the message shown to the administrator; the
 * detail is the driver's error text, one line, cleaned (it never contains the password).
 */
final class DbException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const DRIVER_MISSING = 'driver_missing';
    public const UNREACHABLE    = 'unreachable';
    public const AUTH           = 'auth';
    public const SSL            = 'ssl';
    public const DATABASE       = 'database';
    public const QUERY          = 'query';

    public const KINDS = [self::NOT_CONFIGURED, self::DRIVER_MISSING, self::UNREACHABLE, self::AUTH, self::SSL, self::DATABASE, self::QUERY];

    public function __construct(private readonly string $kind, string $message, private readonly string $detail = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getLanguageKey(): string
    {
        return 'COM_BIOTIME_DB_ERR_' . strtoupper($this->kind);
    }

    /**
     * Sort a driver error into a kind, from its SQLSTATE and text (libpq and the SQL Server driver
     * word them differently).
     */
    public static function fromDriver(\PDOException $e, string $context): self
    {
        $text  = strtolower($e->getMessage());
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        $kind = match (true) {
            str_contains($text, 'ssl') || str_contains($text, 'certificate') || str_contains($text, 'tls')
                || str_contains($text, 'encrypt')                                                       => self::SSL,
            str_contains($text, 'password') || str_contains($text, 'login failed')
                || str_contains($text, 'authentication') || str_contains($text, 'pg_hba')
                || \in_array($state, ['28000', '28P01'], true)                                            => self::AUTH,
            str_contains($text, 'database') && (str_contains($text, 'does not exist') || str_contains($text, 'cannot open'))
                || $state === '3D000'                                                                     => self::DATABASE,
            str_contains($text, 'could not connect') || str_contains($text, 'connection refused')
                || str_contains($text, 'timeout') || str_contains($text, 'timed out')
                || str_contains($text, 'could not translate host') || str_contains($text, 'network')
                || str_contains($text, 'unreachable') || str_starts_with($state, '08')
                || str_contains($text, 'tcp provider')                                                    => self::UNREACHABLE,
            default                                                                                       => $context === 'connect' ? self::UNREACHABLE : self::QUERY,
        };

        return new self($kind, 'Database ' . $context . ' failed', self::clean($e->getMessage()), $e);
    }

    /** The driver's SQLSTATE (e.g. 42501), '' without one: safe to log, unlike the driver's text. */
    public function sqlState(): string
    {
        $previous = $this->getPrevious();
        $state    = $previous instanceof \PDOException ? (string) ($previous->errorInfo[0] ?? $previous->getCode()) : '';

        return preg_match('/^[0-9A-Z]{5}$/', $state) === 1 ? $state : '';
    }

    /** One line, at most 300 characters, with anything that looks like a password removed. */
    public static function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace('/(password|pwd)\s*=\s*(\'[^\']*\'|"[^"]*"|\S+)/iu', '$1=…', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return mb_substr($text, 0, 300);
    }
}
