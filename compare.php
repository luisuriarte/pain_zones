<?php

/**
 * compare.php  -  Pain Zones: read-only visit comparison and zone heat map.
 *
 * Patient-scoped view that reads the active forms of the current patient.
 * Two modes:
 *   - overlay:  pins from two or more visits, one color per visit.
 *   - heatmap:  anatomical zones tinted by a selected metric.
 *
 * Heat metrics (one value per zone and visit, using the highest intensity
 * recorded for that zone in the visit):
 *   - max:   highest per-visit value in the range.
 *   - avg:   average of the per-visit values.
 *   - count: number of distinct visits in which the zone was marked.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/pain_zones.inc.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Core\Header;

// ---------------------------------------------------------------------------
// 1. Access control and patient context
// ---------------------------------------------------------------------------

pz_requireRead();

$pid = (int)PatientSessionUtil::getPid();
if ($pid <= 0) {
    AccessDeniedHelper::denyWithTemplate(
        'No patient selected in session. Please select a patient before comparing pain zones.',
        xlt('Pain Zones')
    );
}

// ---------------------------------------------------------------------------
// 2. Helpers
// ---------------------------------------------------------------------------

/**
 * Parses a strict Y-m-d date. Returns null for missing or invalid values
 * (including impossible dates such as 2026-02-31).
 */
function pz_compareParseDate(?string $value): ?\DateTimeImmutable
{
    if ($value === null || $value === '') {
        return null;
    }

    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        return null;
    }

    return $dt;
}

/**
 * Formats a database date for display, using the OpenEMR locale when available.
 */
function pz_compareFormatDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '';
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    if (function_exists('oeFormatShortDate')) {
        return (string)oeFormatShortDate(date('Y-m-d', $ts));
    }

    return date('Y-m-d', $ts);
}

/**
 * Returns the fill and stroke color for a heat value.
 *
 * @return array{fill: string, stroke: string}
 */
function pz_compareColorForMetric(string $metric, float $value, int $maxVisits): array
{
    if ($metric === 'count') {
        $maxVisits = max(1, $maxVisits);
        $t = ($maxVisits <= 1)
            ? 0.0
            : max(0.0, min(1.0, ($value - 1.0) / ($maxVisits - 1.0)));

        // Sequential scale: light orange (1 visit) -> dark red (max visits).
        $start = [255, 204, 128]; // #FFCC80
        $end   = [183, 28, 28];   // #B71C1C
        $r = (int)round($start[0] + ($end[0] - $start[0]) * $t);
        $g = (int)round($start[1] + ($end[1] - $start[1]) * $t);
        $b = (int)round($start[2] + ($end[2] - $start[2]) * $t);

        return [
            'fill'   => sprintf('#%02X%02X%02X', $r, $g, $b),
            'stroke' => '#7F1D1D',
        ];
    }

    // Max / average use the same 0-10 palette as the encounter form.
    $level   = (int)round(max(0.0, min(10.0, $value)));
    $palette = pz_getLevelColors();

    return $palette['level_' . $level] ?? ['fill' => '#BDBDBD', 'stroke' => '#848484'];
}

/**
 * Parses the SVG returned by pz_getSvgContent().
 */
function pz_compareLoadSvg(string $svg): \DOMDocument
{
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || $doc->documentElement === null) {
        throw new \RuntimeException('Unable to parse the Pain Zones SVG.');
    }

    // Drop every base <title> (Inkscape figure title and RDF metadata copies)
    // so they cannot mask the tooltips we add; new.php strips them the same way.
    $titles = [];
    foreach ($doc->getElementsByTagName('title') as $title) {
        $titles[] = $title;
    }
    foreach ($titles as $title) {
        $title->parentNode?->removeChild($title);
    }

    return $doc;
}

/**
 * Builds an id => element map for the SVG document.
 *
 * @return array<string, \DOMElement>
 */
function pz_compareIdMap(\DOMDocument $doc): array
{
    $map = [];
    $xpath = new \DOMXPath($doc);
    $nodes = $xpath->query('//*[@id]');

    if ($nodes !== false) {
        foreach ($nodes as $node) {
            if ($node instanceof \DOMElement) {
                $id = $node->getAttribute('id');
                if ($id !== '') {
                    $map[$id] = $node;
                }
            }
        }
    }

    return $map;
}

/**
 * Collects the drawable shapes of a zone, stopping at child zones so a parent
 * zone never paints over a more specific one.
 *
 * @param string[] $validZoneIds
 * @return \DOMElement[]
 */
function pz_compareCollectShapes(\DOMElement $node, bool $isRoot, array $validZoneIds): array
{
    $shapes = [];

    if (!$isRoot) {
        $id = $node->getAttribute('id');
        if ($id !== '' && in_array($id, $validZoneIds, true)) {
            return $shapes;
        }
    }

    $tag = strtolower($node->localName ?? $node->tagName);
    if (in_array($tag, ['path', 'polygon', 'rect', 'circle', 'ellipse'], true)) {
        $shapes[] = $node;
    }

    foreach ($node->childNodes as $child) {
        if ($child instanceof \DOMElement) {
            $childShapes = pz_compareCollectShapes($child, false, $validZoneIds);
            if ($childShapes !== []) {
                $shapes = array_merge($shapes, $childShapes);
            }
        }
    }

    return $shapes;
}

