<?php

/**
 * My Dashboard → Statistics (0.10.0): the cards' figures as charts, drawn as inline SVG on the
 * server (no script, no external library). Each mark has a <title> (the browser's tooltip), each
 * chart a text summary for screen readers, and every series is named in a legend with its value.
 *
 *  1. Days so far (donut): present, absent, holiday, weekend, leave; the attendance rate inside.
 *  2. Days per month (stacked columns): present, absent, holiday, leave.
 *  3. Late in and early out per month (grouped columns, minutes).
 *  4. Average check-in and check-out per month (a floating column from check-in to check-out).
 *  5. Attendance rate per month (a small card per month, 1.0.0).
 *  6. Devices used, punches split by fingerprint and face (1.0.0).
 *
 * Colours: the portal's tones (validated together for colour-blind separation): green present,
 * red absent, purple holiday, blue weekend, amber leave and late in, rose early out, teal check-in,
 * indigo check-out (and fingerprint / face on the devices); the monthly rates green, amber or red
 * by their value, which is always written.
 *
 * @license GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use BiotimePortal\Component\Biotime\Administrator\Attendance\ChartData;
use Joomla\CMS\Language\Text;

/**
 * @var array{days: list<\BiotimePortal\Component\Biotime\Administrator\Attendance\Day>, today: string, summary: \BiotimePortal\Component\Biotime\Administrator\Attendance\Summary, f: \BiotimePortal\Component\Biotime\Administrator\Support\Formatter, devices?: ?\BiotimePortal\Component\Biotime\Administrator\Attendance\PunchStats} $displayData
 */
$f      = $displayData['f'];
$s      = $displayData['summary'];
$today  = $displayData['today'];
$e      = static fn ($t): string => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
$months = ChartData::months($displayData['days'], $today);
$kinds  = ChartData::breakdown($displayData['days'], $today);

if ($months === []) {
    return;
}

$color = [
    'present' => '#15803d', 'absent' => '#dc2626', 'holiday' => '#7c3aed', 'weekend' => '#3b5998', 'leave' => '#d97706',
    'late' => '#d97706', 'early' => '#be185d', 'in' => '#0d9488', 'out' => '#4f46e5',
];
$label = [
    'present' => Text::_('COM_BIOTIME_STATUS_PRESENT'),
    'absent'  => Text::_('COM_BIOTIME_STATUS_ABSENT'),
    'holiday' => Text::_('COM_BIOTIME_STATUS_HOLIDAY'),
    'weekend' => Text::_('COM_BIOTIME_STATUS_WEEKEND'),
    'leave'   => Text::_('COM_BIOTIME_CHART_LEAVE'),
];
$short = static fn (array $m): string => mb_substr($f->monthName($m['month']), 0, 3) . ($m['month'] === 1 || $m === $months[0] ? ' ' . substr((string) $m['year'], 2) : '');
$long  = static fn (array $m): string => $f->monthName($m['month']) . ' ' . $m['year'];

// A round axis maximum (1, 2, 2.5 or 5 × 10^n) and its gridlines.
$nice = static function (float $max, int $steps = 4): array {
    if ($max <= 0) {
        return [$steps, 1];
    }

    $raw  = $max / $steps;
    $pow  = 10 ** floor(log10($raw));
    $step = $pow * (($raw / $pow) <= 1 ? 1 : (($raw / $pow) <= 2 ? 2 : (($raw / $pow) <= 2.5 ? 2.5 : (($raw / $pow) <= 5 ? 5 : 10))));

    return [$step * $steps, $step];
};

// The plot area shared by the column and line charts (viewBox 0 0 520 240).
[$W, $H, $L, $R, $T, $B] = [520, 240, 44, 10, 14, 30];
$pw  = $W - $L - $R;
$ph  = $H - $T - $B;
$y   = static fn (float $v, float $max): float => round($T + $ph - ($max > 0 ? $v / $max : 0) * $ph, 1);
$band = $pw / \count($months);
$cx  = static fn (int $i): float => round($L + $band * ($i + 0.5), 1);

