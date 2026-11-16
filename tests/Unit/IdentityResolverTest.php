<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Tests\Unit;

use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingException;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping;
use BiotimePortal\Component\Biotime\Tests\Support\ArrayLogger;
use BiotimePortal\Component\Biotime\Tests\Support\Fakes;
use BiotimePortal\Component\Biotime\Tests\Support\FixedFieldReader;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryAudit;
use BiotimePortal\Component\Biotime\Tests\Support\MemoryUserMapRepository;
use BiotimePortal\Component\Biotime\Tests\Support\RoutingTransport;
use PHPUnit\Framework\TestCase;

/**
 * Employee matching: by the Joomla "employeeid" field first, then e-mail; single, ambiguous, none;
 * duplicates; suspension when the field or e-mail changes; daily re-verification.
 */
final class IdentityResolverTest extends TestCase
{
    private RoutingTransport $zkb;

    private MemoryUserMapRepository $repo;

    private MemoryAudit $audit;

    private FixedFieldReader $fields;

    private \DateTimeImmutable $now;

    /** @var list<array<string, mixed>> */
    private array $employees = [
        ['id' => 11, 'emp_code' => 'E1', 'email' => 'Alice@Example.com', 'first_name' => 'Alice'],
        ['id' => 12, 'emp_code' => 'E10', 'email' => 'alice.b@example.com', 'first_name' => 'Alicia'],
        ['id' => 13, 'emp_code' => 'E2', 'email' => 'shared@example.com', 'first_name' => 'Bob'],
        ['id' => 14, 'emp_code' => 'E3', 'email' => 'shared@example.com', 'first_name' => 'Carl'],
        ['id' => 15, 'emp_code' => 'E4', 'email' => '', 'first_name' => 'Dina'],
    ];

    protected function setUp(): void
    {
        $this->zkb    = new RoutingTransport();
        $this->repo   = new MemoryUserMapRepository();
        $this->audit  = new MemoryAudit();
        $this->fields = new FixedFieldReader();
        $this->now    = new \DateTimeImmutable('2026-11-15 10:00:00', new \DateTimeZone('UTC'));

        // The employee filter answers partial matches, like many search APIs: E1 also finds E10.
        $this->zkb->list('/personnel/api/employees/', $this->employees, static fn (array $e, array $q): bool => !isset($q['emp_code']) || str_starts_with($e['emp_code'], $q['emp_code']));
        $this->zkb->on('/personnel/api/employees/11/', fn () => [200, $this->employees[0]]);
    }

    private function resolver(bool $autoMatch = true): IdentityResolver
    {
        return new IdentityResolver($this->repo, fn () => Fakes::client($this->zkb), $this->audit, new ArrayLogger(), $autoMatch, fn () => $this->now, $this->fields);
    }

    public function testFieldCodeMapsExactlyOneEmployeeEvenWhenTheFilterReturnsPartialMatches(): void
    {
        $this->fields->values[5] = 'E1';

        $identity = $this->resolver()->resolve(5, 'someone@else.com');

        self::assertTrue($identity->isMatched());
        self::assertSame('11', $identity->employeeId());
        self::assertSame('E1', $identity->empCode());
        self::assertEquals(['emp_code' => 'E1', 'page' => '1', 'page_size' => '100'], $this->zkb->queries('/personnel/api/employees/')[0]);
        self::assertStringContainsString('by_field', $this->audit->records[0]['detail']);
    }

    public function testUnknownFieldCodeIsNotFoundAndNeverFallsBackToEmail(): void
    {
        $this->fields->values[5] = 'E99';

        $identity = $this->resolver()->resolve(5, 'alice@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $identity->status);
        self::assertSame('E99', $this->repo->find(5)?->empCode, 'the tried code is kept, so a corrected field is retried at once');
    }

    public function testEmailMatchIsExactAndCaseInsensitive(): void
    {
        $identity = $this->resolver()->resolve(5, ' ALICE@example.COM ');

        self::assertSame('11', $identity->employeeId());
        self::assertSame(UserMapping::SOURCE_AUTO, $this->repo->find(5)?->source);
    }

    public function testSharedEmailIsAmbiguous(): void
    {
        $identity = $this->resolver()->resolve(5, 'shared@example.com');

        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $identity->status);
        self::assertFalse($identity->isMatched());
    }

