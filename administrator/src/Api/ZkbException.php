<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Api;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Error raised by ZkbClient. The "kind" is stable and selects the user-facing language string;
 * the server message is kept (scrubbed) for the log only and is never shown to employees.
 */
final class ZkbException extends \RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';
    public const NETWORK        = 'network';
    public const AUTH           = 'auth';
    public const PERMISSION     = 'permission';
    public const NOT_FOUND      = 'not_found';
    public const VALIDATION     = 'validation';
    public const RATE_LIMITED   = 'rate_limited';
    public const SERVER         = 'server';
    public const BAD_RESPONSE   = 'bad_response';

    public const KINDS = [
        self::NOT_CONFIGURED, self::NETWORK, self::AUTH, self::PERMISSION, self::NOT_FOUND,
        self::VALIDATION, self::RATE_LIMITED, self::SERVER, self::BAD_RESPONSE,
    ];

    public function __construct(
        private readonly string $kind,
        string $message,
        private readonly int $httpStatus = 0,
        private readonly string $serverMessage = '',
        ?\Throwable $previous = null
    ) {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Unknown error kind');
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * Map an HTTP error status to an error kind.
     */
    public static function fromStatus(int $httpStatus, string $serverMessage = ''): self
    {
        $kind = match (true) {
            $httpStatus === 401                       => self::AUTH,
            $httpStatus === 403                       => self::PERMISSION,
            $httpStatus === 404, $httpStatus === 405  => self::NOT_FOUND,
            $httpStatus === 429                       => self::RATE_LIMITED,
            $httpStatus >= 500                        => self::SERVER,
            default                                   => self::VALIDATION,
        };

        return new self($kind, 'ZKBioTime returned an error (' . $kind . ')', $httpStatus, $serverMessage);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Message text from ZKBioTime, scrubbed and truncated. Safe to log; never shown to employees.
     */
    public function getServerMessage(): string
    {
        return $this->serverMessage;
    }

    /**
     * Language key of the friendly message, e.g. COM_ZKBIOTIME_ERROR_NETWORK.
     */
    public function getLanguageKey(): string
    {
        return 'COM_ZKBIOTIME_ERROR_' . strtoupper($this->kind);
    }
}
