<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Exception\RouteNotFoundException;

/**
 * Every portal page passes through here:
 *  1. login required;
 *  2. a known view and its Joomla permission (403 otherwise);
 *  3. a matched ZKBioTime employee (otherwise only the "could not be matched" page).
 */
final class DisplayController extends BaseController
{
    use IdentityGuardTrait;

    /** view => required permission; a list means any one of them. */
    public const VIEWS = [
        'dashboard'  => 'zkb.dashboard.view',
        'attendance' => 'zkb.attendance.view',
        'calendar'   => 'zkb.calendar.view',
        'punches'    => 'zkb.punches.view',
        'day'        => ['zkb.attendance.view', 'zkb.calendar.view', 'zkb.dashboard.view'],
    ];

    protected $default_view = 'dashboard';

    public function display($cachable = false, $urlparams = [])
    {
        $this->noCache();

        // Before 0.4.6 the saved ZKBioTime data lived in the session; it is now in its own table.
        // Remove this clean-up in 0.6, once every session from before 0.4.6 has expired.
        $session = $this->app->getSession();

        if ($session->has('com_biotime.cache')) {
            $session->remove('com_biotime.cache');
        }

        if (!$this->requireLogin()) {
            return $this;
        }

        $view = $this->input->getCmd('view', $this->default_view);

        if (!isset(self::VIEWS[$view])) {
            throw new RouteNotFoundException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $user    = $this->app->getIdentity();
        $allowed = array_filter((array) self::VIEWS[$view], static fn (string $a): bool => $user->authorise($a, 'com_biotime'));

        if ($allowed === []) {
            throw new NotAllowed(Text::_('COM_BIOTIME_NOT_AUTHORISED'), 403);
        }

        try {
            $identity = $this->currentIdentity();
        } catch (SourceException $e) {
            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'unavailable');
            $this->input->set('kind', $e->getKind());

            return parent::display(false, $urlparams);
        }

        if (!$identity->isMatched()) {
            $this->input->set('view', 'unmatched');
            $this->input->set('layout', 'default');
        }

        return parent::display(false, $urlparams);
    }
}
