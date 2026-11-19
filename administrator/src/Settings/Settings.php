<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;

/**
 * Immutable view of the settings stored in #__biotime_settings (name/value rows).
 *
 * Settings live in the component's own table, not in com_config params: com_config replaces all
 * params on save, and the database password must be encrypted before it is stored.
 */
final class Settings
{
    public const DEFAULT_CACHE_MINUTES = 5;
    public const MAX_CACHE_MINUTES     = 60;

    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y'];
    public const TIME_FORMATS = ['H:i', 'H:i:s', 'h:i A'];

    public const DEFAULTS = [
        // Database connection and query timeouts (seconds)
        'connect_timeout'  => '5',
        'timeout'          => '30',
        // Employee mapping: the Joomla custom user field holding emp_code, then e-mail as fallback
        'match_field'      => 'employeeid',
        'auto_match'       => '1',
        // New users found by e-mail (1.0.19): 0 = wait for an administrator, 1 = approved at once
        'auto_approve_matches' => '0',
        // Weekend days, ISO weekdays (1 = Monday … 7 = Sunday), comma-separated: Friday and Saturday
        'weekend_days'     => '5,6',
        // ZKBioTime's own database (0.5.0), read by every portal page (1.0.0); '' = not set up
        'db_engine'        => '',
        'db_host'          => '',
        'db_port'          => '7496',
        // "IP" or "IP:port" the connection is opened to instead of the host (PostgreSQL), '' = direct
        'db_connect_via'   => '',
        'db_name'          => 'biotime',
        'db_user'          => '',
        'db_password_enc'  => '',
        'db_ssl'           => 'verify',
        'db_ca'            => '',
        // Unit of ZKBioTime's duration columns: auto, minutes or seconds
        'db_duration_unit' => 'auto',
        // System
        'detailed_log'     => '1',
        'cache_minutes'    => '5',
        'date_format'      => 'd/m/Y',
        'time_format'      => 'H:i',
        'server_timezone'  => '',
        // Letterhead of the printed and PDF reports (1.0.11): logo (an image of the Media
        // Manager), a line of text on each side under it, the footer lines and the main colour
        'report_logo'         => '',
        'report_header_left'  => '',
        'report_header_right' => '',
        'report_footer'       => '',
        'report_color'        => '#1d3f74',
    ];

    /** Image types the report logo may be (1.0.11). */
    public const REPORT_LOGO_TYPES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

