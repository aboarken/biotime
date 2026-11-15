<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/**
 * Stage 1 (connection and authentication) has no portal pages yet. Guests are sent to the login
 * page; everyone else gets "not found". The six menu item types arrive in later versions.
 */
final class DisplayController extends BaseController
{
    public function display($cachable = false, $urlparams = [])
    {
        $user = $this->app->getIdentity();

        if ($user === null || $user->guest) {
            $return = base64_encode(Uri::getInstance()->toString(['path', 'query', 'fragment']));
            $this->app->enqueueMessage(Text::_('COM_BIOTIME_LOGIN_REQUIRED'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_users&view=login&return=' . $return, false));

            return $this;
        }

        throw new RouteNotFoundException(Text::_('COM_BIOTIME_NOT_AVAILABLE_YET'), 404);
    }
}
