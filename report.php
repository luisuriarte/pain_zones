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
 * Emits the CSS and JS for the hover popup that shows the body figure on the
 * encounter summary. The figure is never shown inline: it stays hidden inside a
 * `.pz-mark-popup` element and appears next to the cursor when the pointer
 * enters a row of the marks table, highlighting that row's zone and pin.
 *
 * Emitted once per page. Each popup carries its own pin payload in
 * `data-pz-pins`, so several Pain Zones forms can share the same script.
 */
function pz_reportFigureAssets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <style>
        .pz-report-marks tr.pz-report-mark-row:hover {
            background-color: #eef5fb;
        }
        .pz-mark-popup {
            position: fixed;
            z-index: 1080;
            display: none;
            pointer-events: none;
            background: #ffffff;
            border: 1px solid #b0bec5;
            border-radius: 8px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
            padding: 10px;
            max-width: 640px;
        }
        .pz-mark-popup-body {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .pz-mark-popup-figure svg {
            display: block;
            width: 200px;
            height: auto;
        }
        /* Zones and pins that are not the hovered one fade into the background. */
        .pz-mark-popup-figure svg .pz-popup-dim {
            opacity: 0.18;
            transition: opacity 0.12s ease;
        }
        .pz-mark-popup-figure svg .pz-popup-hl {
            filter: drop-shadow(0 0 5px rgba(21, 101, 192, 0.95));
        }
        .pz-mark-popup-details {
            max-width: 380px;
            font-size: 0.8125rem;
            color: #263238;
        }
        .pz-mark-popup-title {
            font-weight: 700;
            font-size: 0.95rem;
            color: #0d47a1;
            margin-bottom: 4px;
        }
        .pz-mark-popup-badge {
            display: inline-block;
            font-weight: 700;
            font-size: 0.75rem;
            line-height: 1;
            padding: 2px 7px;
            border-radius: 10px;
            margin-right: 6px;
            color: #ffffff;
            vertical-align: middle;
        }
        .pz-mark-popup-row {
            font-size: 0.8rem;
            margin-top: 3px;
            line-height: 1.35;
        }
        .pz-mark-popup-row b {
            color: #455a64;
        }
    </style>
    <script>
        (function () {
            'use strict';
            if (typeof window.pzReportFigureInit === 'function') {
                return;
            }
            window.pzReportFigureInit = function (root) {
                root = root || document;

                // The popups live inside the collapsible encounter-form
                // containers, where a position:fixed child gets clipped. Move
                // each to <body> so it can float freely, and hide it inline.
                root.querySelectorAll('.pz-mark-popup').forEach(function (popup) {
                    if (popup.parentNode !== document.body) {
                        document.body.appendChild(popup);
                    }
                    popup.style.display = 'none';
                });

                function popupFor(instanceId) {
                    return document.querySelector('.pz-mark-popup[data-pz-instance="' + instanceId + '"]')
                        || document.querySelector('.pz-mark-popup');
                }

                function renderDetails(details, data) {
                    details.textContent = '';

                    const title = document.createElement('div');
                    title.className = 'pz-mark-popup-title';
                    title.textContent = data.title;
                    details.appendChild(title);

                    const head = document.createElement('div');
                    head.className = 'pz-mark-popup-row';
                    const badge = document.createElement('span');
                    badge.className = 'pz-mark-popup-badge';
                    badge.style.backgroundColor = data.color || '#455A64';
                    badge.textContent = data.intensity + '/10';
                    head.appendChild(badge);
                    if (data.band) {
                        head.appendChild(document.createTextNode(data.band));
                    }
                    details.appendChild(head);

                    (data.details || []).forEach(function (row) {
                        const line = document.createElement('div');
                        line.className = 'pz-mark-popup-row';
                        const b = document.createElement('b');
                        b.textContent = row.label + ': ';
                        line.appendChild(b);
                        line.appendChild(document.createTextNode(row.value));
                        details.appendChild(line);
                    });
                }

                // Dim everything, then highlight only the hovered zone and pin.
                function highlight(figure, index, zone) {
                    if (!figure) {
                        return;
                    }
                    figure.querySelectorAll('.pz-popup-dim, .pz-popup-hl').forEach(function (el) {
                        el.classList.remove('pz-popup-dim', 'pz-popup-hl');
                    });
                    figure.querySelectorAll('path, circle, ellipse, polygon, polyline, rect')
                        .forEach(function (el) { el.classList.add('pz-popup-dim'); });

                    if (zone) {
                        let el = null;
                        try {
                            el = figure.querySelector('[id="' + CSS.escape(zone) + '"]');
                        } catch (e) {
                            el = null;
                        }
                        if (el) {
                            el.classList.remove('pz-popup-dim');
                            el.classList.add('pz-popup-hl');
                        }
                    }
                    const group = figure.querySelectorAll('#pz-compare-pins > g')[index];
                    if (group) {
                        group.classList.remove('pz-popup-dim');
                        group.classList.add('pz-popup-hl');
                        group.querySelectorAll('circle, text').forEach(function (el) {
                            el.classList.remove('pz-popup-dim');
                            el.classList.add('pz-popup-hl');
                        });
                    }
                }

                function place(popup, evt) {
                    const margin = 16;
                    const rect = popup.getBoundingClientRect();
                    let left = evt.clientX + margin;
                    let top = evt.clientY + margin;
                    if (left + rect.width > window.innerWidth - margin) {
                        left = window.innerWidth - rect.width - margin;
                    }
                    if (left < margin) {
                        left = margin;
                    }
                    if (top + rect.height > window.innerHeight - margin) {
                        top = evt.clientY - rect.height - margin;
                    }
                    if (top < margin) {
                        top = margin;
                    }
                    popup.style.left = left + 'px';
                    popup.style.top = top + 'px';
                }

                function showFor(row, evt) {
                    const instanceId = row.getAttribute('data-pz-instance') || '';
                    const popup = popupFor(instanceId);
                    if (!popup) {
                        return;
                    }
                    let pins = [];
                    try {
                        pins = JSON.parse(popup.getAttribute('data-pz-pins') || '[]');
                    } catch (e) {
                        pins = [];
                    }
                    const index = parseInt(row.getAttribute('data-pz-pin'), 10);
                    const data = pins[index];
                    if (!data) {
                        return;
                    }
                    // Make the popup measurable before placing it.
                    popup.style.display = 'block';
                    popup.setAttribute('aria-hidden', 'false');
                    renderDetails(popup.querySelector('.pz-mark-popup-details'), data);
                    highlight(popup.querySelector('.pz-mark-popup-figure'), index, row.getAttribute('data-pz-zone') || '');
                    place(popup, evt);
                }

                function hideAll() {
                    document.querySelectorAll('.pz-mark-popup').forEach(function (popup) {
                        popup.style.display = 'none';
                        popup.setAttribute('aria-hidden', 'true');
                    });
                }

                // Use event delegation on the document: the encounter summary
                // re-renders rows when forms expand/collapse, so binding per row
                // would go stale.
                document.addEventListener('mouseover', function (evt) {
                    if (!evt.target || typeof evt.target.closest !== 'function') {
                        return;
                    }
                    const row = evt.target.closest('.pz-report-mark-row');
                    if (row) {
                        showFor(row, evt);
                    }
                });
                document.addEventListener('mousemove', function (evt) {
                    const open = document.querySelector('.pz-mark-popup[aria-hidden="false"]');
                    if (open) {
                        place(open, evt);
                    }
                });
                document.addEventListener('mouseout', function (evt) {
                    if (!evt.target || typeof evt.target.closest !== 'function') {
                        return;
                    }
                    const row = evt.target.closest('.pz-report-mark-row');
                    if (!row) {
                        return;
                    }
                    // Ignore moves that stay within the same row.
                    const to = evt.relatedTarget;
                    if (to && row.contains(to)) {
                        return;
                    }
                    hideAll();
                });
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' || e.key === 'Esc') {
                        hideAll();
                    }
                });
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { window.pzReportFigureInit(document); });
            } else {
                window.pzReportFigureInit(document);
            }
        })();
    </script>
    <?php
}