/**
 * Tints the marked zones and adds a native SVG tooltip to each one.
 *
 * @param array<string, array{color: array{fill: string, stroke: string}, tooltip: string}> $zoneHeat
 * @param string[] $validZoneIds
 */
function pz_compareApplyHeatToSvg(string $svg, array $zoneHeat, array $validZoneIds): string
{
    $doc   = pz_compareLoadSvg($svg);
    $idMap = pz_compareIdMap($doc);

    foreach ($zoneHeat as $zoneId => $heat) {
        if (!isset($idMap[$zoneId])) {
            continue;
        }

        $zoneEl = $idMap[$zoneId];
        $shapes = pz_compareCollectShapes($zoneEl, true, $validZoneIds);

        foreach ($shapes as $shape) {
            $style = trim($shape->getAttribute('style'));
            $style = rtrim($style, ';');
            $style .= ($style === '' ? '' : ';')
                . 'fill:' . $heat['color']['fill'] . ';fill-opacity:0.6;'
                . 'stroke:' . $heat['color']['stroke'] . ';stroke-width:1.5px;';
            $shape->setAttribute('style', $style);
        }

        $title = $doc->createElementNS('http://www.w3.org/2000/svg', 'title');
        $title->appendChild($doc->createTextNode($heat['tooltip']));
        $zoneEl->insertBefore($title, $zoneEl->firstChild);
    }

    $out = $doc->saveXML($doc->documentElement);

    return ($out === false) ? $svg : $out;
}

/**
 * Adds a pins layer on top of the SVG (one circle + intensity label per mark).
 *
 * @param array<int, array{mark: array<string, mixed>, color: string, tooltip: string}> $pins
 */
function pz_compareAddPinsToSvg(string $svg, array $pins): string
{
    if ($pins === []) {
        return $svg;
    }

    $doc  = pz_compareLoadSvg($svg);
    $root = $doc->documentElement;
    if ($root === null) {
        return $svg;
    }

    $parts = preg_split('/[\s,]+/', trim((string)$root->getAttribute('viewBox'))) ?: [];
    if (count($parts) !== 4) {
        return $svg;
    }

    $vx = (float)$parts[0];
    $vy = (float)$parts[1];
    $vw = (float)$parts[2];
    $vh = (float)$parts[3];
    if ($vw <= 0.0 || $vh <= 0.0) {
        return $svg;
    }

    $radius   = max(10.0, $vh * 0.014);
    $fontSize = max(10.0, $radius * 1.1);
    $svgNs    = 'http://www.w3.org/2000/svg';

    $layer = $doc->createElementNS($svgNs, 'g');
    $layer->setAttribute('id', 'pz-compare-pins');

    foreach ($pins as $pin) {
        $mark = $pin['mark'];
        $cx   = $vx + (((float)$mark['x_pct']) / 100.0) * $vw;
        $cy   = $vy + (((float)$mark['y_pct']) / 100.0) * $vh;

        $group = $doc->createElementNS($svgNs, 'g');

        $title = $doc->createElementNS($svgNs, 'title');
        $title->appendChild($doc->createTextNode($pin['tooltip']));
        $group->appendChild($title);

        $circle = $doc->createElementNS($svgNs, 'circle');
        $circle->setAttribute('cx', (string)round($cx, 3));
        $circle->setAttribute('cy', (string)round($cy, 3));
        $circle->setAttribute('r', (string)round($radius, 3));
        $circle->setAttribute('fill', $pin['color']);
        $circle->setAttribute('stroke', '#212529');
        $circle->setAttribute('stroke-width', (string)round(max(1.5, $radius * 0.12), 3));
        $group->appendChild($circle);

        $text = $doc->createElementNS($svgNs, 'text');
        $text->setAttribute('x', (string)round($cx, 3));
        $text->setAttribute('y', (string)round($cy, 3));
        $text->setAttribute('text-anchor', 'middle');
        $text->setAttribute('dominant-baseline', 'central');
        $text->setAttribute('font-size', (string)round($fontSize, 3));
        $text->setAttribute('font-weight', 'bold');
        $text->setAttribute('font-family', 'sans-serif');
        $text->setAttribute('fill', '#FFFFFF');
        $text->setAttribute('stroke', '#000000');
        $text->setAttribute('stroke-width', '0.5');
        $text->setAttribute('paint-order', 'stroke');
        $text->setAttribute('style', 'pointer-events: none;');
        $text->appendChild($doc->createTextNode((string)(int)$mark['intensity']));
        $group->appendChild($text);

        $layer->appendChild($group);
    }

    $root->appendChild($layer);

    $out = $doc->saveXML($root);

    return ($out === false) ? $svg : $out;
}

// ---------------------------------------------------------------------------
// 3. Request parameters and date range
// ---------------------------------------------------------------------------

