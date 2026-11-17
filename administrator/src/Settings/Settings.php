<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Settings;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\Credentials;
use BiotimePortal\Component\Biotime\Administrator\Attendance\SourceConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\AttendanceReader;
use BiotimePortal\Component\Biotime\Administrator\Database\DbConfig;
use BiotimePortal\Component\Biotime\Administrator\Database\DbException;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;

/**
 * Immutable view of the settings stored in #__biotime_settings (name/value rows).
 *
 * Settings live in the component's own table, not in com_config params: com_config replaces all
 * params on save, and the password and token must be encrypted before they are stored.
 */
final class Settings
{
    public const DEFAULT_PAGE_SIZE     = 100;
    public const DEFAULT_MAX_PAGES     = 50;
    public const DEFAULT_CACHE_MINUTES = 5;
    public const MAX_CACHE_MINUTES     = 60;

    public const DATE_FORMATS = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y'];
    public const TIME_FORMATS = ['H:i', 'H:i:s', 'h:i A'];

    public const DEFAULTS = [
        // Connection. The port is part of the server URL (https://host:5555).
        'server_url'       => '',
        // Read only: port of versions before 0.2.0, merged into the server URL until saved.
        'server_port'      => '',
        'auth_method'      => Credentials::METHOD_JWT,
        'api_username'     => '',
        'api_password_enc' => '',
        'api_token_enc'    => '',
        'connect_timeout'  => '5',
        'timeout'          => '30',
        'verify_tls'       => '1',
        'ca_bundle'        => '',
        'tls_pin'          => '',
        'connect_via'      => '',
        // Employee mapping: the Joomla custom user field holding emp_code, then e-mail as fallback
        'match_field'      => 'employeeid',
        'auto_match'       => '1',
        // Modules within the portal pages
        'module_charts'    => '1',
        // Weekend days, ISO weekdays (1 = Monday … 7 = Sunday), comma-separated: Friday and Saturday
        'weekend_days'     => '5,6',
        // Calculated daily attendance and schedules: a read-only ZKBioTime endpoint or saved report
        'daily_path'          => '',
        'daily_param_emp'     => 'emp_code',
        'daily_param_from'    => 'start_date',
        'daily_param_to'      => 'end_date',
        'daily_date_format'   => 'Y-m-d',
        'daily_extra'         => '',
        'daily_fields'        => '',
        'daily_duration_unit' => 'minutes',
        'daily_status_map'    => '',
        // Direct read from ZKBioTime's own database (0.5.0); '' = not used
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
        // Where the portal pages read attendance: 'api' or 'database' (0.6.0)
        'data_source'      => 'api',
        // Unit of ZKBioTime's duration columns: auto, minutes or seconds
        'db_duration_unit' => 'auto',
        // System
        'detailed_log'     => '1',
        'cache_minutes'    => '5',
        'page_size'        => '100',
        'max_pages'        => '50',
        'date_format'      => 'd/m/Y',
        'time_format'      => 'H:i',
        'server_timezone'  => '',
        // Internal: the cached API token (encrypted) and when to renew it. Never in the form.
        'token_cache_enc'      => '',
        'token_cache_renew_at' => '',
    ];

    /** Names never sent to the browser. */
    public const SECRET_NAMES = ['api_password_enc', 'api_token_enc', 'token_cache_enc', 'token_cache_renew_at', 'db_password_enc'];

    /** @param array<string, string> $values */
    public function __construct(private readonly array $values = [])
    {
    }

    public function get(string $name): string
    {
        return $this->values[$name] ?? self::DEFAULTS[$name] ?? '';
    }

