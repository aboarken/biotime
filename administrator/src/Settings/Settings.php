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
    public const DEFAULT_REPORT_DAYS   = 93;

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
        // Modules within the six pages
        'module_charts'    => '1',
        'module_exports'   => '1',
        'module_leave'     => '1',
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
        // Leave
        'leave_path'          => '',
        'leave_param_emp'     => 'emp_code',
        'leave_param_from'    => 'start_date',
        'leave_param_to'      => 'end_date',
        'leave_date_format'   => 'Y-m-d',
        'leave_extra'         => '',
        'leave_fields'        => '',
        'leave_duration_unit' => 'hours',
        'leave_status_map'    => '',
        // System
        'detailed_log'     => '1',
        'cache_minutes'    => '5',
        'page_size'        => '100',
        'max_pages'        => '50',
        'date_format'      => 'Y-m-d',
        'time_format'      => 'H:i',
        'server_timezone'  => '',
        'export_per_hour'  => '10',
        'report_max_days'  => '93',
        // Internal: the cached API token (encrypted) and when to renew it. Never in the form.
        'token_cache_enc'      => '',
        'token_cache_renew_at' => '',
    ];

    /** Names never sent to the browser. */
    public const SECRET_NAMES = ['api_password_enc', 'api_token_enc', 'token_cache_enc', 'token_cache_renew_at'];

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

    /** @param 'charts'|'exports'|'leave' $module */
    public function moduleEnabled(string $module): bool
    {
        return $this->get('module_' . $module) !== '0';
    }

    /**
     * Where calculated daily attendance (or leave) is read, as configured by the administrator.
     *
     * @param 'daily'|'leave' $kind
     */
    public function source(string $kind): SourceConfig
    {
        return SourceConfig::fromSettings($this, $kind);
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

        return \in_array($format, self::DATE_FORMATS, true) ? $format : 'Y-m-d';
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

    public function exportsPerHour(): int
    {
        return max(1, min(1000, (int) $this->get('export_per_hour') ?: 10));
    }

    public function reportMaxDays(): int
    {
        return max(1, min(366, (int) $this->get('report_max_days') ?: self::DEFAULT_REPORT_DAYS));
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

        return $data;
    }

    public function __debugInfo(): array
    {
        return $this->toFormData() + [
            'api_password_enc' => $this->hasPassword() ? '[set]' : '',
            'api_token_enc'    => $this->hasStaticToken() ? '[set]' : '',
        ];
    }
}