$grid = static function (float $max, float $step, \Closure $fmt) use ($L, $W, $R, $y, $e): string {
    $out = '';

    for ($v = 0; $v <= $max + 1e-9; $v += $step) {
        $yy   = $y($v, $max);
        $out .= '<line class="bt-chart-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="bt-chart-axis" x="' . ($L - 6) . '" y="' . ($yy + 4) . '" text-anchor="end">' . $e($fmt($v)) . '</text>';
    }

    return $out;
};
$xLabels = static function () use ($months, $cx, $H, $short, $e): string {
    $out = '';

    foreach ($months as $i => $m) {
        $out .= '<text class="bt-chart-axis" x="' . $cx($i) . '" y="' . ($H - 10) . '" text-anchor="middle">' . $e($short($m)) . '</text>';
    }

    return $out;
};
$legend = static function (array $items) use ($e): string {
    $out = '<ul class="bt-chart-legend">';

    foreach ($items as [$c, $text, $value]) {
        $out .= '<li><span class="bt-chart-key" style="background:' . $c . '"></span>' . $e($text)
            . ($value !== null ? ' <strong>' . $e($value) . '</strong>' : '') . '</li>';
    }

    return $out . '</ul>';
};
$hm = static fn (float $min): string => $f->duration((int) round($min));
$clock = static fn (float $min): string => \sprintf('%02d:%02d', intdiv((int) $min, 60), (int) $min % 60);

// 1. Donut: days so far by kind (kinds with 0 days are left out of the ring, kept in the legend).
$total = array_sum($kinds);
$ring  = '';
$circ  = 2 * M_PI * 70;
$start = 0.0;
$shown = array_filter($kinds, static fn (int $n): bool => $n > 0);
$gap   = \count($shown) > 1 ? 2.0 : 0.0;

foreach ($shown as $kind => $n) {
    $len   = $circ * $n / $total;
    $ring .= '<circle class="bt-chart-mark" cx="100" cy="100" r="70" fill="none" stroke="' . $color[$kind] . '" stroke-width="26"'
        . ' stroke-dasharray="' . round(max(0.5, $len - $gap), 2) . ' ' . round($circ, 2) . '" stroke-dashoffset="' . round(-$start, 2) . '"'
        . ' transform="rotate(-90 100 100)"><title>' . $e($label[$kind] . ': ' . Text::plural('COM_BIOTIME_N_DAYS', $n)) . '</title></circle>';
    $start += $len;
}

// 2. Stacked columns: days per month.
$stackKinds = array_values(array_filter(['present', 'absent', 'holiday', 'leave'], static fn (string $k): bool => $kinds[$k] > 0 || $k === 'present' || $k === 'absent'));
[$maxDays, $stepDays] = $nice(max(array_map(static fn (array $m): int => array_sum(array_intersect_key($m['kinds'], array_flip($stackKinds))), $months)));
$bw      = min(34, $band * 0.55);
$stacked = $grid($maxDays, $stepDays, static fn (float $v): string => (string) (int) $v);

foreach ($months as $i => $m) {
    $base = 0;

    foreach ($stackKinds as $k) {
        $n = $m['kinds'][$k];

        if ($n === 0) {
            continue;
        }

        $top = $y($base + $n, $maxDays);
        $h   = max(1, $y($base, $maxDays) - $top - ($base > 0 ? 2 : 0));
        $stacked .= '<rect class="bt-chart-mark" x="' . round($cx($i) - $bw / 2, 1) . '" y="' . $top . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . $color[$k] . '">'
            . '<title>' . $e($long($m) . ' · ' . $label[$k] . ': ' . Text::plural('COM_BIOTIME_N_DAYS', $n)) . '</title></rect>';
        $base += $n;
    }
}

$stacked .= $xLabels();

// 3. Grouped columns: late in and early out minutes per month.
$grouped = '';

[$maxMin, $stepMin] = $nice(max(1, ...array_map(static fn (array $m): int => max($m['summary']->lateMinutes, $m['summary']->earlyMinutes), $months)));
// As wide as the check-in / check-out columns (1.0.1); the two bars of a month side by side.
$gw      = min(30, $band * 0.42);
$grouped = $grid($maxMin, $stepMin, $hm);

