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
            'weekend_days'    => ['5', '6'],
            'match_field'     => 'employeeid',
            'auto_match'      => '1',
            'module_charts'   => '1',
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

    public function testWeekendDaysAreStoredAsIsoWeekdays(): void
    {
        self::assertSame('5,6', $this->validator()->validate($this->input(), new Settings())['weekend_days']);
        self::assertSame('6,7', $this->validator()->validate($this->input(['weekend_days' => ['7', '6', '9', 'x']]), new Settings())['weekend_days']);
        self::assertSame('', $this->validator()->validate($this->input(['weekend_days' => []]), new Settings())['weekend_days'], 'no weekend at all is allowed');
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_WEEKEND', $this->errors($this->input(['weekend_days' => ['1', '2', '3', '4', '5', '6', '7']])));
        self::assertSame([5, 6], (new Settings())->weekendDays(), 'default: Friday and Saturday');
        self::assertSame([1, 7], (new Settings(['weekend_days' => '7,1']))->weekendDays());
    }

    public function testStoredPasswordIsNotKeptForANewServerAddress(): void
    {
        $current = new Settings($this->validator()->validate($this->input(), new Settings()));
        $same    = $this->input(['api_password' => '']);

        self::assertSame([], $this->errors($same, $current), 'same address: the stored password is kept');
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_REENTER_PASSWORD', $this->errors(['server_url' => 'https://attacker.example.com'] + $same, $current));
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_REENTER_PASSWORD', $this->errors(['connect_via' => 'attacker.example.com'] + $same, $current));
        self::assertSame([], $this->errors(['server_url' => 'https://zkb2.example.com'] + $this->input(), $current), 'typed again: fine');
    }

    /** @return array<string, string> */
    private function database(array $override = []): array
    {
        return $override + [
            'db_engine'   => 'pgsql',
            'db_server'   => 'DB.Example.com:7496',
            'db_name'     => 'biotime',
            'db_user'     => 'joomla_portal',
            'db_password' => 'db secret 1',
            'db_ssl'      => 'verify',
            'db_ca'       => '/etc/ssl/zkb-db.pem',
        ] + $this->input();
    }

    public function testDatabaseSettingsAreCheckedAndThePasswordEncrypted(): void
    {
        $values = $this->validator()->validate($this->database(), new Settings());

        self::assertSame(['pgsql', 'db.example.com', '7496', 'biotime', 'joomla_portal', 'verify'], [$values['db_engine'], $values['db_host'], $values['db_port'], $values['db_name'], $values['db_user'], $values['db_ssl']]);
        self::assertSame('db secret 1', $this->vault->decrypt($values['db_password_enc'], KeyVault::CONTEXT_DB_PASSWORD));
        self::assertArrayNotHasKey('db_password', $values);

        $config = (new Settings($values))->dbConfig($this->vault);
        self::assertSame('db secret 1', $config->password());
        self::assertStringNotContainsString('db secret 1', $config->dsn(), 'the password is never in the connection string');
        self::assertArrayNotHasKey('db_password_enc', (new Settings($values))->toFormData());

        self::assertSame([], $this->errors(['db_engine' => '', 'db_server' => '', 'db_user' => '', 'db_password' => ''] + $this->database()), 'off: nothing required');
        self::assertSame('db.example.com:7496', (new Settings($values))->toFormData()['db_server'], 'one field in the form');
        self::assertSame(['db.example.com', '1433'], array_values(array_intersect_key($this->validator()->validate(['db_engine' => 'sqlsrv', 'db_server' => 'db.example.com', 'db_ca' => ''] + $this->database(), new Settings()), ['db_host' => 1, 'db_port' => 1])), 'default port of the engine');
        self::assertSame([], $this->errors($this->input()), 'forms without the database fields');
    }

    public function testDatabaseSettingsRefuseUnsafeValues(): void
    {
        $cases = [
            [['db_engine' => 'mysql'], 'COM_BIOTIME_SETTINGS_ERR_DB_ENGINE'],
            [['db_server' => 'db sslmode=disable'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => 'db.example.com:70000'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_server' => 'https://db.example.com'], 'COM_BIOTIME_SETTINGS_ERR_DB_SERVER'],
            [['db_connect_via' => 'fw.example.com:7496'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA'],
            [['db_connect_via' => '203.0.113.10:x'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA'],
            [['db_engine' => 'sqlsrv', 'db_ca' => '', 'db_connect_via' => '203.0.113.10'], 'COM_BIOTIME_SETTINGS_ERR_DB_VIA_SQLSRV'],
            [['db_name' => "biotime';x"], 'COM_BIOTIME_SETTINGS_ERR_DB_NAME'],
            [['db_user' => 'a b'], 'COM_BIOTIME_SETTINGS_ERR_DB_USER'],
            [['db_user' => 'Postgres'], 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER'],
            [['db_user' => 'sa'], 'COM_BIOTIME_SETTINGS_ERR_DB_ADMIN_USER'],
            [['db_ssl' => 'maybe'], 'COM_BIOTIME_SETTINGS_ERR_DB_SSL'],
            [['db_ca' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_CA_REQUIRED'],
            [['db_ca' => "/etc/a.pem'; sslmode='disable"], 'COM_BIOTIME_SETTINGS_ERR_DB_CA'],
            [['db_password' => ''], 'COM_BIOTIME_SETTINGS_ERR_DB_PASSWORD_REQUIRED'],
        ];

        foreach ($cases as [$override, $key]) {
            self::assertContains($key, $this->errors($override + $this->database()), json_encode($override));
        }

        self::assertSame([], $this->errors(['db_ssl' => 'require', 'db_ca' => ''] + $this->database()), 'Require needs no CA file');
        self::assertSame([], $this->errors(['db_engine' => 'sqlsrv', 'db_server' => 'db.example.com:1433', 'db_ca' => ''] + $this->database()), 'SQL Server checks against the system certificates');
        self::assertContains('COM_BIOTIME_SETTINGS_ERR_DB_CA', (function (): array {
            try {
                $this->validator(static fn (): bool => false)->validate($this->database(), new Settings());
            } catch (ValidationFailed $e) {
                return $e->getKeys();
            }

            return [];
        })(), 'a CA file that does not exist');
    }

    public function testDatabaseConnectViaIsAnIpAddressAndKeepsTheServerNameForTheCertificate(): void
    {
        $values = $this->validator()->validate(['db_server' => 'zkb-server.example.local:7496', 'db_connect_via' => ' 203.0.113.10:15432 '] + $this->database(), new Settings());

        self::assertSame(['zkb-server.example.local', '7496', '203.0.113.10:15432'], [$values['db_host'], $values['db_port'], $values['db_connect_via']]);

        $dsn = (new Settings($values))->dbConfig($this->vault)->dsn();
        self::assertStringContainsString("host='zkb-server.example.local';port=15432", $dsn, 'certificate checked against the name, connection to the public port');
        self::assertStringContainsString("hostaddr='203.0.113.10'", $dsn);

        $noPort = $this->validator()->validate(['db_connect_via' => '203.0.113.10'] + $this->database(), new Settings());
        self::assertSame('203.0.113.10:7496', $noPort['db_connect_via'], 'without a port: the server\'s port');
        self::assertSame('[2001:db8::5]:7496', $this->validator()->validate(['db_connect_via' => '2001:db8::5'] + $this->database(), new Settings())['db_connect_via']);
        self::assertStringNotContainsString('hostaddr', (new Settings($this->validator()->validate($this->database(), new Settings())))->dbConfig($this->vault)->dsn(), 'empty: direct');
    }

    public function testStoredDatabasePasswordIsNotKeptForAnotherServer(): void
    {
        $current = new Settings($this->validator()->validate($this->database(), new Settings()));
        $same    = $this->database(['db_password' => '']);

        self::assertSame([], $this->errors($same, $current), 'same server: kept');
        self::assertArrayNotHasKey('db_password_enc', $this->validator()->validate($same, $current), 'not overwritten: the stored one stays');

        foreach ([['db_server' => 'attacker.example.com:7496'], ['db_server' => 'db.example.com:5432'], ['db_connect_via' => '198.51.100.7'], ['db_name' => 'other'], ['db_user' => 'someone'], ['db_engine' => 'sqlsrv', 'db_ca' => '']] as $override) {
            self::assertContains('COM_BIOTIME_SETTINGS_ERR_REENTER_DB_PASSWORD', $this->errors($override + $same, $current), json_encode($override));
        }

        self::assertSame([], $this->errors(['db_server' => 'db2.example.com'] + $this->database(), $current), 'typed again: fine');
        self::assertSame('', $this->validator()->validate(['clear_db_password' => '1', 'db_engine' => ''] + $same, $current)['db_password_enc'], 'removed');
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
        self::assertSame('d/m/Y', $settings->dateFormat(), 'default: DD/MM/YYYY');
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
        yield 'employee list as daily source' => [['daily_path' => '/personnel/api/employees/', 'daily_fields' => "emp_code = emp_code\ndate = hire_date"], 'COM_BIOTIME_SETTINGS_DAILY_ERR_BUILTIN'];
        yield 'bad param' => [['daily_param_emp' => 'Emp Code'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_PARAM'];
        yield 'bad status' => [['daily_status_map' => 'Late = tardy'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_STATUS_MAP'];
        yield 'leave is no daily status' => [['daily_status_map' => 'Leave = leave'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_STATUS_MAP'];
        yield 'no separator' => [['daily_fields' => 'no separator'], 'COM_BIOTIME_SETTINGS_DAILY_ERR_LINE'];
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