/**
 * Emits the body-figure block (caption + figure) shown beside the marks table
 * in the printable export.
 *
 * The figure is always a data-URI <img>: mPDF only draws SVG that is referenced
 * as an image, never an inline <svg> element. On screen this block is not used;
 * the figure lives inside the hover popup instead.
 *
 * @param array{svg: string, dataUri: string, figure: string, view: string, pins: array<int, mixed>} $figure
 */
function pz_reportFigureBlock(array $figure): void
{
    $figureLabel = match ($figure['figure']) {
        'adult_female' => xlt('Female'),
        'adult_male'   => xlt('Male'),
        default        => text($figure['figure']),
    };
    $viewLabel = match ($figure['view']) {
        'anterior'  => xlt('Anterior'),
        'posterior' => xlt('Posterior'),
        default     => text($figure['view']),
    };

    $caption = $figureLabel . ' - ' . $viewLabel;

    echo '<div class="pz-report-figure" style="text-align: center;">';
    echo '<div style="font-size: 0.9em; font-weight: bold; margin-bottom: 4px;">' . text($caption) . '</div>';
    echo '<img src="' . attr($figure['dataUri']) . '" alt="' . attr($caption) . '" style="width: 100%; height: auto;" />';
    echo '</div>';
}

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

    // On screen the figure is never shown inline: it lives inside a hover
    // popup opened from the marks table (see pz_reportFigureAssets). Only the
    // PDF export embeds the figure beside the table as an <img> data URI, the
    // one form mPDF actually draws.
    $reportFigure = (!empty($marks)) ? pz_buildReportFigure($marks) : null;
    $isPdfOutput  = !empty($GLOBALS['PDF_OUTPUT']);

    // Map each mark id to its pin index in the figure payload, so a table row
    // can highlight its own zone/pin inside the popup.
    $pinIndexByMark = [];
    if ($reportFigure !== null) {
        foreach ($reportFigure['pins'] as $i => $pin) {
            if (($pin['markId'] ?? '') !== '') {
                $pinIndexByMark[(string)$pin['markId']] = $i;
            }
        }
    }

    // A form may appear several times in one encounter, so scope the popup and
    // its row links by a per-render instance id.
    static $instanceSeq = 0;
    $instanceId = 'pz-marks-' . (++$instanceSeq);

    // mPDF has no reliable flexbox support, so the PDF uses an inert layout table
    // to keep the figure beside the marks; the browser shows only the table.
    if ($isPdfOutput) {
        echo '<table style="width: 100%; border-collapse: collapse;"><tr>';
        if ($reportFigure !== null) {
            echo '<td style="width: 240px; vertical-align: top; padding-right: 12px;">';
            pz_reportFigureBlock($reportFigure);
            echo '</td>';
        }
        echo '<td style="vertical-align: top;">';
    }

    echo '<table class="pz-report-marks" style="width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 0.9em;">';
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

            $markIdStr = (string)($mark['id'] ?? '');
            $pinIndex  = ($markIdStr !== '' && isset($pinIndexByMark[$markIdStr])) ? $pinIndexByMark[$markIdStr] : null;
            $rowAttrs  = 'border-bottom: 1px solid #e0e0e0; vertical-align: top;';
            if (!$isPdfOutput && $pinIndex !== null) {
                $rowAttrs .= ' cursor: help;';
            }
            echo '<tr style="' . $rowAttrs . '"';
            if (!$isPdfOutput && $pinIndex !== null) {
                echo ' class="pz-report-mark-row" data-pz-pin="' . attr((string)$pinIndex) . '"';
                echo ' data-pz-zone="' . attr($rawZone) . '"';
                echo ' data-pz-instance="' . attr($instanceId) . '"';
            }
            echo '>';
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

    if ($isPdfOutput) {
        echo '</td>';       // marks table cell
        echo '</tr></table>'; // layout table
    } elseif ($reportFigure !== null && $reportFigure['pins'] !== []) {
        // Hover popup: hidden inline so it never flashes before the CSS/JS run,
        // then attached to <body> by the script to escape the collapsible
        // encounter-form container (which would clip a fixed element). The pin
        // payload travels on the element itself so several forms can coexist.
        $pinsJson = json_encode($reportFigure['pins'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        echo '<div class="pz-mark-popup" id="' . attr($instanceId) . '" data-pz-instance="' . attr($instanceId) . '" role="dialog" aria-hidden="true"'
            . ' data-pz-pins="' . attr($pinsJson) . '" style="display: none;">';
        echo '<div class="pz-mark-popup-body">';
        echo '<div class="pz-mark-popup-figure pz-report-figure-svg">' . $reportFigure['svg'] . '</div>';
        echo '<div class="pz-mark-popup-details"></div>';
        echo '</div>';
        echo '</div>';
    }

    $generalNotes = trim((string)($headerRows[0]['general_notes'] ?? ''));
    if ($generalNotes !== '') {
        echo '<div style="margin-top: 6px; font-size: 0.9em;">';
        echo '<strong>' . xlt('General Notes') . ':</strong> ';
        echo '<span class="text">' . text($generalNotes) . '</span>';
        echo '</div>';
    }

    echo '<div style="margin-top: 8px; font-size: 0.9em;">';
    if ($isPdfOutput) {
        echo '<a href="' . attr(pz_rootdir() . '/forms/pain_zones/compare.php') . '" class="link">';
    } else {
        echo '<a href="' . attr(pz_rootdir() . '/forms/pain_zones/compare.php') . '"'
            . ' class="link" onclick="return pzCompareOpen(this);">';
    }
    echo xlt('Compare with previous visits');
    echo '</a>';
    echo '</div>';

    // Interactive tooltip assets and navigation helper are browser-only.
    if (!$isPdfOutput) {
        if ($reportFigure !== null && $reportFigure['pins'] !== []) {
            pz_reportFigureAssets();
        }
        ?>
        <script>
            if (typeof window.pzCompareOpen !== 'function') {
                window.pzCompareOpen = function (link) {
                    // Navigate the current encounter tab to the comparison page, matching
                    // the AI assistant module. Without a "return" target the page's own
                    // "Go Back" button falls back to the encounter form list.
                    if (typeof top.restoreSession === 'function') {
                        top.restoreSession();
                    }
                    window.location.href = link.href;
                    return false;
                };
            }
        </script>
        <?php
    }
}