foreach ($months as $i => $m) {
    foreach ([['late', $m['summary']->lateMinutes, -1, 'COM_BIOTIME_COL_LATE'], ['early', $m['summary']->earlyMinutes, 1, 'COM_BIOTIME_COL_EARLY']] as [$k, $v, $side, $key]) {
        $top = $y($v, $maxMin);
        $x   = $side < 0 ? $cx($i) - $gw - 1 : $cx($i) + 1;
        $grouped .= '<rect class="bt-chart-mark" x="' . round($x, 1) . '" y="' . $top . '" width="' . round($gw, 1) . '" height="' . max(0, round($T + $ph - $top, 1)) . '" rx="3" fill="' . $color[$k] . '">'
            . '<title>' . $e($long($m) . ' · ' . Text::_($key) . ': ' . $f->duration($v)) . '</title></rect>';
    }
}

$grouped .= $xLabels();

// 4. Lines: average check-in and check-out per month (minutes after midnight).
$series = ['in' => [], 'out' => []];

foreach ($months as $i => $m) {
    $series['in'][$i]  = ChartData::minutes($m['summary']->avgCheckIn);
    $series['out'][$i] = ChartData::minutes($m['summary']->avgCheckOut);
}

$known = array_filter(array_merge($series['in'], $series['out']), static fn (?int $v): bool => $v !== null);
$lines = '';

if ($known !== []) {
    // Whole hours around the times, never before 00:00 or after 24:00.
    $lo   = max(0, (int) (floor((min($known) - 75) / 60) * 60));
    $hi   = min(1440, (int) (ceil((max($known) + 30) / 60) * 60));
    $span = max(60, $hi - $lo);
    $step = $span <= 240 ? 60 : ($span <= 720 ? 120 : 240);
    $hi   = min(1440, $lo + (int) (ceil($span / $step) * $step));
    $lo   = $hi - (int) (ceil($span / $step) * $step);
    $ly   = static fn (int $v): float => round($T + $ph - ($v - $lo) / ($hi - $lo) * $ph, 1);

    for ($v = $lo; $v <= $hi; $v += $step) {
        $lines .= '<line class="bt-chart-grid" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $ly($v) . '" y2="' . $ly($v) . '"/>'
            . '<text class="bt-chart-axis" x="' . ($L - 6) . '" y="' . ($ly($v) + 4) . '" text-anchor="end">' . $clock($v) . '</text>';
    }

    // One floating column per month, from the average check-in (teal cap, bottom) to the average
    // check-out (indigo cap, top): the usual working window. A month with only one of the two
    // shows that cap alone. Times are written beside the caps.
    $fw = min(30, $band * 0.45);

    foreach ($months as $i => $m) {
        [$in, $out] = [$series['in'][$i], $series['out'][$i]];
        $x = round($cx($i) - $fw / 2, 1);
        $tip = $e($long($m) . ' · ' . Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN') . ': ' . ($in !== null ? $clock($in) : '—') . ' · ' . Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT') . ': ' . ($out !== null ? $clock($out) : '—'));

        if ($in !== null && $out !== null && $out > $in) {
            $lines .= '<rect class="bt-chart-mark" x="' . $x . '" y="' . $ly($out) . '" width="' . round($fw, 1) . '" height="' . max(1, round($ly($in) - $ly($out), 1)) . '" rx="4" fill="#e0e7ff"><title>' . $tip . '</title></rect>';
        }

        foreach (['in' => $in, 'out' => $out] as $k => $v) {
            if ($v === null) {
                continue;
            }

            $lines .= '<rect class="bt-chart-mark" x="' . $x . '" y="' . round($ly($v) - 3, 1) . '" width="' . round($fw, 1) . '" height="6" rx="3" fill="' . $color[$k] . '"><title>' . $tip . '</title></rect>'
                . '<text class="bt-chart-value" x="' . $cx($i) . '" y="' . ($k === 'out' && $ly($v) - 8 >= 10 ? round($ly($v) - 8, 1) : round($ly($v) + 16, 1)) . '" text-anchor="middle">' . $clock($v) . '</text>';
        }
    }

    $lines .= $xLabels();
}

