<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Audit\DbAuditLog;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Log\JoomlaLogger;
use BiotimePortal\Component\Biotime\Administrator\Security\RateLimiter;
use BiotimePortal\Component\Biotime\Administrator\Security\SecretProvider;
use BiotimePortal\Component\Biotime\Administrator\Service\ZkbClientFactory;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsRepository;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $namespace = '\\BiotimePortal\\Component\\Biotime';

        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));

        $container->share(
            SettingsRepository::class,
            static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            JoomlaLogger::class,
            static fn (Container $c): JoomlaLogger => new JoomlaLogger($c->get(SettingsRepository::class)->load()->detailedLog())
        );

        $container->share(
            SecretProvider::class,
            static fn (): SecretProvider => new SecretProvider((string) Factory::getApplication()->get('secret', ''))
        );

        $container->share(
            ZkbClientFactory::class,
            static fn (Container $c): ZkbClientFactory => new ZkbClientFactory(
                $c->get(SettingsRepository::class),
                $c->get(SecretProvider::class),
                $c->get(JoomlaLogger::class)
            )
        );

        $container->share(
            DbAuditLog::class,
            static fn (Container $c): DbAuditLog => new DbAuditLog($c->get(DatabaseInterface::class), $c->get(JoomlaLogger::class))
        );
        $container->alias(AuditLogInterface::class, DbAuditLog::class);

        $container->share(RateLimiter::class, static fn (Container $c): RateLimiter => new RateLimiter($c->get(DbAuditLog::class)));

        $container->set(
            ComponentInterface::class,
            static function (Container $c): BiotimeComponent {
                $component = new BiotimeComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setServiceContainer($c);

                return $component;
            }
        );
    }
};