    /** Names never sent to the browser. */
    public const SECRET_NAMES = ['db_password_enc'];

    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $name): string
    {
        return $this->values[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    public function connectTimeout(): int
    {
        return max(1, min(60, $this->timeout(), (int) $this->get('connect_timeout')));
    }

    public function timeout(): int
    {
        return max(1, min(300, (int) $this->get('timeout')));
    }

    /** Name of the Joomla custom user field that holds the employee code; '' = not used. */
    public function matchField(): string
    {
        $name = $this->get('match_field');

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $name) ? $name : '';
    }

    public function autoMatch(): bool
    {
        return $this->get('auto_match') !== '0';
    }

    /** New users found by e-mail are approved at once (1.0.19); otherwise they wait for an administrator. */
    public function autoApproveMatches(): bool
    {
        return $this->get('auto_approve_matches') === '1';
    }

    /** Database engine of the direct read: DbConfig::ENGINE_*, or '' when not used. */
    public function dbEngine(): string
    {
        $engine = $this->get('db_engine');

        return \in_array($engine, DbConfig::ENGINES, true) ? $engine : '';
    }

    /** @return array{0: string, 1: int} connect via: IP address and port (0 = the server's port) */
    public function dbVia(): array
    {
        $via = $this->get('db_connect_via');

        if ($via === '') {
            return ['', 0];
        }

        // Stored normalised by SettingsValidator: "IP", "IP:port" or "[IPv6]:port".
        if (preg_match('/^\[(.+)\]:(\d+)$/', $via, $m) || preg_match('/^([^:]+):(\d+)$/', $via, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$via, 0];
    }

    /**
     * The portal pages can read the database: they need PostgreSQL (the queries are written for
     * it; SQL Server is only checked by Test Database).
     */
    public function readsDatabase(): bool
    {
        return $this->dbEngine() === DbConfig::ENGINE_PGSQL;
    }

    public function dbDurationUnit(): string
    {
        return \in_array($this->get('db_duration_unit'), AttendanceReader::UNITS, true) ? $this->get('db_duration_unit') : 'auto';
    }

    public function hasDbPassword(): bool
    {
        return $this->get('db_password_enc') !== '';
    }

    /**
     * The direct-read connection, with the password decrypted.
     *
     * @throws DbException NOT_CONFIGURED when it is off, incomplete or cannot be decrypted
     */
    public function dbConfig(KeyVault $vault): DbConfig
    {
        if ($this->dbEngine() === '' || $this->get('db_host') === '' || $this->get('db_user') === '' || !$this->hasDbPassword()) {
            throw new DbException(DbException::NOT_CONFIGURED, 'Database connection is not configured');
        }

        try {
            $password = $vault->decrypt($this->get('db_password_enc'), KeyVault::CONTEXT_DB_PASSWORD);

            return new DbConfig(
                $this->dbEngine(),
                $this->get('db_host'),
                (int) $this->get('db_port'),
                $this->get('db_name'),
                $this->get('db_user'),
                $password,
                $this->get('db_ssl'),
                $this->get('db_ca'),
                $this->connectTimeout(),
                $this->timeout(),
                ...$this->dbVia()
            );
        } catch (KeyVaultException | \InvalidArgumentException $e) {
            throw new DbException(DbException::NOT_CONFIGURED, 'Database settings are incomplete or cannot be decrypted', '', $e);
        }
    }

    public function detailedLog(): bool
    {
        return $this->get('detailed_log') !== '0';
    }

    /** Minutes read-only results are kept per user (0 = no cache). */
    public function cacheMinutes(): int
    {
        $value = $this->get('cache_minutes');

        return $value === '' ? self::DEFAULT_CACHE_MINUTES : max(0, min(self::MAX_CACHE_MINUTES, (int) $value));
    }

    public function cacheTtl(): int
    {
        return $this->cacheMinutes() * 60;
    }

    public function dateFormat(): string
    {
        $format = $this->get('date_format');

        return \in_array($format, self::DATE_FORMATS, true) ? $format : 'd/m/Y';
    }

    public function timeFormat(): string
    {
        $format = $this->get('time_format');

        return \in_array($format, self::TIME_FORMATS, true) ? $format : 'H:i';
    }

    /** IANA time zone of the ZKBioTime server; '' = Joomla's server time zone. */
    public function serverTimezone(): string
    {
        $zone = $this->get('server_timezone');

        return \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : '';
    }

    /**
     * Weekend days as ISO weekdays (1 = Monday … 7 = Sunday). A weekend day without punches is
     * shown as "Weekend" instead of "Absent". Default: Friday and Saturday.
     *
     * @return list<int>
     */
    public function weekendDays(): array
    {
        return self::parseWeekdays($this->get('weekend_days'));
    }

    /**
     * @param string|list<string|int> $value "5,6" or a list of weekdays
     *
     * @return list<int>
     */
    public static function parseWeekdays(string|array $value): array
    {
        $list = \is_array($value) ? $value : explode(',', $value);
        $days = [];

        foreach ($list as $item) {
            $item = trim((string) $item);

            if (preg_match('/^[1-7]$/', $item)) {
                $days[] = (int) $item;
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * Values safe to show in the form: no ciphertext.
     *
     * @return array<string, string>
     */
    public function toFormData(): array
    {
        $data = array_merge(self::DEFAULTS, $this->values);

        foreach (self::SECRET_NAMES as $name) {
            unset($data[$name]);
        }

        // Host and port are one field in the form, as the server URL.
        $data['db_server'] = $this->get('db_host') !== '' ? DbConfig::formatAddress($this->get('db_host'), (int) $this->get('db_port')) : '';
        unset($data['db_host'], $data['db_port']);

        return $data;
    }

    public function __debugInfo(): array
    {
        return $this->toFormData() + [
            'db_password_enc' => $this->hasDbPassword() ? '[set]' : '',
        ];
    }

    /**
     * Letterhead of the printed and PDF reports (1.0.11), as stored by SettingsValidator: the logo
     * (a path below images/, '' for none), the texts under it, the footer lines and the colour.
     *
     * @return array{logo: string, left: string, right: string, footer: list<string>, color: string}
     */
    public function letterhead(): array
    {
        $logo  = $this->get('report_logo');
        $color = $this->get('report_color');

        return [
            'logo'   => self::isReportLogo($logo) ? $logo : '',
            'left'   => $this->get('report_header_left'),
            'right'  => $this->get('report_header_right'),
            'footer' => array_values(array_filter(array_map('trim', explode("\n", $this->get('report_footer'))), static fn (string $l): bool => $l !== '')),
            'color'  => preg_match('/^#[0-9a-f]{6}\z/i', $color) ? strtolower($color) : self::DEFAULTS['report_color'],
        ];
    }

    /** A logo path the reports may show: an image file below the site's images/ folder. */
    public static function isReportLogo(string $path): bool
    {
        return (bool) preg_match('#^images/(?:[A-Za-z0-9 _().-]+/)*[A-Za-z0-9 _().-]+\.(?:' . implode('|', self::REPORT_LOGO_TYPES) . ')\z#i', $path)
            && !preg_match('#(^|/)\.\.?(/|$)#', $path);
    }
}
