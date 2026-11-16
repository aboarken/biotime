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
    public const VERIFY_FINGERPRINT = 'fingerprint';
    public const VERIFY_FACE        = 'face';
    public const VERIFY_OTHER       = 'other';

    /**
     * @param ?string $state     the state text ZKBioTime shows (e.g. "Check In")
     * @param ?string $stateCode ZKBioTime's punch state code (kept for display; the time of day decides check-in and check-out)
     * @param ?string $verify    how the employee was verified (e.g. "Fingerprint", "Face")
     * @param ?string $sn        serial number of the device
     * @param ?string $verifyCode ZKBioTime's verify type code, used only when there is no text
     */
    public function __construct(
        public readonly string $date,
        public readonly string $time,
        public readonly ?string $state,
        public readonly ?string $device,
        public readonly ?string $area,
        public readonly ?string $stateCode = null,
        public readonly ?string $verify = null,
        public readonly ?string $sn = null,
        public readonly ?string $verifyCode = null
    ) {
    }

    /** @param array{t: string, s?: ?string, c?: ?string, d?: ?string, a?: ?string, v?: ?string, n?: ?string, vc?: ?string} $row */
    public static function fromCache(array $row): self
    {
        $text = static fn (string $key): ?string => self::known($row[$key] ?? null);

        return new self(substr($row['t'], 0, 10), substr($row['t'], 11), $text('s'), $text('d'), $text('a'), $row['c'] ?? null, $text('v'), $text('n'), $row['vc'] ?? null);
    }

    /**
     * A text value, or null when ZKBioTime only says "Unknown" (as many devices record the punch
     * state, e.g. state 255): that word is not shown in the punch details.
     */
    public static function known(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || strcasecmp($value, 'unknown') === 0 ? null : $value;
    }

    /**
     * How the employee was verified: fingerprint, face or other (card, password, palm, unknown).
     * The verify text decides ("Fingerprint", "Face"); the code only when there is no text
     * (1 = fingerprint, 15 = face, as seen on the real server).
     */
    public function verifyKind(): string
    {
        $text = mb_strtolower((string) $this->verify);

        return match (true) {
            str_contains($text, 'face')                                   => self::VERIFY_FACE,
            str_contains($text, 'finger') || preg_match('/\bfp\b/', $text) === 1 => self::VERIFY_FINGERPRINT,
            $text === '' && $this->verifyCode === '1'                     => self::VERIFY_FINGERPRINT,
            $text === '' && $this->verifyCode === '15'                    => self::VERIFY_FACE,
            default                                                       => self::VERIFY_OTHER,
        };
    }

    public function seconds(): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $this->time . ':00:00'));

        return $h * 3600 + $m * 60 + $s;
    }
}
