<?php

/**
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\CMS\Extension\Service\Provider\RouterFactory;
use Joomla\CMS\Component\Router\RouterFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use BiotimePortal\Component\Biotime\Administrator\Attendance\AttendanceService;
use BiotimePortal\Component\Biotime\Administrator\Audit\AuditLogInterface;
use BiotimePortal\Component\Biotime\Administrator\Audit\DbAuditLog;
use BiotimePortal\Component\Biotime\Administrator\Cache\StoredUserCache;
use BiotimePortal\Component\Biotime\Administrator\Cache\UserCacheInterface;
use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Identity\DbUserFieldReader;
use BiotimePortal\Component\Biotime\Administrator\Identity\DbUserMapRepository;
use BiotimePortal\Component\Biotime\Administrator\Identity\IdentityResolver;
use BiotimePortal\Component\Biotime\Administrator\Identity\MappingManager;
use BiotimePortal\Component\Biotime\Administrator\Identity\UserMapRepositoryInterface;
use BiotimePortal\Component\Biotime\Administrator\Log\JoomlaLogger;
use BiotimePortal\Component\Biotime\Administrator\Security\RateLimiter;
use BiotimePortal\Component\Biotime\Administrator\Security\SecretProvider;
use BiotimePortal\Component\Biotime\Administrator\Service\ZkbClientFactory;
use BiotimePortal\Component\Biotime\Administrator\Settings\SettingsRepository;

return new class () implements ServiceProviderInterface {
    private const CACHE_SESSION_KEY = 'com_biotime.cache';


    public function register(Container $container): void
    {
        $namespace = '\\BiotimePortal\\Component\\Biotime';

        $container->registerServiceProvider(new MVCFactory($namespace));
        $container->registerServiceProvider(new ComponentDispatcherFactory($namespace));
        $container->registerServiceProvider(new RouterFactory($namespace));

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

        $client = static fn (Container $c): \Closure => static fn () => $c->get(ZkbClientFactory::class)->create();

        $container->share(
            UserMapRepositoryInterface::class,
            static fn (Container $c): UserMapRepositoryInterface => new DbUserMapRepository($c->get(DatabaseInterface::class))
        );

        $container->share(
            IdentityResolver::class,
            static fn (Container $c): IdentityResolver => new IdentityResolver(
                $c->get(UserMapRepositoryInterface::class),
                $client($c),
                $c->get(AuditLogInterface::class),
                $c->get(JoomlaLogger::class),
                $c->get(SettingsRepository::class)->load()->autoMatch(),
                null,
                new DbUserFieldReader($c->get(DatabaseInterface::class), $c->get(SettingsRepository::class)->load()->matchField())
            )
        );

        $container->share(
            MappingManager::class,
            static fn (Container $c): MappingManager => new MappingManager(
                $c->get(UserMapRepositoryInterface::class),
                $client($c),
                $c->get(AuditLogInterface::class)
            )
        );

        // Per-user cache in the user's own session: never shared, gone at logout.
        $container->share(
            UserCacheInterface::class,
            static fn (Container $c): UserCacheInterface => new StoredUserCache(
                static fn (): array => (array) Factory::getApplication()->getSession()->get(self::CACHE_SESSION_KEY, []),
                static fn (array $data) => Factory::getApplication()->getSession()->set(self::CACHE_SESSION_KEY, $data),
                $c->get(SettingsRepository::class)->load()->cacheTtl()
            )
        );

        // The ZKBioTime server's time zone: times are shown as recorded, never shifted.
        $zone = static function (Container $c): \DateTimeZone {
            $zone = $c->get(SettingsRepository::class)->load()->serverTimezone() ?: (string) Factory::getApplication()->get('offset', 'UTC');

            try {
                return new \DateTimeZone($zone);
            } catch (\Exception) {
                return new \DateTimeZone('UTC');
            }
        };

        $container->share(
            AttendanceService::class,
            static function (Container $c) use ($client, $zone): AttendanceService {
                $settings = $c->get(SettingsRepository::class)->load();

                return new AttendanceService(
                    $client($c),
                    $settings->source('daily'),
                    $settings->source('leave'),
                    $settings->moduleEnabled('leave'),
                    $c->get(UserCacheInterface::class),
                    $c->get(JoomlaLogger::class),
                    $zone($c),
                    null,
                    $settings->weekendDays()
                );
            }
        );

        $container->set(
            ComponentInterface::class,
            static function (Container $c): BiotimeComponent {
                $component = new BiotimeComponent($c->get(ComponentDispatcherFactoryInterface::class));
                $component->setMVCFactory($c->get(MVCFactoryInterface::class));
                $component->setRouterFactory($c->get(RouterFactoryInterface::class));
                $component->setServiceContainer($c);

                return $component;
            }
        );
    }
};
