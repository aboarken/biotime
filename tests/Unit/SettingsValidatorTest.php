<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVault;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVaultException;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\Settings;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\SettingsValidator;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\ValidationFailed;

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
            'server_url'      => 'https://biotime.example.com',
            'server_port'     => '8443',
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

        self::assertSame('https://biotime.example.com', $values['server_url']);
        self::assertSame('8443', $values['server_port']);
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

        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_JWT_CREDENTIALS'], $this->errors($this->input(['api_password' => '', 'clear_password' => '1']), $current));
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
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_TOKEN_CREDENTIALS'], $this->errors($this->input(['auth_method' => 'token', 'api_password' => ''])));
    }

    public function testRulesAreCheckedAndAllErrorsCollected(): void
    {
        $errors = $this->errors($this->input([
            'server_url'      => 'ftp://x',
            'server_port'     => '99999',
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
            'COM_ZKBIOTIME_SETTINGS_ERR_PORT', 'COM_ZKBIOTIME_SETTINGS_ERR_URL', 'COM_ZKBIOTIME_SETTINGS_ERR_METHOD',
            'COM_ZKBIOTIME_SETTINGS_ERR_USERNAME', 'COM_ZKBIOTIME_SETTINGS_ERR_TIMEOUTS', 'COM_ZKBIOTIME_SETTINGS_ERR_PIN',
            'COM_ZKBIOTIME_SETTINGS_ERR_PAGE_SIZE', 'COM_ZKBIOTIME_SETTINGS_ERR_MAX_PAGES',
        ] as $key) {
            self::assertContains($key, $errors);
        }
    }

    public function testSmallerRules(): void
    {
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_URL_REQUIRED'], $this->errors($this->input(['server_url' => ''])));
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_PORT'], $this->errors($this->input(['server_url' => 'https://x.example:8081', 'server_port' => '8082'])));
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_TIMEOUT_RANGE'], $this->errors($this->input(['timeout' => '0'])));
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_PASSWORD'], $this->errors($this->input(['api_password' => "a\nb"])));
        self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_TOKEN'], $this->errors($this->input(['auth_method' => 'token', 'api_token' => 'has spaces in it 1234'])));
        self::assertSame(
            ['COM_ZKBIOTIME_SETTINGS_ERR_PIN_HTTPS'],
            $this->errors($this->input(['server_url' => 'http://10.0.0.5', 'server_port' => '', 'tls_pin' => 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']))
        );
    }

    public function testMissingCaBundleIsRefused(): void
    {
        try {
            $this->validator(static fn (): bool => false)->validate($this->input(['ca_bundle' => '/etc/ssl/missing.pem']), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['COM_ZKBIOTIME_SETTINGS_ERR_CA_BUNDLE'], $e->getKeys());
        }
    }

    public function testNoEncryptionSecretIsReported(): void
    {
        $validator = new SettingsValidator(static fn (): string => throw new KeyVaultException('none'), static fn (): bool => true);

        try {
            $validator->validate($this->input(), new Settings());
            self::fail('Expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertContains('COM_ZKBIOTIME_SETTINGS_ERR_NO_SECRET', $e->getKeys());
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
        $settings = new Settings(['timeout' => '9999', 'connect_timeout' => '0', 'page_size' => '-3', 'max_pages' => '', 'server_port' => '99999', 'auth_method' => 'x']);

        self::assertSame(300, $settings->timeout());
        self::assertSame(1, $settings->connectTimeout());
        self::assertSame(1, $settings->pageSize());
        self::assertSame(50, $settings->maxPages());
        self::assertSame(0, $settings->serverPort());
        self::assertSame('jwt', $settings->authMethod());
    }
}
