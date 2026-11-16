<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Devices\Device;

/**
 * Small helpers shared by the portal templates: the colour ("tone") of each day status and device
 * health, in the portal's own design (media/com_biotime/css/portal.css, .bt-tone-*).
 */
final class Ui
{
    /** Day status => tone. Green = fine, amber = needs a look, red = absent, slate = no work. */
    private const STATUS_TONES = [
        'present'    => 'green',
        'checked_in' => 'teal',
        'late'       => 'amber',
        'early'      => 'amber',
        'incomplete' => 'amber',
        'absent'     => 'red',
        'leave'      => 'blue',
        'holiday'    => 'purple',
        'rest'       => 'slate',
        'weekend'    => 'slate',
        'punched'    => 'blue',
        'other'      => 'muted',
    ];

    /** A symbol per status, so the calendar can show it in a narrow phone cell (with the legend). */
    private const STATUS_SYMBOLS = [
        'present'    => '✓',
        'checked_in' => '→',
        'late'       => '⏱',
        'early'      => '↧',
        'incomplete' => '!',
        'absent'     => '✕',
        'leave'      => '✈',
        'holiday'    => '★',
        'rest'       => '○',
        'weekend'    => '○',
        'punched'    => '•',
        'other'      => '•',
    ];

    private const HEALTH_TONES = [
        Device::ONLINE  => 'green',
        Device::DELAYED => 'amber',
        Device::OFFLINE => 'red',
        Device::UNKNOWN => 'muted',
    ];

    public static function tone(?string $status): string
    {
        return self::STATUS_TONES[$status ?? ''] ?? 'muted';
    }

    public static function symbol(?string $status): string
    {
        return self::STATUS_SYMBOLS[$status ?? ''] ?? '•';
    }

    public static function healthTone(string $health): string
    {
        return self::HEALTH_TONES[$health] ?? 'muted';
    }

    /** Tone for a count: the given tone when there is something, muted for zero. */
    public static function countTone(int $value, string $tone): string
    {
        return $value > 0 ? $tone : 'muted';
    }
}
