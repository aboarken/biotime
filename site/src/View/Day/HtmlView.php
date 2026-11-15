<?php

/**
 * @license GNU General Public License version 2 or later
 */

namespace BiotimePortal\Component\Biotime\Site\View\Day;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BiotimePortal\Component\Biotime\Administrator\Attendance\Day;
use BiotimePortal\Component\Biotime\Administrator\Attendance\LeaveRecord;
use BiotimePortal\Component\Biotime\Administrator\Attendance\Period;
use BiotimePortal\Component\Biotime\Site\Model\DayModel;
use BiotimePortal\Component\Biotime\Site\View\PortalViewTrait;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Exception\RouteNotFoundException;

/**
 * Details of one day (sub-page of My Attendance, the Calendar and My Schedules; not a menu item type).
 */
final class HtmlView extends BaseHtmlView
{
    use PortalViewTrait;

    public Day $day;

    public Period $period;

    /** @var list<LeaveRecord> */
    public array $leave = [];

    public bool $canAttendance = false;

    public bool $canCalendar = false;

    public function display($tpl = null): void
    {
        /** @var DayModel $model */
        $model = $this->getModel();
        $day   = $model->day();

        if ($day === null) {
            throw new RouteNotFoundException(Text::_('COM_BIOTIME_DAY_NOT_FOUND'), 404);
        }

        $this->day    = $day;
        $this->period = $model->period();
        $this->leave  = $model->leave();
        [$this->year, $this->month] = $model->selectedMonth();
        $this->canAttendance = $model->can('zkb.attendance.view');
        $this->canCalendar   = $model->can('zkb.calendar.view');

        $this->preparePage($model, 'day', 'COM_BIOTIME_DAY_TITLE');
        $this->pageHeading = Text::sprintf('COM_BIOTIME_DAY_HEADING', $this->f->weekday($day->weekday()), $this->f->date($day->date));
        $this->setDocumentTitle($this->pageHeading);
        $model->logPage('day', $day->date);

        parent::display($tpl);
    }
}
