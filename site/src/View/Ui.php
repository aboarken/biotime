<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Small helpers shared by the portal templates: the colour ("tone") and symbol of each day status, in the portal's own design (media/com_biotime/css/portal.css, .bt-tone-*).
 */
final class Ui
{
    /** Day status => tone. Green = fine, amber = needs a look, red = absent, slate = no work. */
    private const STATUS_TONES = [
        'present'    => 'green',
        'checked_in' => 'teal',
        'checked_out' => 'blue',
        'late'       => 'amber',
        'early'      => 'amber',
        'incomplete' => 'amber',
        'missing_punch' => 'amber',
        'absent'     => 'red',
        'holiday'    => 'purple',
        'rest'       => 'slate',
        'weekend'    => 'weekend',
        'other'      => 'muted',
    ];

    /** A symbol per status, so the calendar can show it in a narrow phone cell (with the legend). */
    private const STATUS_SYMBOLS = [
        'present'    => '✓',
        'checked_in' => '↘',
        'checked_out' => '↗',
        'late'       => '⏱',
        'early'      => '↧',
        'incomplete' => '!',
        'missing_punch' => '!',
        'absent'     => '✕',
        'holiday'    => '★',
        'rest'       => '○',
        'weekend'    => '○',
        'other'      => '•',
    ];

    /**
     * One colour per card (1.0.5), the same on My Attendance, My Dashboard and the Manager
     * Dashboard, so no two cards look alike (tones in media/css/portal.css).
     */
    private const CARD_TONES = [
        'COM_BIOTIME_CARD_ALL_DAYS'             => 'ink',
        'COM_BIOTIME_MANAGER_WORKFORCE'         => 'slate',
        'COM_BIOTIME_CARD_PRESENT_DAYS'         => 'green',
        'COM_BIOTIME_CARD_ABSENT_DAYS'          => 'red',
        'COM_BIOTIME_CARD_LEAVES'               => 'orange',
        'COM_BIOTIME_CARD_MISSING_MORNING'      => 'amber',
        'COM_BIOTIME_CARD_MISSING_AFTERNOON'    => 'gold',
        'COM_BIOTIME_MANAGER_MORNING_PUNCHES'   => 'teal',
        'COM_BIOTIME_MANAGER_AFTERNOON_PUNCHES' => 'cyan',
        'COM_BIOTIME_CARD_HOLIDAYS'             => 'purple',
        'COM_BIOTIME_CARD_WEEKENDS'             => 'weekend',
        'COM_BIOTIME_CARD_WEEKEND_WORKED'       => 'sky',
        'COM_BIOTIME_CARD_RATE'                 => 'lime',
        'COM_BIOTIME_CARD_AVG_CHECK_IN'         => 'emerald',
        'COM_BIOTIME_CARD_AVG_CHECK_OUT'        => 'indigo',
        'COM_BIOTIME_CARD_PUNCHES'              => 'blue',
        'COM_BIOTIME_CARD_LATE_TOTAL'           => 'rose',
        'COM_BIOTIME_CARD_EARLY_TOTAL'          => 'fuchsia',
        'COM_BIOTIME_CARD_LATE_EARLY'           => 'crimson',
        'COM_BIOTIME_CARD_WORKED'               => 'brown',
        'COM_BIOTIME_CARD_OVERTIME'             => 'stone',
    ];

    /** The card's own colour (1.0.5); slate for a card not in the list. */
    public static function cardTone(string $labelKey): string
    {
        return self::CARD_TONES[$labelKey] ?? 'slate';
    }

    public static function tone(?string $status): string
    {
        return self::STATUS_TONES[$status ?? ''] ?? 'muted';
    }

    public static function symbol(?string $status): string
    {
        return self::STATUS_SYMBOLS[$status ?? ''] ?? '•';
    }

    /** Tone for a count: the given tone when there is something, muted for zero. */
    public static function countTone(int $value, string $tone): string
    {
        return $value > 0 ? $tone : 'muted';
    }
}
