<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZkbPortal\Component\Zkbiotime\Administrator\Audit\AuditCounterInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVault;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\KeyVaultException;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\RateLimiter;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\SecretProvider;

final class SecurityTest extends TestCase
{
    private const SECRET = 'a-joomla-configuration-secret-value';

    public function testEncryptionRoundTripUsesAes256GcmWithRandomNonce(): void
    {
        $vault = new KeyVault(self::SECRET);
        $a     = $vault->encrypt('p4ss', KeyVault::CONTEXT_PASSWORD);
        $b     = $vault->encrypt('p4ss', KeyVault::CONTEXT_PASSWORD);

        self::assertStringStartsWith('v1:', $a);
        self::assertNotSame($a, $b, 'a fresh nonce for every encryption');
        self::assertStringNotContainsString('p4ss', $a);
        self::assertSame('p4ss', $vault->decrypt($a, KeyVault::CONTEXT_PASSWORD));
    }

    public function testCiphertextIsBoundToItsContext(): void
    {
        $vault = new KeyVault(self::SECRET);

        $this->expectException(KeyVaultException::class);
        $vault->decrypt($vault->encrypt('p4ss', KeyVault::CONTEXT_PASSWORD), KeyVault::CONTEXT_STATIC_TOKEN);
    }

    public function testOtherSecretOrTamperingFails(): void
    {
        $cipher = (new KeyVault(self::SECRET))->encrypt('p4ss', KeyVault::CONTEXT_PASSWORD);

        foreach ([
            fn () => (new KeyVault('another-secret-of-enough-length'))->decrypt($cipher, KeyVault::CONTEXT_PASSWORD),
            fn () => (new KeyVault(self::SECRET))->decrypt(substr($cipher, 0, -4) . 'AAAA', KeyVault::CONTEXT_PASSWORD),
            fn () => (new KeyVault(self::SECRET))->decrypt('v0:' . substr($cipher, 3), KeyVault::CONTEXT_PASSWORD),
            fn () => (new KeyVault(self::SECRET))->decrypt('v1:short', KeyVault::CONTEXT_PASSWORD),
            fn () => new KeyVault('short'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Expected KeyVaultException');
            } catch (KeyVaultException) {
            }
        }

        self::assertStringNotContainsString(self::SECRET, print_r(new KeyVault(self::SECRET), true));
    }

    public function testSecretSourcePrefersLongEnvironmentVariable(): void
    {
        $env = str_repeat('e', 32);

        $provider = new SecretProvider(self::SECRET, static fn (string $n): string|false => $n === SecretProvider::ENV_NAME ? $env : false);
        self::assertSame(SecretProvider::SOURCE_ENV, $provider->getSource());
        self::assertSame($env, $provider->getSecret());

        $short = new SecretProvider(self::SECRET, static fn (): string => 'too-short');
        self::assertSame(SecretProvider::SOURCE_JOOMLA, $short->getSource());
        self::assertTrue($short->envTooShort());

        $none = new SecretProvider('', static fn (): bool => false);
        self::assertSame(SecretProvider::SOURCE_NONE, $none->getSource());
        self::assertFalse($none->envTooShort());
        $this->expectException(KeyVaultException::class);
        $none->getSecret();
    }

    public function testRateLimiterCountsTheLastHour(): void
    {
        $counter = new class () implements AuditCounterInterface {
            public int $used = 0;

            public ?\DateTimeImmutable $since = null;

            public function countSince(int $userId, string $action, \DateTimeImmutable $since, array $results): int
            {
                $this->since = $since;

                return $this->used;
            }
        };

        $now     = new \DateTimeImmutable('2026-11-15 12:00:00', new \DateTimeZone('UTC'));
        $limiter = new RateLimiter($counter, static fn (): \DateTimeImmutable => $now);

        $counter->used = 19;
        self::assertTrue($limiter->allow(5, 'settings.test', 20));
        $counter->used = 20;
        self::assertFalse($limiter->allow(5, 'settings.test', 20));
        self::assertSame('2026-11-15 11:00:00', $counter->since?->format('Y-m-d H:i:s'));
    }
}
