<?php

/**
 * Installer script for com_biotime.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Filesystem\File;
use Joomla\Filesystem\Folder;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const MIN_PHP    = '8.3.0';
                private const MIN_JOOMLA = '6.0.0';

                /**
                 * Files and folders shipped by older versions that no longer exist.
                 * Joomla's upgrade does not delete them, so they are removed here.
                 * Paths are relative to the component's site / administrator folder.
                 * Add an entry whenever a release removes a file or feature.
                 */
                private const OBSOLETE_SITE = [];

                private const OBSOLETE_ADMIN = [];

                /** Settings rows of removed options. */
                private const OBSOLETE_SETTINGS = [];

                /** Front-end views that no longer exist; menu items pointing to them are trashed. */
                private const OBSOLETE_VIEWS = [];

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $app = Factory::getApplication();

                    if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_MIN_PHP', self::MIN_PHP), 'error');

                        return false;
                    }

                    if (version_compare(JVERSION, self::MIN_JOOMLA, '<')) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_MIN_JOOMLA', self::MIN_JOOMLA), 'error');

                        return false;
                    }

                    if (!\extension_loaded('openssl') || !\in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
                        $app->enqueueMessage(Text::_('COM_BIOTIME_INSTALL_NO_OPENSSL'), 'error');

                        return false;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'install') {
                        Factory::getApplication()->enqueueMessage(Text::_('COM_BIOTIME_INSTALL_NEXT_STEPS'), 'info');
                    }

                    if ($type !== 'update') {
                        return true;
                    }

                    $removed = $this->removeObsoleteFiles(JPATH_SITE . '/components/com_biotime', self::OBSOLETE_SITE)
                        + $this->removeObsoleteFiles(JPATH_ADMINISTRATOR . '/components/com_biotime', self::OBSOLETE_ADMIN);

                    $db = Factory::getContainer()->get(DatabaseInterface::class);

                    $trashed = $this->trashObsoleteMenuItems($db);
                    $this->mergeServerPort($db);
                    $this->deleteObsoleteSettings($db);

                    $app = Factory::getApplication();

                    if ($removed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_CLEANED_FILES', $removed), 'info');
                    }

                    if ($trashed > 0) {
                        $app->enqueueMessage(Text::sprintf('COM_BIOTIME_INSTALL_TRASHED_MENU', $trashed), 'warning');
                    }

                    return true;
                }

                /**
                 * @param  list<string>  $paths
                 */
                private function removeObsoleteFiles(string $base, array $paths): int
                {
                    $count = 0;

                    foreach ($paths as $relative) {
                        $path = $base . '/' . $relative;

                        try {
                            if (is_dir($path)) {
                                $count += Folder::delete($path) ? 1 : 0;
                            } elseif (is_file($path)) {
                                $count += File::delete($path) ? 1 : 0;
                            }
                        } catch (\Throwable) {
                            // Leave it; the files are unused and harmless.
                        }
                    }

                    return $count;
                }

                /**
                 * Menu items for removed views would lead to an error page. They are moved to the
                 * trash (not deleted), so an admin can still restore or review them.
                 */
                private function trashObsoleteMenuItems(DatabaseInterface $db): int
                {
                    $count = 0;

                    foreach (self::OBSOLETE_VIEWS as $view) {
                        $link  = 'index.php?option=com_biotime&view=' . $view;
                        $like  = $link . '&%';
                        $query = $db->createQuery()
                            ->update($db->quoteName('#__menu'))
                            ->set($db->quoteName('published') . ' = -2')
                            ->where($db->quoteName('client_id') . ' = 0')
                            ->where($db->quoteName('published') . ' <> -2')
                            ->where('(' . $db->quoteName('link') . ' = :link OR ' . $db->quoteName('link') . ' LIKE :like)')
                            ->bind(':link', $link)
                            ->bind(':like', $like);

                        try {
                            $db->setQuery($query)->execute();
                            $count += (int) $db->getAffectedRows();
                        } catch (\Throwable) {
                            // Not critical.
                        }
                    }

                    return $count;
                }

                /**
                 * 0.2.0: the port is part of the server URL. A port stored separately by 0.1.x is
                 * moved into the URL (https://host:5555) and the old row removed.
                 */
                private function mergeServerPort(DatabaseInterface $db): void
                {
                    try {
                        $rows = $db->setQuery(
                            $db->createQuery()
                                ->select($db->quoteName(['name', 'value']))
                                ->from($db->quoteName('#__biotime_settings'))
                                ->whereIn($db->quoteName('name'), ['server_url', 'server_port'], ParameterType::STRING)
                        )->loadAssocList('name', 'value');

                        $url  = trim((string) ($rows['server_url'] ?? ''));
                        $port = (int) ($rows['server_port'] ?? 0);

                        if ($port < 1 || $port > 65535) {
                            return;
                        }

                        $parts = parse_url($url);

                        if ($url !== '' && \is_array($parts) && !isset($parts['port']) && !empty($parts['scheme']) && !empty($parts['host'])) {
                            $default = strtolower($parts['scheme']) === 'https' ? 443 : 80;
                            $merged  = $parts['scheme'] . '://' . $parts['host'] . ($port !== $default ? ':' . $port : '') . rtrim($parts['path'] ?? '', '/');

                            $db->setQuery(
                                $db->createQuery()
                                    ->update($db->quoteName('#__biotime_settings'))
                                    ->set($db->quoteName('value') . ' = :url')
                                    ->where($db->quoteName('name') . ' = ' . $db->quote('server_url'))
                                    ->bind(':url', $merged)
                            )->execute();
                        }

                        $db->setQuery(
                            $db->createQuery()
                                ->delete($db->quoteName('#__biotime_settings'))
                                ->where($db->quoteName('name') . ' = ' . $db->quote('server_port'))
                        )->execute();
                    } catch (\Throwable) {
                        // Not critical: Settings::serverUrl() merges the old port at run time too.
                    }
                }

                private function deleteObsoleteSettings(DatabaseInterface $db): void
                {
                    if (self::OBSOLETE_SETTINGS === []) {
                        return;
                    }

                    $query = $db->createQuery()
                        ->delete($db->quoteName('#__biotime_settings'))
                        ->whereIn($db->quoteName('name'), self::OBSOLETE_SETTINGS, ParameterType::STRING);

                    try {
                        $db->setQuery($query)->execute();
                    } catch (\Throwable) {
                        // Not critical: unknown settings rows are ignored by the component.
                    }
                }
            }
        );
    }
};
