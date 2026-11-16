<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\Router\RouterServiceInterface;
use Joomla\CMS\Component\Router\RouterServiceTrait;
use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;
use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapRepositoryInterface;
use BiotimePortal\Component\Biotime\Administrator\Log\JoomlaLogger;
use BiotimePortal\Component\Biotime\Administrator\Security\RateLimiter;
use BiotimePortal\Component\Biotime\Administrator\Service\ZkbClientFactory;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsRepository;

/**
 * Component class. Gives controllers and models access to the services registered in
 * services/provider.php.
 */
final class BiotimeComponent extends MVCComponent implements RouterServiceInterface
{
    use RouterServiceTrait;

    private ?ContainerInterface $services = null;

    public function setServiceContainer(ContainerInterface $services): void
    {
        $this->services = $services;
    }

    public function getClientFactory(): ZkbClientFactory
    {
        return $this->service(ZkbClientFactory::class);
    }

    public function getSettingsRepository(): SettingsRepository
    {
        return $this->service(SettingsRepository::class);
    }

    public function getAuditLog(): AuditLogInterface
    {
        return $this->service(AuditLogInterface::class);
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->service(RateLimiter::class);
    }

    public function getIdentityResolver(): IdentityResolver
    {
        return $this->service(IdentityResolver::class);
    }

    public function getMappingManager(): MappingManager
    {
        return $this->service(MappingManager::class);
    }

    public function getUserMapRepository(): UserMapRepositoryInterface
    {
        return $this->service(UserMapRepositoryInterface::class);
    }

    public function getAttendanceService(): AttendanceService
    {
        return $this->service(AttendanceService::class);
    }

    public function getLogger(): JoomlaLogger
    {
        return $this->service(JoomlaLogger::class);
    }

    /**
     * @template T of object
     * @param  class-string<T>  $id
     * @return T
     */
    private function service(string $id): object
    {
        if ($this->services === null) {
            throw new \LogicException('Service container not set');
        }

        return $this->services->get($id);
    }
}