    public function testNoMatchIsNotFound(): void
    {
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->resolver()->resolve(5, 'nobody@example.com')->status);
        self::assertSame(UserMapping::STATUS_NOT_FOUND, $this->resolver()->resolve(6, 'not-an-email')->status);
    }

    public function testEmailMatchingCanBeTurnedOff(): void
    {
        $identity = $this->resolver(false)->resolve(5, 'alice@example.com');

        self::assertFalse($identity->isMatched());
        self::assertSame([], $this->zkb->calls, 'no API call');
    }

    public function testOneEmployeeIsNeverMappedToTwoUsers(): void
    {
        $this->fields->values[5] = 'E1';
        $this->fields->values[6] = 'E1';

        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        $second = $this->resolver()->resolve(6, 'b@x.com');

        self::assertSame(UserMapping::STATUS_AMBIGUOUS, $second->status);
        self::assertSame(5, $this->repo->findByEmployeeId('11')?->joomlaUserId);
    }

    public function testActiveMappingNeedsNoApiCall(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');
        $calls = \count($this->zkb->calls);

        $identity = $this->resolver()->resolve(5, 'a@x.com');

        self::assertTrue($identity->isMatched());
        self::assertCount($calls, $this->zkb->calls);
    }

    public function testChangedFieldSuspendsTheMapping(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->fields->values[5] = 'E2';
        $identity = $this->resolver()->resolve(5, 'a@x.com');

        self::assertSame(UserMapping::STATUS_SUSPENDED, $identity->status);
        self::assertContains('identity.suspended', $this->audit->actions());
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'a@x.com')->status, 'stays suspended');
    }

    public function testChangedEmailSuspendsAnEmailMappingButNotAFieldMapping(): void
    {
        $this->resolver()->resolve(5, 'alice@example.com');
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'new@example.com')->status);

        $this->fields->values[6] = 'E2';
        $this->resolver()->resolve(6, 'old@example.com');
        self::assertTrue($this->resolver()->resolve(6, 'new@example.com')->isMatched());
    }

    public function testNotFoundIsRetriedOnlyAfterTheRecheckTimeUnlessTheFieldChanged(): void
    {
        $this->fields->values[5] = 'E99';
        $this->resolver()->resolve(5, 'a@x.com');
        $calls = \count($this->zkb->calls);

        $this->resolver()->resolve(5, 'a@x.com');
        self::assertCount($calls, $this->zkb->calls, 'reused within the recheck time');

        $this->fields->values[5] = 'E1';
        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched(), 'a corrected field is used at once');
    }

    public function testDailyReverificationSuspendsWhenTheEmployeeCodeChanged(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->employees[0]['emp_code'] = 'E1-OLD';
        $this->now = $this->now->modify('+25 hours');

        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->resolver()->resolve(5, 'a@x.com')->status);
    }

    /** A failed daily re-verification is not retried on every page load (each try waits for ZKBioTime). */
    public function testFailedReverificationIsRetriedAfterAnHourNotOnEveryPage(): void
    {
        $this->fields->values[5] = 'E1';
        $this->resolver()->resolve(5, 'a@x.com');

        $this->zkb->on('/personnel/api/employees/11/', static fn () => [503, ['detail' => 'down']]);
        $this->now = $this->now->modify('+25 hours');
        $tries     = fn (): int => \count($this->zkb->queries('/personnel/api/employees/11/'));

        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched(), 'the verified mapping is kept');
        $after = $tries();
        self::assertGreaterThan(0, $after);

        $failedAt = $this->now;

        foreach ([1, 30, 59] as $minutes) {
            $this->now = $failedAt->modify("+{$minutes} minutes");
            self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        }

        self::assertSame($after, $tries(), 'no new attempt within the hour');

        $this->zkb->on('/personnel/api/employees/11/', fn () => [200, $this->employees[0]]);
        $this->now = $failedAt->modify('+61 minutes');
        self::assertTrue($this->resolver()->resolve(5, 'a@x.com')->isMatched());
        self::assertGreaterThan($after, $tries(), 'tried again after an hour');

        $before    = $tries();
        $this->now = $this->now->modify('+2 hours');
        $this->resolver()->resolve(5, 'a@x.com');
        self::assertSame($before, $tries(), 'verified again: next check in a day');
    }

    public function testAdministratorMapsByExactCodeAndDuplicatesAreRefused(): void
    {
        $manager = new MappingManager($this->repo, fn () => Fakes::client($this->zkb), $this->audit, fn () => $this->now);

        $employee = $manager->assign(5, 'a@x.com', 'E1', 99);
        self::assertSame('Alice', $employee['first_name']);
        self::assertSame(UserMapping::SOURCE_ADMIN, $this->repo->find(5)?->source);
        self::assertSame(99, $this->audit->records[0]['actor']);

        foreach ([[6, 'E1', 'COM_BIOTIME_MAPPING_ERR_TAKEN'], [6, 'E9', 'COM_BIOTIME_MAPPING_ERR_NOT_FOUND'], [6, 'E 1', 'COM_BIOTIME_MAPPING_ERR_CODE'], [0, 'E2', 'COM_BIOTIME_MAPPING_ERR_USER']] as [$user, $code, $key]) {
            try {
                $manager->assign($user, 'b@x.com', $code, 99);
                self::fail('Expected ' . $key);
            } catch (MappingException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }

        $manager->suspend(5, 99);
        self::assertSame(UserMapping::STATUS_SUSPENDED, $this->repo->find(5)?->status);
        $manager->confirm(5, 'a@x.com', 99);
        self::assertTrue($this->repo->find(5)?->isActive());
        $manager->rematch(5, 99);
        self::assertNull($this->repo->find(5));
        self::assertSame(['mapping.assign', 'mapping.assign', 'mapping.suspend', 'mapping.assign', 'mapping.rematch'], array_values(array_filter($this->audit->actions(), static fn ($a) => str_starts_with($a, 'mapping.'))));
    }

    public function testAdministratorMappingIsNotSuspendedByEmailOrField(): void
    {
        (new MappingManager($this->repo, fn () => Fakes::client($this->zkb), $this->audit, fn () => $this->now))->assign(5, 'a@x.com', 'E4', 1);
        $this->fields->values[5] = 'E1';

        self::assertSame('E4', $this->resolver()->resolve(5, 'other@x.com')->empCode());
    }
}
