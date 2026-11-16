<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Devices;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One biometric device (terminal) as ZKBioTime lists it, with its health. Values ZKBioTime did not
 * send are null and shown as "—".
 */
final class Device
{
    public const ONLINE  = 'online';
    public const DELAYED = 'delayed';
    public const OFFLINE = 'offline';
    public const UNKNOWN = 'unknown';

    public const HEALTHS = [self::ONLINE, self::DELAYED, self::OFFLINE, self::UNKNOWN];

    /**
     * @param ?string $lastActivity  "YYYY-MM-DD HH:MM:SS" in the ZKBioTime server's time zone
     * @param ?int    $minutesSince  minutes since the last activity; null when unknown
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $sn,
        public readonly ?string $alias,
        public readonly ?string $model,
        public readonly ?string $ip,
        public readonly ?string $area,
        public readonly ?string $firmware,
        public readonly ?string $lastActivity,
        public readonly ?int $minutesSince,
        public readonly string $health,
        public readonly ?string $stateText,
        public readonly ?int $users,
        public readonly ?int $fingerprints,
        public readonly ?int $faces,
        public readonly ?int $palms,
        public readonly ?int $transactions,
        public readonly ?bool $attendance
    ) {
    }
}