$allowedModes   = ['overlay', 'heatmap'];
$allowedRanges  = ['30d', '3m', '6m', '12m', 'all', 'custom'];
$allowedFigures = array_keys(PZ_FIGURE_MAP);
$allowedViews   = ['anterior', 'posterior'];
$allowedMetrics = ['max', 'avg', 'count'];

$mode = (string)($_GET['mode'] ?? 'overlay');
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'overlay';
}

$range = (string)($_GET['range'] ?? '12m');
if (!in_array($range, $allowedRanges, true)) {
    $range = '12m';
}

$metric = (string)($_GET['metric'] ?? 'max');
if (!in_array($metric, $allowedMetrics, true)) {
    $metric = 'max';
}

// Optional "back" target supplied by the form that opened this page. Only a
// same-installation relative path is accepted, to avoid open redirects; the
// compare page falls back to the encounter summary otherwise.
$returnUrl = '';
$rawReturn = isset($_GET['return']) ? (string)$_GET['return'] : '';
if ($rawReturn !== '') {
    $decoded = rawurldecode($rawReturn);
    if (
        !str_contains($decoded, "\n") && !str_contains($decoded, "\r")
        && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $decoded)   // no scheme (http:, javascript:...)
        && !str_starts_with($decoded, '//')                   // no protocol-relative host
        && str_starts_with($decoded, '/')                     // must be a root-relative path
        && !str_contains($decoded, '..')                      // no traversal
    ) {
        $returnUrl = $decoded;
    }
}

$today    = new \DateTimeImmutable('today');
$fromDate = null;
$toDate   = null;

switch ($range) {
    case '30d':
        $fromDate = $today->modify('-30 days')->format('Y-m-d');
        $toDate   = $today->format('Y-m-d');
        break;
    case '3m':
        $fromDate = $today->modify('-3 months')->format('Y-m-d');
        $toDate   = $today->format('Y-m-d');
        break;
    case '6m':
        $fromDate = $today->modify('-6 months')->format('Y-m-d');
        $toDate   = $today->format('Y-m-d');
        break;
    case '12m':
        $fromDate = $today->modify('-12 months')->format('Y-m-d');
        $toDate   = $today->format('Y-m-d');
        break;
    case 'all':
        $fromDate = null;
        $toDate   = null;
        break;
    case 'custom':
        $customFrom = pz_compareParseDate(isset($_GET['from']) ? (string)$_GET['from'] : null);
        $customTo   = pz_compareParseDate(isset($_GET['to']) ? (string)$_GET['to'] : null);

        if ($customFrom === null || $customTo === null) {
            // First time "Custom" is selected: prefill with the default range.
            $customFrom = $today->modify('-12 months');
            $customTo   = $today;
        }

        if ($customFrom > $customTo) {
            [$customFrom, $customTo] = [$customTo, $customFrom];
        }

        $fromDate = $customFrom->format('Y-m-d');
        $toDate   = $customTo->format('Y-m-d');
        break;
}

// ---------------------------------------------------------------------------
// 4. Read the patient's confirmed forms and their active marks
// ---------------------------------------------------------------------------

$visitWhere  = "h.pid = ? AND h.activity = 1 AND h.review_status = 'confirmed'";
$visitParams = [$pid];

if ($fromDate !== null && $toDate !== null) {
    $toExclusive   = (new \DateTimeImmutable($toDate))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $visitWhere   .= ' AND h.date >= ? AND h.date < ?';
    $visitParams[] = $fromDate . ' 00:00:00';
    $visitParams[] = $toExclusive;
}

$visitRows = QueryUtils::fetchRecords(
    "SELECT h.id, h.date
     FROM form_pain_zones h
     WHERE {$visitWhere}
     ORDER BY h.date ASC, h.id ASC",
    $visitParams
);

$markRows = QueryUtils::fetchRecords(
    "SELECT m.form_id, m.figure, m.body_view, m.zone_id, m.laterality,
            m.x_pct, m.y_pct, m.intensity, m.pain_quality
     FROM form_pain_zones_marks m
     INNER JOIN form_pain_zones h ON h.id = m.form_id AND h.pid = m.pid
     WHERE {$visitWhere} AND m.activity = 1
     ORDER BY h.date ASC, m.id ASC",
    $visitParams
);

// ---------------------------------------------------------------------------
// 5. Figure / view defaults (pick the panel with the most data in the range)
// ---------------------------------------------------------------------------

$figureCounts = ['adult_male' => 0, 'adult_female' => 0];
$viewCounts   = [
    'adult_male'   => ['anterior' => 0, 'posterior' => 0],
    'adult_female' => ['anterior' => 0, 'posterior' => 0],
];

foreach ($markRows as $mark) {
    $markFigure = (string)($mark['figure'] ?? '');
    $markView   = (string)($mark['body_view'] ?? '');

    if (isset($figureCounts[$markFigure])) {
        $figureCounts[$markFigure]++;
        if (isset($viewCounts[$markFigure][$markView])) {
            $viewCounts[$markFigure][$markView]++;
        }
    }
}

$figure = (string)($_GET['figure'] ?? '');
if (!in_array($figure, $allowedFigures, true)) {
    $figure = ($figureCounts['adult_female'] > $figureCounts['adult_male'])
        ? 'adult_female'
        : 'adult_male';
}

