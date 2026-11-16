<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Profile;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbClient;
use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Psr\Log\LoggerInterface;

/**
 * The logged-in employee's own ZKBioTime record, read only. Only the fields in FIELDS are ever
 * read; everything else in the API answer (passwords, card numbers, biometric data, national ID,
 * device data, ...) is dropped before it is cached or shown.
 */
final class ProfileService
{
    /**
     * Portal field => ZKBioTime employee field (dots for nested objects; "[]" for a list of objects).
     * VERIFIED: BioTime 9.5 API User Manual (16 Aug 2024), 5.1, and the field names seen on the real server (see docs/API-MAPPING.md).
     */
    public const FIELDS = [
        'emp_code'   => 'emp_code',
        'first_name' => 'first_name',
        'last_name'  => 'last_name',
        'department' => 'department.dept_name',
        'position'   => 'position.position_name',
        'area'       => 'area[].area_name',
        'email'      => 'email',
        'hire_date'  => 'hire_date',
    ];

    /**
     * @param \Closure(): ZkbClient $clientFactory
     */
    public function __construct(
        private readonly \Closure $clientFactory,
        private readonly UserCacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array<string, ?string> portal field => text (null when ZKBioTime gave none)
     *
     * @throws ZkbException
     */
    public function profile(Identity $identity): array
    {
        $cached = $this->cache->get($identity, 'profile');

        if ($cached !== null) {
            return $cached;
        }

        $employee = ($this->clientFactory)()->employee($identity->employeeId());

        if ((string) ($employee['emp_code'] ?? '') !== $identity->empCode()) {
            $this->logger->warning(\sprintf('ZKBioTime returned an employee with another code for Joomla user %d; not shown', $identity->joomlaUserId));

            throw new ZkbException(ZkbException::NOT_FOUND, 'Employee record does not match the mapping');
        }

        $profile = self::extract($employee);
        $this->cache->set($identity, 'profile', $profile);

        return $profile;
    }

    /**
     * @param array<mixed> $employee
     *
     * @return array<string, ?string>
     */
    public static function extract(array $employee): array
    {
        $out = [];

        foreach (self::FIELDS as $field => $path) {
            $out[$field] = self::read($employee, $path);
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     */
    private static function read(array $data, string $path): ?string
    {
        if (str_contains($path, '[].')) {
            [$list, $key] = explode('[].', $path, 2);
            $items        = $data[$list] ?? null;

            if (!\is_array($items)) {
                return null;
            }

            if (!array_is_list($items)) {
                $items = [$items];
            }

            $names = array_filter(array_map(static fn ($i) => \is_array($i) ? self::read($i, $key) : null, $items));

            return $names === [] ? null : implode(', ', $names);
        }

        $value = $data;

        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) $value)) ?? '');

        return $text === '' ? null : mb_substr($text, 0, 200);
    }
}