    /**
     * The server URL including its port. A port stored separately by an older version is merged
     * in, so existing sites keep working until the settings are saved again.
     */
    public function serverUrl(): string
    {
        $url  = $this->get('server_url');
        $port = (int) $this->get('server_port');

        if ($url === '' || $port < 1 || $port > 65535 || parse_url($url, PHP_URL_PORT) !== null) {
            return $url;
        }

        $parts = parse_url($url);

        if (!\is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $default = strtolower($parts['scheme']) === 'https' ? 443 : 80;

        return $parts['scheme'] . '://' . $parts['host'] . ($port !== $default ? ':' . $port : '') . ($parts['path'] ?? '');
    }

    public function authMethod(): string
    {
        $method = $this->get('auth_method');

        return \in_array($method, Credentials::METHODS, true) ? $method : Credentials::METHOD_JWT;
    }

    public function username(): string
    {
        return $this->get('api_username');
    }

    public function passwordCiphertext(): string
    {
        return $this->get('api_password_enc');
    }

    public function hasPassword(): bool
    {
        return $this->passwordCiphertext() !== '';
    }

    public function staticTokenCiphertext(): string
    {
        return $this->get('api_token_enc');
    }

    public function hasStaticToken(): bool
    {
        return $this->staticTokenCiphertext() !== '';
    }

    /** Whether enough is stored to try a connection. */
    public function isConfigured(): bool
    {
        if ($this->serverUrl() === '') {
            return false;
        }

        $login = $this->username() !== '' && $this->hasPassword();

        return $this->authMethod() === Credentials::METHOD_JWT ? $login : ($this->hasStaticToken() || $login);
    }

    public function connectTimeout(): int
    {
        return max(1, min(60, $this->timeout(), (int) $this->get('connect_timeout')));
    }

    public function timeout(): int
    {
        return max(1, min(300, (int) $this->get('timeout')));
    }

    public function verifyTls(): bool
    {
        return $this->get('verify_tls') !== '0';
    }

    public function caBundle(): string
    {
        return $this->get('ca_bundle');
    }

    public function tlsPin(): string
    {
        return $this->get('tls_pin');
    }

    /** "host", "host:port" or "IP:port" to connect to instead of the server URL's host; '' = direct. */
    public function connectVia(): string
    {
        return $this->get('connect_via');
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

    /** @param 'charts' $module */
    public function moduleEnabled(string $module): bool
    {
        return $this->get('module_' . $module) !== '0';
    }

    /**
     * Where calculated daily attendance is read, as configured by the administrator.
     *
     * @param 'daily' $kind
     */
    public function source(string $kind): SourceConfig
    {
        return SourceConfig::fromSettings($this, $kind);
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

    /** Pages read attendance from the database (PostgreSQL only), not the API. */
    public function readsDatabase(): bool
    {
        return $this->get('data_source') === 'database' && $this->dbEngine() === DbConfig::ENGINE_PGSQL;
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

    public function pageSize(): int
    {
        return max(1, min(1000, (int) $this->get('page_size') ?: self::DEFAULT_PAGE_SIZE));
    }

    public function maxPages(): int
    {
        return max(1, min(1000, (int) $this->get('max_pages') ?: self::DEFAULT_MAX_PAGES));
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

    public function isHttps(): bool
    {
        return str_starts_with(strtolower($this->serverUrl()), 'https://');
    }

    /**
     * Values safe to show in the form: no ciphertext, no cached token, the port inside the URL.
     *
     * @return array<string, string>
     */
    public function toFormData(): array
    {
        $data = array_merge(self::DEFAULTS, $this->values);

        foreach (self::SECRET_NAMES as $name) {
            unset($data[$name]);
        }

        $data['server_url'] = $this->serverUrl();
        unset($data['server_port']);

        // Host and port are one field in the form, as the server URL.
        $data['db_server'] = $this->get('db_host') !== '' ? DbConfig::formatAddress($this->get('db_host'), (int) $this->get('db_port')) : '';
        unset($data['db_host'], $data['db_port']);

        return $data;
    }

    public function __debugInfo(): array
    {
        return $this->toFormData() + [
            'api_password_enc' => $this->hasPassword() ? '[set]' : '',
            'api_token_enc'    => $this->hasStaticToken() ? '[set]' : '',
            'db_password_enc'  => $this->hasDbPassword() ? '[set]' : '',
        ];
    }
}