$view = (string)($_GET['view'] ?? '');
if (!in_array($view, $allowedViews, true)) {
    $view = ($viewCounts[$figure]['posterior'] > $viewCounts[$figure]['anterior'])
        ? 'posterior'
        : 'anterior';
}

// ---------------------------------------------------------------------------
// 6. Group marks by visit and by the selected panel
// ---------------------------------------------------------------------------

$visitDateById = [];
foreach ($visitRows as $row) {
    $visitDateById[(int)$row['id']] = (string)$row['date'];
}

$panelMarksByForm = [];
$panelMarks       = [];

foreach ($markRows as $mark) {
    if ((string)$mark['figure'] !== $figure || (string)$mark['body_view'] !== $view) {
        continue;
    }

    $formId = (int)$mark['form_id'];
    $panelMarksByForm[$formId][] = $mark;
    $panelMarks[] = $mark;
}

$visitStats = [];
foreach ($visitRows as $row) {
    $formId      = (int)$row['id'];
    $marks       = $panelMarksByForm[$formId] ?? [];
    $intensities = array_map(static fn(array $m): int => (int)$m['intensity'], $marks);

    $visitStats[$formId] = [
        'date'  => (string)$row['date'],
        'marks' => count($intensities),
        'max'   => $intensities === [] ? null : max($intensities),
        'avg'   => $intensities === [] ? null : (array_sum($intensities) / count($intensities)),
    ];
}

// ---------------------------------------------------------------------------
// 7. Overlay selection (default: the last three visits in the range)
// ---------------------------------------------------------------------------

$visitIds       = array_map(static fn(array $row): int => (int)$row['id'], $visitRows);
$visitsProvided = isset($_GET['visits_present']);
$selectedMap    = [];

$rawVisits = $_GET['visits'] ?? null;
if (is_array($rawVisits)) {
    foreach ($rawVisits as $rawVisitId) {
        $candidate = (int)$rawVisitId;
        if (in_array($candidate, $visitIds, true)) {
            $selectedMap[$candidate] = true;
        }
    }
}

if ($selectedMap === [] && !$visitsProvided) {
    foreach (array_slice($visitRows, -3) as $row) {
        $selectedMap[(int)$row['id']] = true;
    }
}

if (count($selectedMap) > 5) {
    $selectedMap = [];
    foreach (array_slice($visitRows, -5) as $row) {
        $selectedMap[(int)$row['id']] = true;
    }
}

$selectedVisits = [];
foreach ($visitRows as $row) {
    $formId = (int)$row['id'];
    if (isset($selectedMap[$formId])) {
        $selectedVisits[] = $formId;
    }
}

$visitPalette = ['#0072B2', '#E69F00', '#009E73', '#D55E00', '#CC79A7'];
$visitColors  = [];
foreach ($selectedVisits as $index => $formId) {
    $visitColors[$formId] = $visitPalette[$index % count($visitPalette)];
}

// ---------------------------------------------------------------------------
// 8. Heat aggregation
//    One value per zone and visit (the highest intensity of that zone in the
//    visit), then max / average / distinct-visit count across the range.
// ---------------------------------------------------------------------------

$zoneVisit = [];
foreach ($panelMarks as $mark) {
    $zoneId = (string)($mark['zone_id'] ?? '');
    if ($zoneId === '') {
        continue;
    }

    $formId    = (int)$mark['form_id'];
    $intensity = (int)$mark['intensity'];

    if (!isset($zoneVisit[$zoneId][$formId])) {
        $zoneVisit[$zoneId][$formId] = ['max' => $intensity, 'marks' => 1];
    } else {
        $zoneVisit[$zoneId][$formId]['max']   = max($zoneVisit[$zoneId][$formId]['max'], $intensity);
        $zoneVisit[$zoneId][$formId]['marks']++;
    }
}

$zoneHeat      = [];
$maxZoneVisits = 1;

foreach ($zoneVisit as $zoneId => $perVisit) {
    $visitsCount = count($perVisit);
    $maxValue    = 0;
    $sumVisitMax = 0;
    $totalMarks  = 0;
    $lastVisit   = null;

    foreach ($perVisit as $formId => $data) {
        $maxValue     = max($maxValue, (int)$data['max']);
        $sumVisitMax += (int)$data['max'];
        $totalMarks  += (int)$data['marks'];

        $visitDate = $visitDateById[$formId] ?? null;
        if ($visitDate !== null && ($lastVisit === null || $visitDate > $lastVisit)) {
            $lastVisit = $visitDate;
        }
    }

    $zoneHeat[$zoneId] = [
        'label'       => pz_zoneLabel($zoneId),
        'visits'      => $visitsCount,
        'total_marks' => $totalMarks,
        'max'         => $maxValue,
        'avg'         => $visitsCount > 0 ? ($sumVisitMax / $visitsCount) : 0.0,
        'last_visit'  => $lastVisit,
    ];

    $maxZoneVisits = max($maxZoneVisits, $visitsCount);
}

