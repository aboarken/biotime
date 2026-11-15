<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Reports;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Summary;
use BiotimePortal\Component\Biotime\Site\Model\ReportsModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

/**
 * Attendance Reports for a month or a custom range: summary, working days, worked hours, late-in,
 * early-out, absence, overtime, leave and exceptions; PDF and Excel exports.
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public const PAGE_SIZE = 25;

    /** @var array{from: \DateTimeImmutable, to: \DateTimeImmutable, error: ?string, query: array<string, string>, mode: string} */
    public array $range;

    public ?Period $period = null;

    public ?Summary $summary = null;

    public string $rangeError = '';

    public bool $canExport = false;

    /** The user may export, but the administrator turned exports off. */
    public bool $exportsOff = false;

    public bool $leaveOff = false;

    public int $maxDays = 93;

    /** @var list<Day> */
    public array $rows = [];

    public int $page = 1;

    public int $pages = 1;

    /** @var array<string, list<Day>> section => days */
    public array $sections = [];

    public function display($tpl = null): void
    {
        /** @var ReportsModel $model */
        $model = $this->getModel();

        $this->range   = $model->range();
        $this->maxDays = $model->settings()->reportMaxDays();
        $this->canExport  = $model->can('zkb.report.export') && $model->settings()->moduleEnabled('exports');
        $this->exportsOff = $model->can('zkb.report.export') && !$model->settings()->moduleEnabled('exports');
        $this->leaveOff   = !$model->settings()->moduleEnabled('leave');
        [$this->year, $this->month] = [(int) $this->range['from']->format('Y'), (int) $this->range['from']->format('n')];

        if ($this->range['error'] !== null) {
            $this->rangeError = Text::sprintf($this->range['error'], $this->maxDays);
        } else {
            $this->period  = $model->period();
            $this->summary = $this->period->summary();

            $days = $this->period->days;
            $this->sections = [
                'late'       => array_values(array_filter($days, static fn (Day $d): bool => ($d->late ?? 0) > 0 || $d->status === 'late')),
                'early'      => array_values(array_filter($days, static fn (Day $d): bool => ($d->early ?? 0) > 0 || $d->status === 'early')),
                'absent'     => array_values(array_filter($days, static fn (Day $d): bool => $d->status === 'absent')),
                'overtime'   => array_values(array_filter($days, static fn (Day $d): bool => ($d->overtime ?? 0) > 0)),
                'exceptions' => array_values(array_filter($days, static fn (Day $d): bool => $d->exceptions !== [])),
            ];

            $this->pages = max(1, (int) ceil(\count($days) / self::PAGE_SIZE));
            $this->page  = min(max(1, Factory::getApplication()->getInput()->getInt('page', 1)), $this->pages);
            $this->rows  = \array_slice($days, ($this->page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);
        }

        $this->preparePage($model, 'reports', 'COM_BIOTIME_REPORTS_TITLE');

        if ($this->period !== null) {
            $model->logPage('reports', $this->period->from . '..' . $this->period->to);
        }

        parent::display($tpl);
    }

    public function pageUrl(int $page): string
    {
        return $this->url('view=reports&' . http_build_query($this->range['query'] + ($page > 1 ? ['page' => (string) $page] : [])));
    }
}
