<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Profile;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Api\ZkbException;
use BiotimePortal\Component\Biotime\Site\Model\ProfileModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * My Profile: a read-only view of the employee's own ZKBioTime record (allow-listed fields only).
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    /** @var array<string, ?string> */
    public array $profile = [];

    public string $error = '';

    public function display($tpl = null): void
    {
        /** @var ProfileModel $model */
        $model = $this->getModel();

        try {
            $this->profile = $model->profile();
            $state         = 'profile read';
        } catch (ZkbException $e) {
            $this->error = $this->errorText($e->getKind());
            $state       = 'error ' . $e->getKind();
        }

        $this->preparePage($model, 'profile', 'COM_BIOTIME_PROFILE_TITLE');
        $model->logger()->info(\sprintf('Page profile, Joomla user %d: %s', (int) Factory::getApplication()->getIdentity()?->id, $state));

        parent::display($tpl);
    }
}