$metricValueKey = match ($metric) {
    'count' => 'visits',
    'avg'   => 'avg',
    default => 'max',
};

uasort($zoneHeat, static function (array $a, array $b) use ($metricValueKey): int {
    $cmp = $b[$metricValueKey] <=> $a[$metricValueKey];
    if ($cmp !== 0) {
        return $cmp;
    }

    return strcmp((string)$a['label'], (string)$b['label']);
});

foreach ($zoneHeat as &$heat) {
    $value          = (float)$heat[$metricValueKey];
    $heat['color']  = pz_compareColorForMetric($metric, $value, $maxZoneVisits);
    $heat['tooltip'] = $heat['label'] . "\n"
        . xl('Visits') . ': ' . $heat['visits'] . "\n"
        . xl('Marks') . ': ' . $heat['total_marks'] . "\n"
        . xl('Maximum') . ': ' . $heat['max'] . "\n"
        . xl('Average') . ': ' . number_format((float)$heat['avg'], 1) . "\n"
        . xl('Last visit') . ': '
        . ($heat['last_visit'] !== null ? pz_compareFormatDate((string)$heat['last_visit']) : '');
}
unset($heat);

// ---------------------------------------------------------------------------
// 9. Pins for overlay mode
// ---------------------------------------------------------------------------

$pins = [];
foreach ($selectedVisits as $formId) {
    $visitDate = $visitDateById[$formId] ?? '';

    foreach ($panelMarksByForm[$formId] ?? [] as $mark) {
        $quality = trim((string)($mark['pain_quality'] ?? ''));
        if ($quality !== '') {
            $quality = pz_qualityLabel($quality);
        }

        $tooltip = pz_compareFormatDate($visitDate) . "\n"
            . pz_zoneLabel((string)($mark['zone_id'] ?? '')) . "\n"
            . xl('Intensity') . ': ' . (int)$mark['intensity'] . '/10'
            . ($quality !== '' ? "\n" . xl('Quality') . ': ' . $quality : '');

        $pins[] = [
            'mark'    => $mark,
            'color'   => $visitColors[$formId],
            'tooltip' => $tooltip,
        ];
    }
}

// ---------------------------------------------------------------------------
// 10. Render the SVG for the selected mode
// ---------------------------------------------------------------------------

$validZones = pz_getSvgZoneIds($figure, $view);
$svgContent = pz_getSvgContent($figure, $view);

if ($mode === 'heatmap' && $zoneHeat !== []) {
    $svgMarkup = pz_compareApplyHeatToSvg($svgContent, $zoneHeat, $validZones);
} elseif ($mode === 'overlay' && $pins !== []) {
    $svgMarkup = pz_compareAddPinsToSvg($svgContent, $pins);
} else {
    $svgMarkup = $svgContent;
}

// ---------------------------------------------------------------------------
// 11. Page metadata
// ---------------------------------------------------------------------------

$rootdir     = pz_rootdir();
$compareUrl  = $rootdir . '/forms/pain_zones/compare.php';

$figureLabel = ($figure === 'adult_female') ? xl('Female') : xl('Male');
$viewLabel   = ($view === 'posterior') ? xl('Posterior') : xl('Anterior');
$metricLabel = match ($metric) {
    'avg'   => xl('Average'),
    'count' => xl('Count'),
    default => xl('Maximum'),
};

$patientRows = QueryUtils::fetchRecords(
    'SELECT fname, lname FROM patient_data WHERE pid = ? LIMIT 1',
    [$pid]
);
$patientName = trim(
    (string)($patientRows[0]['fname'] ?? '') . ' ' . (string)($patientRows[0]['lname'] ?? '')
);

// Target of the "Go Back" button: the caller supplied a validated path, otherwise
// fall back to the encounter form list so the user still leaves the page safely.
$PZ_COMPARE_RETURN_URL = ($returnUrl !== '')
    ? $returnUrl
    : $rootdir . '/patient_file/encounter/forms.php';

$palette       = pz_getLevelColors();
$legendStops   = [];
for ($i = 0; $i <= 10; $i++) {
    $legendStops[] = $palette['level_' . $i]['fill'] . ' ' . ($i * 10) . '%';
}
$legendGradient = 'linear-gradient(to right, ' . implode(', ', $legendStops) . ')';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Visit Comparison'); ?> - <?php echo xlt('Pain Zones'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .pz-compare-container {
            max-width: 1500px;
            margin: 0 auto;
            padding: 15px;
        }
        .pz-compare-map {
            position: relative;
            width: 100%;
            height: 72vh;
            min-height: 520px;
            overflow: hidden;
            background: #ffffff;
        }
        .pz-compare-map svg {
            width: 100%;
            height: 100%;
            display: block;
        }
        .pz-visit-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid #dee2e6;
            border-radius: 14px;
            padding: 2px 8px;
            margin: 0 6px 6px 0;
            cursor: pointer;
            background: #ffffff;
            font-size: 0.82rem;
        }
        .pz-visit-chip.is-selected {
            background: #f8f9fa;
            border-width: 2px;
        }
        .pz-visit-chip input {
            margin-right: 5px;
        }
        .pz-visit-dot,
        .pz-heat-swatch {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 4px;
            vertical-align: middle;
        }
        .pz-heat-swatch {
            width: 12px;
            height: 12px;
            border: 1px solid #666666;
            border-radius: 2px;
            margin-right: 5px;
        }
        .pz-heat-legend-bar {
            display: inline-block;
            width: 170px;
            height: 12px;
            border: 1px solid #adb5bd;
            border-radius: 2px;
            margin: 0 6px;
            vertical-align: middle;
        }
        @media print {
            .pz-no-print {
                display: none !important;
            }
            .pz-compare-container {
                max-width: none;
                padding: 0;
            }
            .pz-compare-map {
                height: 60vh;
                min-height: 420px;
            }
            .card {
                break-inside: avoid;
            }
            body {
                background: #ffffff !important;
            }
        }
    </style>
