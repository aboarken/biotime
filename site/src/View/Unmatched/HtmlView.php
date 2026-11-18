<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Unmatched;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Support\SourceException;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Shown when the user has no usable employee mapping (layout default) or ZKBioTime could not be
 * asked (layout unavailable). One friendly message, nothing else.
 */
final class HtmlView extends BaseHtmlView
{
    public string $kind = '';

    /** Matched by e-mail and waiting for an administrator to confirm it (1.0.10). */
    public bool $pending = false;

    public function display($tpl = null): void
    {
        $kind       = Factory::getApplication()->getInput()->getCmd('kind', '');
        $this->kind = \in_array($kind, SourceException::KINDS, true) ? $kind : '';

        $component = Factory::getApplication()->bootComponent('com_biotime');
        $mapping   = $component instanceof \BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent
            ? $component->getUserMapRepository()->find((int) Factory::getApplication()->getIdentity()?->id)
            : null;
        $this->pending = $mapping !== null && $mapping->status === \BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping::STATUS_SUSPENDED
            && $mapping->employeeId !== null && $mapping->source === \BiotimePortal\Component\Biotime\Administrator\Identity\UserMapping::SOURCE_AUTO;

        $document = $this->getDocument();
        $document->setMetaData('robots', 'noindex, nofollow');
        $document->getWebAssetManager()->getRegistry()->addExtensionRegistryFile('com_biotime');
        $document->getWebAssetManager()->useStyle('com_biotime.portal');

        $this->logger()?->info(\sprintf(
            'Page %s, Joomla user %d: %s',
            Factory::getApplication()->getInput()->getCmd('layout', 'default') === 'unavailable' ? 'unavailable' : 'unmatched',
            (int) Factory::getApplication()->getIdentity()?->id,
            $this->kind !== '' ? 'ZKBioTime error ' . $this->kind : 'no active employee mapping'
        ));

        parent::display($tpl);
    }

    private function logger(): ?\Psr\Log\LoggerInterface
    {
        $component = Factory::getApplication()->bootComponent('com_biotime');

        return $component instanceof \BiotimePortal\Component\Biotime\Administrator\Extension\BiotimeComponent ? $component->getLogger() : null;
    }
}
