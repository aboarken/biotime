<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVault;
use BiotimePortal\Component\Biotime\Administrator\Security\KeyVaultException;
use BiotimePortal\Component\Biotime\Administrator\Settings\Settings;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsValidator;
use BiotimePortal\Component\Biotime\Administrator\Settings\ValidationFailed;

final class SettingsValidatorTest extends TestCase
{
    private KeyVault $vault;

    protected function setUp(): void
    {
        $this->vault = new KeyVault('a-joomla-configuration-secret-value');
    }

    private function validator(?\Closure $fileExists = null): SettingsValidator
    {
        return new SettingsValidator(fn (string $p, string $c): string => $this->vault->encrypt($p, $c), $fileExists ?? static fn (): bool => true);
    }

    /** @return array<string, string> */
    private function input(array $override = []): array
    {
        return $override + [
            'server_url'      => 'https://biotime.example.com:8443',
            'auth_method'     => 'jwt',
            'api_username'    => 'portal-api',
            'api_password'    => 'Pa55 word!',
            'api_token'       => '',
            'connect_timeout' => '5',
            'timeout'         => '30',
            'verify_tls'      => '1',
            'ca_bundle'       => '',
            'tls_pin'         => '',
            'page_size'       => '100',
            'max_pages'       => '50',
            'detailed_log'    => '1',
            'cache_minutes'   => '5',
            'date_format'     => 'Y-m-d',
            'time_format'     => 'H:i',
            'server_timezone' => '',
            'export_per_hour' => '10',
            'report_max_days' => '93',
            'match_field'     => 'employeeid',
            'auto_match'      => '1',
            'module_charts'   => '1',
            'module_exports'  => '1',
            'module_leave'    => '1',
        ];
    }

    /** @return list<string> */
    private function errors(array $input, ?Settings $current = null): array
    {
        try {
            $this->validator()->validate($input, $current ?? new Settings());
        } catch (ValidationFailed $e) {
            return $e->getKeys();
        }

        return [];
    }

    public function testValidSettingsAreStoredWithEncryptedPassword(): void
    {
        $values = $this->validator()->validate($this->input(), new Settings());

        self::assertSame('https://biotime.example.com:8443', $values['server_url']);
        self::assertSame('', $values['server_port'], 'the old separate port is cleared');
        self::assertSame('Pa55 word!', $this->vault->decrypt($values['api_password_enc'], KeyVault::CONTEXT_PASSWORD));
        self::assertArrayNotHasKey('api_password', $values, 'plain password is never stored');
        self::assertSame('', $values['token_cache_enc'], 'saving empties the cached token');
        self::assertSame('', $values['api_token_enc'], 'no token stored');
        self::assertSame(array_keys(Settings::DEFAULTS), array_values(array_intersect(array_keys(Settings::DEFAULTS), array_keys($values))));
        self::assertSame([], array_diff(array_keys($values), array_keys(Settings::DEFAULTS)), 'only known names');
    }

    public function testEmptyPasswordKeepsTheStoredOne(): void
    {
        $current = new Settings(['api_password_enc' => 'v1:stored']);
        $values  = $this->validator()->validate($this->input(['api_password' => '']), $current);

        self::assertArrayNotHasKey('api_password_enc', $values);
    }

    public function testClearPasswordRemovesItAndThenJwtFails(): void
    {
        $current = new Settings(['api_password_enc' => 'v1:stored']);

        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_JWT_CREDENTIALS'], $this->errors($this->input(['api_password' => '', 'clear_password' => '1']), $current));
    }

    public function testTokenMethodAcceptsAStaticTokenAlone(): void
    {
        $values = $this->validator()->validate($this->input([
            'auth_method' => 'token', 'api_username' => '', 'api_password' => '', 'api_token' => 'a1b2c3d4e5f6a7b8c9d0e1f2',
        ]), new Settings());

        self::assertSame('a1b2c3d4e5f6a7b8c9d0e1f2', $this->vault->decrypt($values['api_token_enc'], KeyVault::CONTEXT_STATIC_TOKEN));
    }

    public function testTokenMethodNeedsATokenOrALogin(): void
    {
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_TOKEN_CREDENTIALS'], $this->errors($this->input(['auth_method' => 'token', 'api_password' => ''])));
    }

