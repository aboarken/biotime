<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent;
use BiotimePortal\Component\Biotime\Administrator\Identity\Identity;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Shared by the site controllers. The employee always comes from the server-side mapping.
 */
trait IdentityGuardTrait
{
    /** @return bool false when a redirect to the login page was set */
    protected function requireLogin(): bool
    {
        $user = $this->app->getIdentity();

        if ($user !== null && !$user->guest) {
            return true;
        }

        $return = base64_encode(Uri::getInstance()->toString(['path', 'query', 'fragment']));
        $this->app->enqueueMessage(Text::_('COM_BIOTIME_LOGIN_REQUIRED'), 'warning');
        $this->setRedirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));

        return false;
    }

    /**
     * @throws \BiotimePortal\Component\Biotime\Administrator\Api\ZkbException when ZKBioTime is needed and unavailable
     */
    protected function currentIdentity(): Identity
    {
        $user = $this->app->getIdentity();

        return $this->biotime()->getIdentityResolver()->resolve((int) $user->id, (string) $user->email);
    }

    protected function biotime(): BiotimeComponent
    {
        $component = $this->app->bootComponent('com_biotime');

        if (!$component instanceof BiotimeComponent) {
            throw new \LogicException('Unexpected component class');
        }

        return $component;
    }

    /** Portal pages are personal: never cached by the browser, a proxy or Joomla's page cache. */
    protected function noCache(): void
    {
        $this->app->allowCache(false);
        $this->app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
    }
}
