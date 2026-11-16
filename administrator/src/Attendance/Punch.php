<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Attendance;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects


/**
 * One punch (transaction) as ZKBioTime recorded it, in the ZKBioTime server's local time.
 */
final class Punch
{
    public const IN    = 'in';
    public const OUT   = 'out';
    public const BREAK = 'break';

    /**
     * @param ?string $state     the state text ZKBioTime shows (e.g. "Check In")
     * @param ?string $stateCode ZKBioTime's punch state code, used only when there is no text
     */
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $state,
        public readonly ?string $device,
        public readonly ?string $area,
        public readonly ?string $stateCode = null
    ) {
    }

    /** @param array{t: string, s?: ?string, c?: ?string, d?: ?string, a?: ?string} $row */
    public static function fromCache(array $row): self
    {
        return new self(substr($row['t'], 0, 10), substr($row['t'], 11), $row['s'] ?? null, $row['d'] ?? null, $row['a'] ?? null, $row['c'] ?? null);
    }

    /**
     * Whether this punch is a check-in, a check-out or a break punch, as ZKBioTime recorded it;
     * null when unknown. Overtime in/out count as in/out. The state text decides; the code only
     * when there is no text (ZKTeco codes 0/4 = in, 1/5 = out, 2/3 = break). UNVERIFIED: the 9.5
     * manual does not list the codes; the real server shows 0 = "Check In", 255 = "Unknown".
     */
    public function direction(): ?string
    {
        if ($this->state !== null) {
            $s = mb_strtolower($this->state);

            return match (true) {
                (bool) preg_match('/break|rest|lunch/', $s)               => self::BREAK,
                (bool) preg_match('/(^|[^a-z])(out|off|exit|leave)/', $s) => self::OUT,
                (bool) preg_match('/(^|[^a-z])(in|on|entry|enter)/', $s)  => self::IN,
                default                                                  => self::fromCode($this->stateCode),
            };
        }

        return self::fromCode($this->stateCode);
    }

    private static function fromCode(?string $code): ?string
    {
        return match ($code) {
            '0', '4' => self::IN,
            '1', '5' => self::OUT,
            '2', '3' => self::BREAK,
            default  => null,
        };
    }

    public function seconds(): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $this->time . ':00:00'));

        return $h * 3600 + $m * 60 + $s;
    }
}