// 5. Attendance rate per month: one small card per month (present of working days), coloured
// green from 90 %, amber from 75 %, red below; the percentage is always written.
$rateTone = static fn (?int $r): string => $r === null ? 'muted' : ($r >= 90 ? 'green' : ($r >= 75 ? 'amber' : 'red'));

// 6. Devices used: every device the employee punches on (or is registered on), its punches split
// by fingerprint and face.
$ps      = $displayData['devices'] ?? null;
// Only devices with punches in the school year (1.0.1).
$devices = $ps !== null ? array_values(array_filter($ps->devices, static fn (array $d): bool => (int) $d['total'] > 0)) : [];
$devMax  = max(1, ...array_map(static fn (array $d): int => (int) $d['total'], $devices ?: [['total' => 1]]));

$chart = static fn (string $title, string $summary, string $body, string $legendHtml = '', string $class = ''): string => '<figure class="bt-chart' . $class . '">'
    . '<figcaption class="bt-chart-title">' . $e($title) . '</figcaption>' . $body . $legendHtml
    . '<p class="visually-hidden">' . $e($summary) . '</p></figure>';
?>
<section class="bt-panel" aria-labelledby="bt-stats-title">
    <div class="bt-panel-head">
        <h2 class="bt-panel-title" id="bt-stats-title"><?php echo Text::_('COM_BIOTIME_STATISTICS'); ?></h2>
    </div>
    <div class="bt-charts">
        <?php
        echo $chart(
            Text::_('COM_BIOTIME_CHART_DAYS'),
            implode(', ', array_map(static fn (string $k): string => $label[$k] . ' ' . $kinds[$k], ChartData::KINDS)),
            '<div class="bt-donut"><svg viewBox="0 0 200 200" role="img" aria-hidden="true"><circle cx="100" cy="100" r="70" fill="none" stroke="#eef0f3" stroke-width="26"/>' . $ring
                . '<text class="bt-donut-value" x="100" y="102" text-anchor="middle">' . ($s->rate === null ? '—' : $s->rate . '%') . '</text>'
                . '<text class="bt-donut-label" x="100" y="124" text-anchor="middle">' . $e(Text::_('COM_BIOTIME_CARD_RATE')) . '</text></svg></div>',
            $legend(array_map(static fn (string $k): array => [$color[$k], $label[$k], (string) $kinds[$k]], array_values(array_filter(ChartData::KINDS, static fn (string $k): bool => $kinds[$k] > 0 || \in_array($k, ['present', 'absent'], true))))),
            ' bt-chart--donut'
        );

        echo $chart(
            Text::_('COM_BIOTIME_CHART_DAYS_PER_MONTH'),
            implode('; ', array_map(static fn (array $m): string => $long($m) . ': ' . implode(', ', array_map(static fn (string $k): string => $label[$k] . ' ' . $m['kinds'][$k], $stackKinds)), $months)),
            '<svg class="bt-chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-hidden="true">' . $stacked . '</svg>',
            $legend(array_map(static fn (string $k): array => [$color[$k], $label[$k], null], $stackKinds))
        );

        echo $chart(
            Text::_('COM_BIOTIME_CHART_LATE_EARLY'),
            implode('; ', array_map(static fn (array $m): string => $long($m) . ': ' . Text::_('COM_BIOTIME_COL_LATE') . ' ' . $f->duration($m['summary']->lateMinutes) . ', ' . Text::_('COM_BIOTIME_COL_EARLY') . ' ' . $f->duration($m['summary']->earlyMinutes), $months)),
            '<svg class="bt-chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-hidden="true">' . $grouped . '</svg>',
            $legend([[$color['late'], Text::_('COM_BIOTIME_CARD_LATE_TOTAL'), $f->duration($s->lateMinutes)], [$color['early'], Text::_('COM_BIOTIME_CARD_EARLY_TOTAL'), $f->duration($s->earlyMinutes)]])
        );

        if ($lines !== '') {
            echo $chart(
                Text::_('COM_BIOTIME_CHART_TIMES'),
                implode('; ', array_map(static fn (array $m): string => $long($m) . ': ' . ($m['summary']->avgCheckIn ?? '—') . ' – ' . ($m['summary']->avgCheckOut ?? '—'), $months)),
                '<svg class="bt-chart-svg" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-hidden="true">' . $lines . '</svg>',
                $legend([[$color['in'], Text::_('COM_BIOTIME_CARD_AVG_CHECK_IN'), $f->time($s->avgCheckIn)], [$color['out'], Text::_('COM_BIOTIME_CARD_AVG_CHECK_OUT'), $f->time($s->avgCheckOut)]])
            );
        }

        $tiles = '<div class="bt-rate-months">';

        foreach ($months as $m) {
            $r      = $m['summary']->rate;
            $tiles .= '<div class="bt-rate-month bt-tone-' . $rateTone($r) . '" title="' . $e($long($m) . ': ' . Text::_('COM_BIOTIME_CARD_RATE') . ' ' . ($r === null ? '—' : $r . '%')) . '">'
                . '<span class="bt-rate-name">' . $e($short($m)) . '</span>'
                . '<span class="bt-rate-value">' . ($r === null ? '—' : $r . '<small>%</small>') . '</span>'
                . '<span class="bt-rate-track"><span style="width:' . (int) ($r ?? 0) . '%"></span></span>'
                . '<span class="bt-rate-note">' . $e(Text::sprintf('COM_BIOTIME_CHART_PRESENT_OF', $m['summary']->presentDays, $m['summary']->workingDays)) . '</span></div>';
        }

        echo $chart(
            Text::_('COM_BIOTIME_CHART_RATE_PER_MONTH'),
            implode('; ', array_map(static fn (array $m): string => $long($m) . ': ' . ($m['summary']->rate ?? '—') . '%', $months)),
            $tiles . '</div>'
        );

        $rows = '<div class="bt-devices">';

        foreach ($devices as $d) {
            $tip   = $d['device'] . ': ' . Text::_('COM_BIOTIME_VERIFY_FINGERPRINT') . ' ' . (int) $d['fingerprint'] . ', ' . Text::_('COM_BIOTIME_VERIFY_FACE') . ' ' . (int) $d['face'] . ((int) $d['other'] > 0 ? ', ' . Text::_('COM_BIOTIME_VERIFY_OTHER') . ' ' . (int) $d['other'] : '');
            $rows .= '<div class="bt-device" title="' . $e($tip) . '"><span class="bt-device-name">' . $e($d['device']) . '</span><span class="bt-device-track">';

            foreach ([['fingerprint', $color['in']], ['face', $color['out']], ['other', '#64748b']] as [$k, $c]) {
                if ((int) $d[$k] > 0) {
                    $rows .= '<span style="width:' . round(100 * $d[$k] / $devMax, 1) . '%;background:' . $c . '"></span>';
                }
            }

            $rows .= '</span><span class="bt-device-total">' . (int) $d['total'] . '</span></div>';
        }

        $rows .= $devices === [] ? '<p class="bt-empty">' . Text::_('COM_BIOTIME_DEVICES_USED_NONE') . '</p>' : '';
        $other = $ps !== null && (int) $ps->total['other'] > 0;

        echo $chart(
            Text::_('COM_BIOTIME_DEVICES_USED'),
            implode('; ', array_map(static fn (array $d): string => $d['device'] . ' ' . (int) $d['total'], $devices)),
            $rows . '</div>',
            $devices === [] ? '' : $legend(array_merge(
                [[$color['in'], Text::_('COM_BIOTIME_VERIFY_FINGERPRINT'), (string) (int) ($ps->total['fingerprint'] ?? 0)], [$color['out'], Text::_('COM_BIOTIME_VERIFY_FACE'), (string) (int) ($ps->total['face'] ?? 0)]],
                $other ? [['#64748b', Text::_('COM_BIOTIME_VERIFY_OTHER'), (string) (int) $ps->total['other']]] : []
            ))
        );
        ?>
    </div>
</section>