    public function testRulesAreCheckedAndAllErrorsCollected(): void
    {
        $errors = $this->errors($this->input([
            'server_url'      => 'ftp://x',
            'auth_method'     => 'basic',
            'api_username'    => "bad\x01name",
            'api_token'       => 'short',
            'connect_timeout' => '40',
            'timeout'         => '20',
            'tls_pin'         => 'nonsense',
            'page_size'       => '0',
            'max_pages'       => '5000',
        ]));

        foreach ([
            'COM_BIOTIME_SETTINGS_ERR_URL', 'COM_BIOTIME_SETTINGS_ERR_METHOD',
            'COM_BIOTIME_SETTINGS_ERR_USERNAME', 'COM_BIOTIME_SETTINGS_ERR_TIMEOUTS', 'COM_BIOTIME_SETTINGS_ERR_PIN',
            'COM_BIOTIME_SETTINGS_ERR_PAGE_SIZE', 'COM_BIOTIME_SETTINGS_ERR_MAX_PAGES',
        ] as $key) {
            self::assertContains($key, $errors);
        }
    }

    public function testSmallerRules(): void
    {
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_URL_REQUIRED'], $this->errors($this->input(['server_url' => ''])));
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_URL'], $this->errors($this->input(['server_url' => 'https://x.example:99999'])));
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_TIMEOUT_RANGE'], $this->errors($this->input(['timeout' => '0'])));
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_PASSWORD'], $this->errors($this->input(['api_password' => "a\nb"])));
        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_TOKEN'], $this->errors($this->input(['auth_method' => 'token', 'api_token' => 'has spaces in it 1234'])));
        self::assertSame(
            ['COM_BIOTIME_SETTINGS_ERR_PIN_HTTPS'],
            $this->errors($this->input(['server_url' => 'http://10.0.0.5', 'tls_pin' => 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']))
        );
    }

    public function testConnectViaIsNormalisedWithTheServerPort(): void
    {
        $values = $this->validator()->validate($this->input(['server_url' => 'https://zkb.mysite.com', 'connect_via' => 'Api.zkb.mysite.com']), new Settings());
        self::assertSame('api.zkb.mysite.com:443', $values['connect_via']);

        $values = $this->validator()->validate($this->input(['server_url' => 'http://zkb.mysite.com:8081', 'connect_via' => 'api.zkb.mysite.com']), new Settings());
        self::assertSame('api.zkb.mysite.com:8081', $values['connect_via']);

        $values = $this->validator()->validate($this->input(), new Settings());
        self::assertSame('', $values['connect_via']);

        self::assertSame(['COM_BIOTIME_SETTINGS_ERR_CONNECT_VIA'], $this->errors($this->input(['connect_via' => 'https://api.zkb.mysite.com'])));
    }

    public function testMissingCaBundleIsRefused(): void
    {
        try {
            $this->validator(static fn (): bool => false)->validate($this->input(['ca_bundle' => '/etc/ssl/missing.pem']), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['COM_BIOTIME_SETTINGS_ERR_CA_BUNDLE'], $e->getKeys());
        }
    }

    public function testNoEncryptionSecretIsReported(): void
    {
        $validator = new SettingsValidator(static fn (): string => throw new KeyVaultException('none'), static fn (): bool => true);

        try {
            $validator->validate($this->input(), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_NO_SECRET', $e->getKeys());
        }
    }

    public function testErrorsNeverContainTheEnteredSecret(): void
    {
        try {
            $this->validator()->validate($this->input(['api_password' => "secret\x01value", 'server_url' => '']), new Settings());
        } catch (ValidationFailed $e) {
            self::assertStringNotContainsString('secret', $e->getMessage() . print_r($e->getKeys(), true));
        }
    }

    public function testSettingsNeverSendSecretsToTheForm(): void
    {
        $settings = new Settings([
            'server_url' => 'https://x.example', 'api_username' => 'u', 'api_password_enc' => 'v1:abc',
            'api_token_enc' => 'v1:def', 'token_cache_enc' => 'v1:ghi', 'token_cache_renew_at' => '1',
        ]);

        $form = $settings->toFormData();

        foreach (Settings::SECRET_NAMES as $name) {
            self::assertArrayNotHasKey($name, $form);
        }

        self::assertStringNotContainsString('v1:', print_r($settings, true));
        self::assertTrue($settings->isConfigured());
        self::assertFalse((new Settings(['server_url' => 'https://x.example', 'api_username' => 'u']))->isConfigured());
        self::assertTrue((new Settings(['server_url' => 'https://x.example', 'auth_method' => 'token', 'api_token_enc' => 'v1:x']))->isConfigured());
    }

    public function testSettingsClampStoredValues(): void
    {
        $settings = new Settings(['timeout' => '9999', 'connect_timeout' => '0', 'page_size' => '-3', 'max_pages' => '', 'server_port' => '99999', 'auth_method' => 'x', 'cache_minutes' => '500', 'date_format' => 'x', 'server_timezone' => 'Mars/Base', 'match_field' => 'Bad Name']);

        self::assertSame(300, $settings->timeout());
        self::assertSame(1, $settings->connectTimeout());
        self::assertSame(1, $settings->pageSize());
        self::assertSame(50, $settings->maxPages());
        self::assertSame('', $settings->serverUrl());
        self::assertSame('jwt', $settings->authMethod());
        self::assertSame(60, $settings->cacheMinutes());
        self::assertSame('Y-m-d', $settings->dateFormat());
        self::assertSame('', $settings->serverTimezone());
        self::assertSame('', $settings->matchField());
        self::assertSame('employeeid', (new Settings())->matchField());
    }

    public function testPortOfOlderVersionIsMergedIntoTheUrl(): void
    {
        self::assertSame('https://zkb.example.com:5555', (new Settings(['server_url' => 'https://zkb.example.com', 'server_port' => '5555']))->serverUrl());
        self::assertSame('https://zkb.example.com', (new Settings(['server_url' => 'https://zkb.example.com', 'server_port' => '443']))->serverUrl());
        self::assertSame('https://zkb.example.com:7000', (new Settings(['server_url' => 'https://zkb.example.com:7000', 'server_port' => '5555']))->serverUrl(), 'a port in the URL wins');
        self::assertSame('https://zkb.example.com:5555', (new Settings(['server_url' => 'https://zkb.example.com', 'server_port' => '5555']))->toFormData()['server_url']);
        self::assertArrayNotHasKey('server_port', (new Settings())->toFormData());
    }

    public function testDataSourcesAreValidatedAndStored(): void
    {
        $values = $this->validator()->validate($this->input([
            'daily_path'       => '/att/api/somereport/',
            'daily_param_emp'  => 'emp_code',
            'daily_param_from' => 'start_date',
            'daily_param_to'   => 'end_date',
            'daily_fields'     => "emp_code = emp_code\ndate = att_date\nstatus = attendance_status",
            'daily_status_map' => "On Time = present\nLate = late",
            'daily_extra'      => 'report_id = 12',
        ]), new Settings());

        self::assertSame('/att/api/somereport/', $values['daily_path']);
        self::assertSame("emp_code = emp_code\ndate = att_date\nstatus = attendance_status", $values['daily_fields']);

    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badSources(): iterable
    {
        yield 'traversal' => [['daily_path' => '/att/../x/'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_PATH'];
        yield 'login path' => [['daily_path' => '/jwt-api-token-auth/', 'daily_fields' => "emp_code=a\ndate=b"], 'COM_BIOTIME_SETTINGS_DAILY_ERR_PATH'];
        yield 'missing emp_code' => [['daily_path' => '/a/', 'daily_fields' => 'date = d'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_FIELDS_REQUIRED'];
        yield 'unknown field' => [['daily_fields' => 'salary = pay'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_FIELDS'];
        yield 'sql in extra' => [['daily_extra' => 'q = SELECT * FROM x'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_EXTRA'];
        yield 'paging in extra' => [['daily_extra' => 'page = 2'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_EXTRA'];
        yield 'punch list as daily source' => [['daily_path' => '/iclock/api/transactions/', 'daily_fields' => "emp_code = emp_code\ndate = punch_time"], 'COM_BIOTIME_SETTINGS_DAILY_ERR_BUILTIN'];
        yield 'employee list as leave source' => [['leave_path' => '/personnel/api/employees/', 'leave_fields' => "emp_code = emp_code\nstart = hire_date"], 'COM_BIOTIME_SETTINGS_LEAVE_ERR_BUILTIN'];
        yield 'bad param' => [['daily_param_emp' => 'Emp Code'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_PARAM'];
        yield 'bad status' => [['daily_status_map' => 'Late = tardy'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_STATUS_MAP'];
        yield 'bad leave status' => [['leave_status_map' => 'OK = present'], 'COM_BIOTIME_SETTINGS_LEAVE_ERR_STATUS_MAP'];
        yield 'no separator' => [['leave_fields' => 'no separator'], 'COM_BIOTIME_SETTINGS_LEAVE_ERR_LINE'];
        yield 'match field' => [['match_field' => 'employee id!'], 'COM_BIOTIME_SETTINGS_ERR_MATCH_FIELD'];
        yield 'cache' => [['cache_minutes' => '61'], 'COM_BIOTIME_SETTINGS_ERR_CACHE'];
        yield 'time zone' => [['server_timezone' => 'Nowhere/City'], 'COM_BIOTIME_SETTINGS_ERR_TIMEZONE'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badSources')]
    public function testInvalidSourcesAndSystemValuesAreRefused(array $override, string $key): void
    {
        self::assertSame([$key], $this->errors($this->input($override)));
    }
}