</head>
<body class="body_top bg-light">
<div class="pz-compare-container">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0 text-primary">
            <?php echo xlt('Pain Zones'); ?>
            <span class="text-muted font-weight-normal">/</span>
            <?php echo xlt('Visit Comparison'); ?>
        </h3>
        <div class="pz-no-print">
            <button type="button" class="btn btn-secondary btn-sm mr-2" onclick="return pzCompareBack();">
                <?php echo xlt('Go Back'); ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print();">
                <?php echo xlt('Print'); ?>
            </button>
        </div>
    </div>

    <?php if ($patientName !== ''): ?>
        <div class="text-muted mb-2">
            <?php echo xlt('Patient'); ?>: <strong><?php echo text($patientName); ?></strong>
        </div>
    <?php endif; ?>

    <form method="get" action="<?php echo attr($compareUrl); ?>" id="pz-compare-form"
          class="card shadow-sm mb-3 pz-no-print">
        <input type="hidden" name="visits_present" value="1">
        <div class="card-body py-2">
            <div class="form-row align-items-end">
                <div class="col-md-3 mb-2">
                    <label class="small font-weight-bold d-block"><?php echo xlt('Mode'); ?></label>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="mode" id="pz-mode-overlay"
                               value="overlay"<?php echo $mode === 'overlay' ? ' checked' : ''; ?> data-pz-autosubmit>
                        <label class="form-check-label" for="pz-mode-overlay"><?php echo xlt('Overlay'); ?></label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="mode" id="pz-mode-heatmap"
                               value="heatmap"<?php echo $mode === 'heatmap' ? ' checked' : ''; ?> data-pz-autosubmit>
                        <label class="form-check-label" for="pz-mode-heatmap"><?php echo xlt('Heatmap'); ?></label>
                    </div>
                </div>

                <div class="col-md-3 mb-2">
                    <label for="pz-range" class="small font-weight-bold"><?php echo xlt('Date Range'); ?></label>
                    <select id="pz-range" name="range" class="form-control form-control-sm" data-pz-autosubmit>
                        <option value="30d"<?php echo $range === '30d' ? ' selected' : ''; ?>><?php echo xlt('Last 30 days'); ?></option>
                        <option value="3m"<?php echo $range === '3m' ? ' selected' : ''; ?>><?php echo xlt('Last 3 months'); ?></option>
                        <option value="6m"<?php echo $range === '6m' ? ' selected' : ''; ?>><?php echo xlt('Last 6 months'); ?></option>
                        <option value="12m"<?php echo $range === '12m' ? ' selected' : ''; ?>><?php echo xlt('Last 12 months'); ?></option>
                        <option value="all"<?php echo $range === 'all' ? ' selected' : ''; ?>><?php echo xlt('All'); ?></option>
                        <option value="custom"<?php echo $range === 'custom' ? ' selected' : ''; ?>><?php echo xlt('Custom'); ?></option>
                    </select>
                </div>

                <div class="col-md-2 mb-2">
                    <label for="pz-figure" class="small font-weight-bold"><?php echo xlt('Figure'); ?></label>
                    <select id="pz-figure" name="figure" class="form-control form-control-sm" data-pz-autosubmit>
                        <option value="adult_male"<?php echo $figure === 'adult_male' ? ' selected' : ''; ?>><?php echo xlt('Adult Male'); ?></option>
                        <option value="adult_female"<?php echo $figure === 'adult_female' ? ' selected' : ''; ?>><?php echo xlt('Adult Female'); ?></option>
                    </select>
                </div>

                <div class="col-md-2 mb-2">
                    <label for="pz-view" class="small font-weight-bold"><?php echo xlt('View'); ?></label>
                    <select id="pz-view" name="view" class="form-control form-control-sm" data-pz-autosubmit>
                        <option value="anterior"<?php echo $view === 'anterior' ? ' selected' : ''; ?>><?php echo xlt('Anterior'); ?></option>
                        <option value="posterior"<?php echo $view === 'posterior' ? ' selected' : ''; ?>><?php echo xlt('Posterior'); ?></option>
                    </select>
                </div>

                <div class="col-md-2 mb-2"<?php echo $mode === 'heatmap' ? '' : ' style="display:none;"'; ?>>
                    <label for="pz-metric" class="small font-weight-bold"><?php echo xlt('Heat Metric'); ?></label>
                    <select id="pz-metric" name="metric" class="form-control form-control-sm" data-pz-autosubmit>
                        <option value="max"<?php echo $metric === 'max' ? ' selected' : ''; ?>><?php echo xlt('Maximum'); ?></option>
                        <option value="avg"<?php echo $metric === 'avg' ? ' selected' : ''; ?>><?php echo xlt('Average'); ?></option>
                        <option value="count"<?php echo $metric === 'count' ? ' selected' : ''; ?>><?php echo xlt('Count'); ?></option>
                    </select>
                </div>
            </div>

            <?php if ($range === 'custom'): ?>
                <div class="form-row align-items-end">
                    <div class="col-md-3 mb-2">
                        <label for="pz-from" class="small font-weight-bold"><?php echo xlt('From'); ?></label>
                        <input type="date" id="pz-from" name="from" class="form-control form-control-sm"
                               value="<?php echo attr((string)$fromDate); ?>">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="pz-to" class="small font-weight-bold"><?php echo xlt('To'); ?></label>
                        <input type="date" id="pz-to" name="to" class="form-control form-control-sm"
                               value="<?php echo attr((string)$toDate); ?>">
                    </div>
                </div>
            <?php else: ?>
                <div class="small text-muted">
                    <?php if ($range === 'all'): ?>
                        <?php echo xlt('All time'); ?>
                    <?php else: ?>
                        <?php echo text((string)$fromDate); ?> &rarr; <?php echo text((string)$toDate); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div id="pz-visit-selector"<?php echo $mode === 'heatmap' ? ' style="display:none;"' : ''; ?>>
                <hr class="my-2">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="small font-weight-bold"><?php echo xlt('Visits'); ?></span>
                    <span class="small text-muted"><?php echo xlt('Select up to 5 visits'); ?></span>
                </div>
                <div>
                    <?php foreach ($visitRows as $row): ?>
                        <?php
                        $formId     = (int)$row['id'];
                        $isSelected = isset($selectedMap[$formId]);
                        $stat       = $visitStats[$formId];
                        $chipColor  = $visitColors[$formId] ?? '';
                        $chipStyle  = $isSelected ? ' style="border-color:' . attr($chipColor) . ';"' : '';
                        ?>
                        <label class="pz-visit-chip<?php echo $isSelected ? ' is-selected' : ''; ?>"<?php echo $chipStyle; ?>>
                            <input type="checkbox" class="pz-visit-checkbox" name="visits[]"
                                   value="<?php echo attr((string)$formId); ?>"<?php echo $isSelected ? ' checked' : ''; ?>>
                            <?php if ($isSelected): ?>
                                <span class="pz-visit-dot" style="background:<?php echo attr($chipColor); ?>;"></span>
                            <?php endif; ?>
                            <span><?php echo text(pz_compareFormatDate($stat['date'])); ?></span>
                            <span class="text-muted">(<?php echo (int)$stat['marks']; ?>)</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mt-2">
                <button type="submit" class="btn btn-primary btn-sm"><?php echo xlt('Apply'); ?></button>
                <?php if ($mode === 'overlay' && $selectedVisits === [] && $visitRows !== []): ?>
                    <span class="text-danger small ml-2"><?php echo xlt('Select at least one visit to overlay.'); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if ($visitRows === []): ?>
        <div class="alert alert-info">
            <?php echo xlt('No confirmed visits in the selected range.'); ?>
        </div>
    <?php else: ?>
        <div class="row">
            <div class="col-lg-6 mb-3">
                <div class="card shadow-sm">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold text-dark"><?php echo xlt('Anatomical Map'); ?></span>
                        <span class="small text-muted">
                            <?php echo text($figureLabel); ?> / <?php echo text($viewLabel); ?>
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <div class="pz-compare-map">
                            <?php echo $svgMarkup; // Trusted local SVG plus server-generated elements. ?>
                        </div>
                        <?php if ($panelMarks === []): ?>
                            <div class="p-2 small text-muted">
                                <?php echo xlt('No pain marks in this panel for the selected range.'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer bg-white py-2">
                        <?php if ($mode === 'overlay'): ?>
                            <?php if ($selectedVisits === []): ?>
                                <span class="text-muted small"><?php echo xlt('Select at least one visit to overlay.'); ?></span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap align-items-center">
                                    <?php foreach ($selectedVisits as $formId): ?>
                                        <span class="small mr-3 mb-1">
                                            <span class="pz-visit-dot" style="background:<?php echo attr($visitColors[$formId]); ?>;"></span>
                                            <?php echo text(pz_compareFormatDate($visitStats[$formId]['date'])); ?>
                                            <span class="text-muted">(<?php echo (int)$visitStats[$formId]['marks']; ?>)</span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="d-flex align-items-center flex-wrap small">
                                <?php if ($metric === 'count'): ?>
                                    <span class="text-muted mr-2"><?php echo xlt('Visits with mark'); ?>:</span>
                                    <span>1</span>
                                    <span class="pz-heat-legend-bar"
                                          style="background: linear-gradient(to right, #FFCC80, #B71C1C);"></span>
                                    <span><?php echo (int)$maxZoneVisits; ?></span>
                                <?php else: ?>
                                    <span class="text-muted mr-2"><?php echo xlt('Intensity'); ?>:</span>
                                    <span>0</span>
                                    <span class="pz-heat-legend-bar"
                                          style="background: <?php echo attr($legendGradient); ?>;"></span>
                                    <span>10</span>
                                <?php endif; ?>
                                <span class="text-muted ml-3"><?php echo xlt('Hover a zone to see details.'); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-6 mb-3">
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                        <span class="font-weight-bold text-dark"><?php echo xlt('Visits'); ?></span>
                        <span class="small text-muted">
                            <?php echo text($figureLabel); ?> / <?php echo text($viewLabel); ?>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th><?php echo xlt('Date'); ?></th>
                                    <th class="text-right"><?php echo xlt('Marks'); ?></th>
                                    <th class="text-right"><?php echo xlt('Maximum'); ?></th>
                                    <th class="text-right"><?php echo xlt('Average'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visitStats as $formId => $stat): ?>
                                    <tr>
                                        <td>
                                            <?php if ($mode === 'overlay' && isset($visitColors[$formId])): ?>
                                                <span class="pz-visit-dot" style="background:<?php echo attr($visitColors[$formId]); ?>;"></span>
                                            <?php endif; ?>
                                            <?php echo text(pz_compareFormatDate($stat['date'])); ?>
                                        </td>
                                        <td class="text-right"><?php echo (int)$stat['marks']; ?></td>
                                        <td class="text-right"><?php echo $stat['max'] === null ? '&mdash;' : (int)$stat['max']; ?></td>
                                        <td class="text-right"><?php echo $stat['avg'] === null ? '&mdash;' : text(number_format((float)$stat['avg'], 1)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-white py-1">
                        <span class="small text-muted">
                            <?php echo xlt('Visit totals include only marks for the selected figure and view.'); ?>
                        </span>
                    </div>
                </div>

                <?php if ($mode === 'heatmap'): ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                            <span class="font-weight-bold text-dark"><?php echo xlt('Zone Heat'); ?></span>
                            <span class="small text-muted"><?php echo text($metricLabel); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th><?php echo xlt('Zone'); ?></th>
                                        <th class="text-right"><?php echo xlt('Visits'); ?></th>
                                        <th class="text-right"><?php echo xlt('Marks'); ?></th>
                                        <th class="text-right"><?php echo xlt('Maximum'); ?></th>
                                        <th class="text-right"><?php echo xlt('Average'); ?></th>
                                        <th><?php echo xlt('Last visit'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($zoneHeat === []): ?>
                                        <tr>
                                            <td colspan="6" class="text-muted"><?php echo xlt('No pain marks recorded.'); ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($zoneHeat as $heat): ?>
                                            <tr>
                                                <td>
                                                    <span class="pz-heat-swatch"
                                                          style="background:<?php echo attr($heat['color']['fill']); ?>;border-color:<?php echo attr($heat['color']['stroke']); ?>;"></span>
                                                    <?php echo text($heat['label']); ?>
                                                </td>
                                                <td class="text-right"><?php echo (int)$heat['visits']; ?></td>
                                                <td class="text-right"><?php echo (int)$heat['total_marks']; ?></td>
                                                <td class="text-right"><?php echo (int)$heat['max']; ?></td>
                                                <td class="text-right"><?php echo text(number_format((float)$heat['avg'], 1)); ?></td>
                                                <td><?php echo text($heat['last_visit'] !== null ? pz_compareFormatDate((string)$heat['last_visit']) : ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-white py-1">
                            <span class="small text-muted">
                                <?php echo xlt('Average of the maximum intensity recorded per visit.'); ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';

    const form = document.getElementById('pz-compare-form');
    if (!form) {
        return;
    }

    const visitBoxes = Array.from(form.querySelectorAll('.pz-visit-checkbox'));

    function syncVisitLimit() {
        const checkedCount = visitBoxes.filter(function (box) { return box.checked; }).length;
        const limitReached = checkedCount >= 5;

        visitBoxes.forEach(function (box) {
            if (!box.checked) {
                box.disabled = limitReached;
            }
        });
    }

    visitBoxes.forEach(function (box) {
        box.addEventListener('change', syncVisitLimit);
    });
    syncVisitLimit();

    form.querySelectorAll('[data-pz-autosubmit]').forEach(function (field) {
        field.addEventListener('change', function () {
            form.submit();
        });
    });
}());

// Return to the view this page was opened from. Matching the AI assistant
// module, "Go Back" navigates the *same* encounter tab back to the caller
// (the Pain Zones form) instead of closing the tab, so the user lands exactly
// where they left off rather than on the encounter summary.
function pzCompareBack() {
    if (window.top && typeof window.top.restoreSession === 'function') {
        window.top.restoreSession();
    }
    window.location.href = <?php echo json_encode($PZ_COMPARE_RETURN_URL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    return false;
}
</script>
</body>
</html>
