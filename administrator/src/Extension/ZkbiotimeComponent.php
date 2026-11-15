<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace ZkbPortal\Component\Zkbiotime\Administrator\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\MVCComponent;
use Psr\Container\ContainerInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Audit\AuditLogInterface;
use ZkbPortal\Component\Zkbiotime\Administrator\Log\JoomlaLogger;
use ZkbPortal\Component\Zkbiotime\Administrator\Security\RateLimiter;
use ZkbPortal\Component\Zkbiotime\Administrator\Service\ZkbClientFactory;
use ZkbPortal\Component\Zkbiotime\Administrator\Settings\SettingsRepository;

/**
 * Component class. Gives controllers and models access to the services registered in
 * services/provider.php.
 */
final class ZkbiotimeComponent extends MVCComponent
{
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
