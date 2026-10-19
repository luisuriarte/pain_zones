<?php

/**
 * report.php  -  Pain Zones encounter report for summary and print/PDF views.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/pain_zones.inc.php';

use OpenEMR\Common\Database\QueryUtils;

/**
 * Renders the Pain Zones form report in the encounter summary.
 *
 * @param int|string $pid
 * @param int|string $encounter
 * @param int|string $cols
 * @param int|string $id
 */
function pain_zones_report($pid, $encounter, $cols, $id): void
{
    if (!pz_canRead()) {
        return;
    }

    $formId    = (int)$id;
    $patientId = (int)$pid;

    if ($formId <= 0 || $patientId <= 0) {
        return;
    }

    // Verify form header exists and belongs to patient
    $headerRows = QueryUtils::fetchRecords(
        "SELECT id, general_notes FROM form_pain_zones WHERE id = ? AND pid = ? AND activity = 1",
        [$formId, $patientId]
    );

    if (empty($headerRows)) {
        return;
    }

    // Retrieve active marks ordered by intensity DESC, figure, view, zone_id
    $marks = QueryUtils::fetchRecords(
        "SELECT m.id, m.figure, m.body_view, m.zone_id, m.laterality, m.intensity,
                m.pain_quality, m.radiation, m.onset, m.duration,
                m.aggravating, m.relieving, m.notes,
                lo.title AS level_title, lo.mapping AS level_mapping
         FROM form_pain_zones_marks m
         LEFT JOIN list_options lo
           ON lo.list_id = 'pain_zones_level'
          AND lo.option_id = CONCAT('level_', m.intensity)
          AND lo.activity = 1
         WHERE m.form_id = ? AND m.pid = ? AND m.activity = 1
         ORDER BY m.intensity DESC, m.figure ASC, m.body_view ASC, m.zone_id ASC",
        [$formId, $patientId]
    );

    $bandMap = [
        'none'     => xlt('None'),
        'mild'     => xlt('Mild'),
        'moderate' => xlt('Moderate'),
        'severe'   => xlt('Severe'),
    ];

    echo '<table style="width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 0.9em;">';
    echo '<thead>';
    echo '<tr style="border-bottom: 2px solid #ccc; text-align: left; background-color: #f2f2f2;">';
    echo '<th style="padding: 4px 8px;">' . xlt('Zone') . '</th>';
    echo '<th style="padding: 4px 8px;">' . xlt('Laterality') . '</th>';
    echo '<th style="padding: 4px 8px;">' . xlt('Figure') . '</th>';
    echo '<th style="padding: 4px 8px;">' . xlt('View') . '</th>';
    echo '<th style="padding: 4px 8px; text-align: center;">' . xlt('Intensity') . '</th>';
    echo '<th style="padding: 4px 8px;">' . xlt('Band') . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';

    if (empty($marks)) {
        echo '<tr>';
        echo '<td colspan="6" style="padding: 6px 8px; color: #666; font-style: italic;">' . xlt('No pain marks recorded.') . '</td>';
        echo '</tr>';
    } else {
        foreach ($marks as $mark) {
            $rawZone       = (string)($mark['zone_id'] ?? '');
            $zoneDisplay   = pz_zoneLabel($rawZone);
            $rawLaterality = (string)($mark['laterality'] ?? 'midline');
            $lateralityDisplay = match ($rawLaterality) {
                'left'    => xlt('Left'),
                'right'   => xlt('Right'),
                default   => xlt('Midline'),
            };

            $rawFigure = (string)($mark['figure'] ?? '');
            $figureDisplay = match ($rawFigure) {
                'adult_female' => xlt('Female'),
                'adult_male'   => xlt('Male'),
                default        => text($rawFigure),
            };

            $rawView = (string)($mark['body_view'] ?? '');
            $viewDisplay = match ($rawView) {
                'anterior'  => xlt('Anterior'),
                'posterior' => xlt('Posterior'),
                default     => text($rawView),
            };

            $rawBand     = (string)($mark['level_mapping'] ?? '');
            $bandDisplay = $bandMap[$rawBand] ?? text($rawBand);

            $clinicalParts = [];
            if (!empty($mark['pain_quality'])) {
                $clinicalParts[] = '<strong>' . xlt('Quality') . ':</strong> ' . text(pz_qualityLabel((string)$mark['pain_quality']));
            }
            if (!empty($mark['radiation'])) {
                $clinicalParts[] = '<strong>' . xlt('Radiation') . ':</strong> ' . text((string)$mark['radiation']);
            }
            if (!empty($mark['onset'])) {
                $clinicalParts[] = '<strong>' . xlt('Onset') . ':</strong> ' . text((string)$mark['onset']);
            }
            if (!empty($mark['duration'])) {
                $clinicalParts[] = '<strong>' . xlt('Duration') . ':</strong> ' . text((string)$mark['duration']);
            }
            if (!empty($mark['aggravating'])) {
                $clinicalParts[] = '<strong>' . xlt('Aggravating') . ':</strong> ' . text((string)$mark['aggravating']);
            }
            if (!empty($mark['relieving'])) {
                $clinicalParts[] = '<strong>' . xlt('Relieving') . ':</strong> ' . text((string)$mark['relieving']);
            }
            if (!empty($mark['notes'])) {
                $clinicalParts[] = '<strong>' . xlt('Notes') . ':</strong> ' . text((string)$mark['notes']);
            }

            echo '<tr style="border-bottom: 1px solid #e0e0e0; vertical-align: top;">';
            echo '<td style="padding: 4px 8px;">';
            echo '<span style="font-weight: bold;">' . text($zoneDisplay) . '</span>';
            if (!empty($clinicalParts)) {
                echo '<div style="font-size: 0.88em; color: #444; margin-top: 2px;">';
                echo implode(' &bull; ', $clinicalParts);
                echo '</div>';
            }
            echo '</td>';
            echo '<td style="padding: 4px 8px;">' . $lateralityDisplay . '</td>';
            echo '<td style="padding: 4px 8px;">' . $figureDisplay . '</td>';
            echo '<td style="padding: 4px 8px;">' . $viewDisplay . '</td>';
            echo '<td style="padding: 4px 8px; text-align: center; font-weight: bold;">' . text((string)$mark['intensity']) . '</td>';
            echo '<td style="padding: 4px 8px;">' . $bandDisplay . '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '</table>';

    $generalNotes = trim((string)($headerRows[0]['general_notes'] ?? ''));
    if ($generalNotes !== '') {
        echo '<div style="margin-top: 6px; font-size: 0.9em;">';
        echo '<strong>' . xlt('General Notes') . ':</strong> ';
        echo '<span class="text">' . text($generalNotes) . '</span>';
        echo '</div>';
    }
}
