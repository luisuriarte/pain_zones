<?php

/**
 * new.php  -  Pain Zones encounter form entry point (new and edit modes).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/pain_zones.inc.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;

// 1. ACL check: require read permission to view this form.
pz_requireRead();

// 2. Read mode and form id from request.
$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$mode   = ($formId > 0) ? 'update' : 'new';

// 3. Mode 'new' checks: require write access and active encounter before rendering form.
if ($mode === 'new') {
    if (!pz_canWrite('new')) {
        AccessDeniedHelper::denyWithTemplate(
            'ACL check failed for pain_zones: write access required to create a new form',
            xlt('Pain Zones')
        );
    }
    $sessionEncounter = EncounterSessionUtil::getEncounter();
    if (empty($sessionEncounter)) {
        AccessDeniedHelper::denyWithTemplate(
            'No active encounter in session. Please select or open an encounter before adding a Pain Zones form.',
            xlt('Pain Zones')
        );
    }
}

// 4. ACL check for write capability.
$canWrite = pz_canWrite($mode);

// 5. Session and patient data.
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid     = PatientSessionUtil::getPid();

$generalNotes  = '';
$summaryText   = '';
$existingMarks = [];

if ($formId > 0) {
    // Edit mode: verify form ownership by current session patient.
    pz_assertFormBelongsToSessionPatient($formId, 'pain_zones');

    $headerRow = QueryUtils::fetchRecords(
        "SELECT id, general_notes, summary_text FROM form_pain_zones WHERE id = ? AND pid = ? AND activity = 1",
        [$formId, $pid]
    );

    if (empty($headerRow)) {
        AccessDeniedHelper::deny('Pain Zones form not found or inactive');
    }

    $generalNotes  = (string)($headerRow[0]['general_notes'] ?? '');
    $summaryText   = (string)($headerRow[0]['summary_text'] ?? '');
    $existingMarks = QueryUtils::fetchRecords(
        "SELECT id, figure, body_view, zone_id, laterality, x_pct, y_pct,
                intensity, pain_quality, radiation, onset, duration,
                aggravating, relieving, notes
         FROM form_pain_zones_marks
         WHERE form_id = ? AND pid = ? AND activity = 1
         ORDER BY id ASC",
        [$formId, $pid]
    );
}

// 6. Build counts per figure and view (protected against unexpected values).
$counts = [
    'adult_male'   => ['anterior' => 0, 'posterior' => 0],
    'adult_female' => ['anterior' => 0, 'posterior' => 0],
];

$loadedIds   = [];
$clientMarks = [];

foreach ($existingMarks as $m) {
    $dbId = (int)($m['id'] ?? 0);
    if ($dbId > 0) {
        $loadedIds[] = $dbId;
    }

    $fig = (string)($m['figure'] ?? '');
    $vw  = (string)($m['body_view'] ?? '');

    if (isset($counts[$fig][$vw])) {
        $counts[$fig][$vw]++;
    }

    $clientMarks[] = [
        'db_id'        => $dbId > 0 ? $dbId : null,
        '_client_id'   => 'db_' . $dbId,
        'figure'       => $fig,
        'body_view'    => $vw,
        'zone_id'      => (string)($m['zone_id'] ?? ''),
        'laterality'   => (string)($m['laterality'] ?? 'midline'),
        'x_pct'        => round((float)($m['x_pct'] ?? 0.0), 3),
        'y_pct'        => round((float)($m['y_pct'] ?? 0.0), 3),
        'intensity'    => (int)($m['intensity'] ?? 0),
        'pain_quality' => (string)($m['pain_quality'] ?? ''),
        'radiation'    => (string)($m['radiation'] ?? ''),
        'onset'        => (string)($m['onset'] ?? ''),
        'duration'     => (string)($m['duration'] ?? ''),
        'aggravating'  => (string)($m['aggravating'] ?? ''),
        'relieving'    => (string)($m['relieving'] ?? ''),
        'notes'        => (string)($m['notes'] ?? ''),
    ];
}

// 7. Determine initial panel.
// The figure is fixed to the patient's recorded sex at birth (patient_data.sex).
// The view opens on the side that holds the most marks for the patient figure
// (tie: anterior), so existing data is the first thing the user sees.
$patientFigure  = pz_patientFigure($pid);
$patientSex     = pz_patientSex($pid);
$sexUndefined   = ($patientSex === '' || strcasecmp($patientSex, 'UNK') === 0);

$initialFigure = $patientFigure;
$initialView   = ($counts[$patientFigure]['posterior'] > $counts[$patientFigure]['anterior'])
    ? 'posterior'
    : 'anterior';

// A saved form may hold marks drawn on the opposite figure (e.g. legacy data
// captured before the figure followed the patient). Such marks are never
// dropped: when they exist, the figure selector offers both panels so the user
// can still reach them.
$otherFigure   = ($patientFigure === 'adult_female') ? 'adult_male' : 'adult_female';
$hasOtherMarks = (($counts[$otherFigure]['anterior'] + $counts[$otherFigure]['posterior']) > 0);
$figureChoices = [
    $patientFigure => $patientFigure,
];
if ($hasOtherMarks) {
    $figureChoices[$otherFigure] = $otherFigure;
}

$figureLabels = [
    'adult_male'   => xl('Adult Male'),
    'adult_female' => xl('Adult Female'),
];
$figureLabel  = $figureLabels[$patientFigure];

// 8. Prepare assets: palette, valid zones, and SVGs.
$palette    = pz_getLevelColors();
$validZones = [];
$svgContent = [];

foreach (array_keys(PZ_FIGURE_MAP) as $fig) {
    $validZones[$fig] = [];
    $svgContent[$fig] = [];
    foreach (['anterior', 'posterior'] as $vw) {
        $validZones[$fig][$vw] = pz_getSvgZoneIds($fig, $vw);
        $rawSvg = pz_getSvgContent($fig, $vw);
        // Strip <title>...</title> tags to prevent native browser tooltips
        $svgContent[$fig][$vw] = (string)preg_replace('/<title\b[^>]*>(.*?)<\/title>/is', '', $rawSvg);
    }
}

$zoneLabels = [];
foreach (array_keys(PZ_FIGURE_MAP) as $fig) {
    foreach (['anterior', 'posterior'] as $vw) {
        foreach ($validZones[$fig][$vw] as $zid) {
            if (!isset($zoneLabels[$zid])) {
                $zoneLabels[$zid] = pz_zoneLabel($zid);
            }
        }
    }
}

$qualityOptions = pz_getPainQualityOptions();

$qualityLabels = [];
foreach ($qualityOptions as $optId => $optTitle) {
    $qualityLabels[$optId] = xl($optTitle);
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR;

$rootdir = pz_rootdir();
$saveUrl = $rootdir . '/forms/pain_zones/save.php' . (($formId > 0)
    ? '?mode=update&id=' . attr_url((string)$formId)
    : '?mode=new');
// URL this form was opened from, used as the "back" target of the compare page.
$nativeViewUrl = $rootdir . '/patient_file/encounter/view_form.php?formname=pain_zones'
    . ($formId > 0 ? '&id=' . attr_url((string)$formId) : '');
$compareUrl = $rootdir . '/forms/pain_zones/compare.php?return=' . attr_url($nativeViewUrl);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo xlt('Pain Zones'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        .pz-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 15px;
        }
        .pz-map-viewport {
            position: relative;
            width: 100%;
            height: 72vh;
            min-height: 480px;
            overflow: hidden;
            user-select: none;
            background: #ffffff;
            cursor: default;
        }
        .pz-map-viewport.is-panning {
            cursor: grab !important;
        }
        .pz-map-viewport.is-panning * {
            cursor: grab !important;
            pointer-events: none;
        }
        .pz-svg-wrapper {
            position: relative;
            user-select: none;
            width: 100%;
            height: 100%;
            transform-origin: 0 0;
            will-change: transform;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pz-svg-wrapper svg {
            width: 100%;
            height: 100%;
            max-height: 72vh;
            display: block;
            margin: 0 auto;
        }
        .pz-zoom-controls {
            position: absolute;
            bottom: 12px;
            right: 12px;
            z-index: 1040;
            background: rgba(255, 255, 255, 0.94);
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.18);
        }
        .pz-zoom-btn {
            width: 40px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            color: #495057;
            background: #ffffff;
            border-color: #dee2e6;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .pz-zoom-btn:hover {
            background: #e9ecef;
            color: #212529;
        }
        .pz-zoom-label {
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        .pz-zone-clickable {
            cursor: pointer;
            pointer-events: all;
        }
        .pz-zone-unmarked {
            pointer-events: all;
            transition: fill 0.15s ease, fill-opacity 0.15s ease;
        }
        .pz-zone-unmarked path,
        .pz-zone-unmarked polygon,
        .pz-zone-unmarked rect,
        .pz-zone-unmarked circle,
        .pz-zone-unmarked ellipse {
            pointer-events: all;
            transition: fill 0.15s ease, fill-opacity 0.15s ease;
        }
        .pz-zone-unmarked:hover,
        .pz-zone-unmarked:hover path,
        .pz-zone-unmarked:hover polygon,
        .pz-zone-unmarked:hover rect,
        .pz-zone-unmarked:hover circle,
        .pz-zone-unmarked:hover ellipse {
            fill: #d5dbdb !important;
            fill-opacity: 0.55 !important;
        }
        .pz-zone-marked {
            pointer-events: all;
            transition: filter 0.15s ease;
        }
        .pz-zone-marked:hover {
            filter: brightness(0.9);
        }
        .pz-zone-tooltip {
            position: fixed;
            z-index: 1060;
            pointer-events: none;
            background: rgba(24, 28, 32, 0.95);
            color: #ffffff;
            padding: 6px 12px;
            font-size: 0.8125rem;
            font-weight: 400;
            border-radius: 6px;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
            max-width: 320px;
            white-space: normal;
            opacity: 0;
            transition: opacity 0.12s ease;
            transform: translate(-50%, -100%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(4px);
        }
        .pz-zone-tooltip.show {
            opacity: 1;
        }
        .pz-tooltip-zone-title {
            font-weight: 700;
            font-size: 0.875rem;
            color: #90caf9;
            margin-bottom: 2px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.15);
            padding-bottom: 3px;
        }
        .pz-tooltip-mark-item {
            margin-top: 6px;
            padding-top: 4px;
            border-top: 1px dashed rgba(255, 255, 255, 0.15);
        }
        .pz-tooltip-mark-item:first-of-type {
            margin-top: 4px;
            padding-top: 0;
            border-top: none;
        }
        .pz-tooltip-badge {
            display: inline-block;
            font-weight: 700;
            font-size: 0.75rem;
            line-height: 1;
            padding: 2px 6px;
            border-radius: 10px;
            margin-right: 6px;
            color: #ffffff;
        }
        .pz-tooltip-detail {
            font-size: 0.75rem;
            color: #e0e0e0;
            margin-top: 2px;
            line-height: 1.25;
        }
        .pz-mark-pin {
            cursor: pointer;
        }
        .pz-mark-pin circle {
            filter: drop-shadow(0px 2px 4px rgba(0, 0, 0, 0.45));
            transition: filter 0.15s ease, stroke 0.15s ease, stroke-width 0.15s ease;
        }
        .pz-mark-pin:hover circle {
            filter: drop-shadow(0px 0px 8px rgba(255, 255, 255, 0.85)) drop-shadow(0px 3px 6px rgba(0, 0, 0, 0.6)) brightness(1.25);
            stroke: #ffffff !important;
            stroke-width: 3px !important;
        }
        .pz-scale-bar {
            height: 24px;
            border: 1px solid rgba(0, 0, 0, 0.15);
            background-color: #f8f9fa;
        }
        .pz-scale-cell {
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1;
            user-select: none;
            cursor: pointer;
            transition: filter 0.15s ease, box-shadow 0.15s ease;
            border-right: 1px solid rgba(0, 0, 0, 0.1);
        }
        .pz-scale-cell:last-child {
            border-right: none;
        }
        .pz-scale-cell:hover {
            filter: brightness(1.2);
            box-shadow: inset 0 0 0 2px #ffffff, 0 2px 4px rgba(0, 0, 0, 0.25);
            z-index: 2;
        }
        .pz-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.55);
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pz-modal-dialog {
            background: #ffffff;
            border-radius: 8px;
            width: 95%;
            max-width: 540px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4);
            padding: 20px;
        }
        .pz-mark-card {
            border-left: 4px solid #1976D2;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }
        .pz-mark-card:hover {
            background-color: #f8f9fa;
        }
        #pz-error-banner {
            white-space: pre-line;
        }
    </style>
</head>
<body class="body_top bg-light">
<div class="pz-container">
    <input type="hidden" id="pz-csrf" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken($session)); ?>" />

    <!-- Top Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0 text-primary">
            <?php echo xlt('Pain Zones'); ?>
            <?php if ($formId > 0): ?>
                <span class="badge badge-secondary ml-2"><?php echo xlt('Edit Mode'); ?></span>
            <?php endif; ?>
            <?php if (!$canWrite): ?>
                <span class="badge badge-warning ml-2"><?php echo xlt('Read Only'); ?></span>
            <?php endif; ?>
        </h3>
        <div class="d-flex">
            <button type="button" id="pz-compare-btn" class="btn btn-outline-secondary mr-2"><?php echo xlt('Compare Visits'); ?></button>
            <button type="button" id="pz-cancel-btn" class="btn btn-secondary mr-2"><?php echo xlt('Cancel'); ?></button>
            <?php if ($canWrite): ?>
                <button type="button" id="pz-save-btn" class="btn btn-primary"><?php echo xlt('Save'); ?></button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Error Banner -->
    <div id="pz-error-banner" class="alert alert-danger" style="display:none;" role="alert"></div>

    <?php if ($sexUndefined): ?>
        <div class="alert alert-warning py-2 mb-3" role="alert">
            <?php echo xlt('Patient sex is not defined (Sex at birth is empty or Unknown). The male diagram is shown; please complete the sex in the patient record.'); ?>
        </div>
    <?php endif; ?>

    <!-- Selectors: Figure (fixed to the patient sex) & Body View Radios -->
    <div class="d-flex flex-wrap align-items-center p-2 bg-white border rounded shadow-sm mb-3">
        <div class="d-flex align-items-center">
            <span class="font-weight-bold text-secondary mr-2"><?php echo xlt('Figure:'); ?></span>
            <?php if (count($figureChoices) > 1): ?>
                <select id="pz-figure-select" name="figure" class="form-control form-control-sm mr-3" style="width:auto;">
                    <?php foreach ($figureChoices as $figValue): ?>
                        <option value="<?php echo attr($figValue); ?>"<?php echo $initialFigure === $figValue ? ' selected' : ''; ?>><?php echo text($figureLabels[$figValue]); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <div id="pz-figure-fixed" class="form-control form-control-sm bg-light text-muted mr-3" style="width:auto;"><?php echo text($figureLabel); ?></div>
            <?php endif; ?>
        </div>
        <div class="border-left mx-3 my-1" style="width: 1px; height: 24px;"></div>
        <div class="d-flex align-items-center">
            <span class="font-weight-bold text-secondary mr-2"><?php echo xlt('View:'); ?></span>
            <div class="form-check form-check-inline mb-0 mr-3">
                <input class="form-check-input" type="radio" name="body_view" id="pz-view-ant" value="anterior"<?php echo $initialView === 'anterior' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="pz-view-ant" id="pz-lbl-view-ant"><?php echo xlt('Anterior'); ?> (<?php echo (int)$counts[$initialFigure]['anterior']; ?>)</label>
            </div>
            <div class="form-check form-check-inline mb-0">
                <input class="form-check-input" type="radio" name="body_view" id="pz-view-post" value="posterior"<?php echo $initialView === 'posterior' ? ' checked' : ''; ?>>
                <label class="form-check-label" for="pz-view-post" id="pz-lbl-view-post"><?php echo xlt('Posterior'); ?> (<?php echo (int)$counts[$initialFigure]['posterior']; ?>)</label>
            </div>
        </div>
    </div>

    <!-- Main Workspace -->
    <div class="row">
        <!-- Anatomical Map Column -->
        <div class="col-lg-7 col-md-6 mb-3">
            <div class="card h-100 shadow-sm d-flex flex-column">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center flex-wrap">
                    <span class="font-weight-bold text-dark"><?php echo xlt('Anatomical Map'); ?></span>
                    <?php if ($canWrite): ?>
                        <small class="text-muted"><?php echo xlt('Wheel: Zoom | Right-click: Pan | Left-click: Mark'); ?></small>
                    <?php else: ?>
                        <small class="text-muted"><?php echo xlt('Wheel: Zoom | Right-click: Pan | Left-click: View'); ?></small>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0 position-relative bg-white flex-grow-1" style="overflow:hidden;">
                    <div id="pz-map-viewport" class="pz-map-viewport">
                        <div id="pz-svg-container" class="pz-svg-wrapper w-100 text-center"></div>
                        <div id="pz-zone-tooltip" class="pz-zone-tooltip" style="display:none;" role="tooltip"></div>

                        <!-- Floating Zoom & Pan Controls -->
                        <div class="pz-zoom-controls btn-group-vertical shadow-sm" role="group" aria-label="<?php echo xla('Zoom Controls'); ?>">
                            <button type="button" class="btn btn-sm btn-light border pz-zoom-btn" id="pz-zoom-in" title="<?php echo xla('Zoom In'); ?>" aria-label="<?php echo xla('Zoom In'); ?>">
                                <span style="font-size:1.15rem; line-height:1; font-weight:bold;">+</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-light border pz-zoom-btn" id="pz-zoom-reset" title="<?php echo xla('Reset Zoom'); ?>" aria-label="<?php echo xla('Reset Zoom'); ?>">
                                <span id="pz-zoom-label" class="pz-zoom-label">100%</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-light border pz-zoom-btn" id="pz-zoom-out" title="<?php echo xla('Zoom Out'); ?>" aria-label="<?php echo xla('Zoom Out'); ?>">
                                <span style="font-size:1.15rem; line-height:1; font-weight:bold;">&minus;</span>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white border-top py-2 px-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="small font-weight-bold text-muted"><?php echo xlt('Little pain'); ?></span>
                        <span class="small font-weight-bold text-muted text-uppercase" style="font-size: 0.72rem; letter-spacing: 0.5px;"><?php echo xlt('Pain Scale (0 - 10)'); ?></span>
                        <span class="small font-weight-bold text-muted"><?php echo xlt('Extreme pain'); ?></span>
                    </div>
                    <div class="pz-scale-bar d-flex rounded overflow-hidden shadow-sm">
                        <?php
                        $levelDesc = [
                            0  => xla('0: No pain'),
                            1  => xla('1: Mild pain'),
                            2  => xla('2: Mild pain'),
                            3  => xla('3: Mild pain'),
                            4  => xla('4: Moderate pain'),
                            5  => xla('5: Moderate pain'),
                            6  => xla('6: Moderate pain'),
                            7  => xla('7: Severe pain'),
                            8  => xla('8: Severe pain'),
                            9  => xla('9: Severe pain'),
                            10 => xla('10: Extreme pain'),
                        ];
                        for ($i = 0; $i <= 10; $i++):
                            $pal = $palette['level_' . $i] ?? ['fill' => '#BDBDBD', 'stroke' => '#848484'];
                            $textColor = in_array($i, [0, 4, 5, 6, 7], true) ? '#212529' : '#ffffff';
                        ?>
                            <div class="pz-scale-cell flex-fill d-flex align-items-center justify-content-center"
                                 data-level="<?php echo $i; ?>"
                                 style="background-color: <?php echo attr($pal['fill']); ?>; color: <?php echo attr($textColor); ?>;"
                                 title="<?php echo attr($levelDesc[$i]); ?>">
                                <?php echo $i; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Column -->
        <div class="col-lg-5 col-md-6 mb-3">
            <div class="card h-100 shadow-sm d-flex flex-column">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <span class="font-weight-bold text-dark"><?php echo xlt('Pain Marks'); ?></span>
                </div>
                <div class="card-body p-2 flex-grow-1 overflow-auto" style="max-height: 48vh;">
                    <div id="pz-marks-list"></div>
                </div>
                <div class="card-footer bg-white p-3 border-top">
                    <label for="pz-general-notes" class="font-weight-bold small mb-1"><?php echo xlt('General Notes:'); ?></label>
                    <textarea id="pz-general-notes" name="general_notes" class="form-control" rows="3"<?php echo !$canWrite ? ' readonly' : ''; ?>><?php echo text($generalNotes); ?></textarea>

                    <div class="d-flex justify-content-between align-items-center mt-2 mb-1">
                        <label for="pz-summary-text" class="font-weight-bold small mb-0"><?php echo xlt('Summary Text:'); ?></label>
                        <?php if ($canWrite): ?>
                        <button type="button" id="pz-summary-regenerate" class="btn btn-link btn-sm p-0 small"><?php echo xlt('Regenerate'); ?></button>
                        <?php endif; ?>
                    </div>
                    <textarea id="pz-summary-text" name="summary_text" class="form-control" rows="4"<?php echo !$canWrite ? ' readonly' : ''; ?>><?php echo text($summaryText); ?></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Dialog for Mark Details -->
<div id="pz-modal" class="pz-modal-backdrop" style="display:none;" role="dialog" aria-modal="true">
    <div class="pz-modal-dialog">
        <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
            <h5 id="pz-modal-title" class="mb-0 text-primary"></h5>
            <button type="button" id="pz-modal-close-x" class="close" aria-label="<?php echo xla('Close'); ?>">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="mb-3">
            <div id="pz-modal-zone" class="font-weight-bold text-secondary mb-2"></div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="pz-modal-intensity" class="font-weight-bold mb-0"><?php echo xlt('Pain Intensity (0 - 10):'); ?></label>
                    <span id="pz-modal-intensity-val" class="badge badge-pill px-3 py-1">0</span>
                </div>
                <input type="range" class="custom-range" id="pz-modal-intensity" min="0" max="10" step="1" value="0">
            </div>

            <div class="row mb-2">
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-quality" class="small font-weight-bold mb-0"><?php echo xlt('Pain Quality:'); ?></label>
                    <select id="pz-modal-quality" class="form-control form-control-sm">
                        <option value=""><?php echo xlt('-- Select --'); ?></option>
                        <?php foreach ($qualityOptions as $optId => $optTitle): ?>
                            <option value="<?php echo attr($optId); ?>"><?php echo xlt($optTitle); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="pz-modal-quality-custom" class="form-control form-control-sm mt-1" style="display:none;" maxlength="64" placeholder="<?php echo xla('Specify other pain quality'); ?>">
                </div>
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-radiation" class="small font-weight-bold mb-0"><?php echo xlt('Radiation:'); ?></label>
                    <input type="text" id="pz-modal-radiation" class="form-control form-control-sm" maxlength="255" placeholder="<?php echo xla('e.g. Radiates to leg'); ?>">
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-onset" class="small font-weight-bold mb-0"><?php echo xlt('Onset:'); ?></label>
                    <input type="text" id="pz-modal-onset" class="form-control form-control-sm" maxlength="100" placeholder="<?php echo xla('e.g. 2 days ago'); ?>">
                </div>
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-duration" class="small font-weight-bold mb-0"><?php echo xlt('Duration:'); ?></label>
                    <input type="text" id="pz-modal-duration" class="form-control form-control-sm" maxlength="100" placeholder="<?php echo xla('e.g. Intermittent, 1 hour'); ?>">
                </div>
            </div>

            <div class="row mb-2">
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-aggravating" class="small font-weight-bold mb-0"><?php echo xlt('Aggravating Factors:'); ?></label>
                    <input type="text" id="pz-modal-aggravating" class="form-control form-control-sm" maxlength="255" placeholder="<?php echo xla('e.g. Walking, flexion'); ?>">
                </div>
                <div class="col-sm-6 mb-2">
                    <label for="pz-modal-relieving" class="small font-weight-bold mb-0"><?php echo xlt('Relieving Factors:'); ?></label>
                    <input type="text" id="pz-modal-relieving" class="form-control form-control-sm" maxlength="255" placeholder="<?php echo xla('e.g. Rest, ice'); ?>">
                </div>
            </div>

            <div class="mb-2">
                <label for="pz-modal-notes" class="small font-weight-bold mb-0"><?php echo xlt('Mark Notes:'); ?></label>
                <textarea id="pz-modal-notes" class="form-control form-control-sm" rows="2" maxlength="5000" placeholder="<?php echo xla('Specific notes for this zone'); ?>"></textarea>
            </div>
        </div>
        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
            <button type="button" id="pz-modal-delete" class="btn btn-outline-danger btn-sm" style="display:none;"><?php echo xlt('Delete Mark'); ?></button>
            <div class="ml-auto d-flex">
                <button type="button" id="pz-modal-cancel" class="btn btn-secondary btn-sm mr-2"><?php echo xlt('Cancel'); ?></button>
                <button type="button" id="pz-modal-confirm" class="btn btn-primary btn-sm"><?php echo xlt('Confirm'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    const PZ = {
        canWrite: <?php echo $canWrite ? 'true' : 'false'; ?>,
        dirty: false,
        clientCounter: 0,
        currentFigure: <?php echo json_encode($initialFigure, $jsonFlags); ?>,
        currentView: <?php echo json_encode($initialView, $jsonFlags); ?>,
        palette: <?php echo json_encode($palette, $jsonFlags); ?>,
        validZones: <?php echo json_encode($validZones, $jsonFlags); ?>,
        zoneLabels: <?php echo json_encode($zoneLabels, $jsonFlags); ?>,
        qualityOptions: <?php echo json_encode(array_keys($qualityOptions), $jsonFlags); ?>,
        qualityLabels: <?php echo json_encode($qualityLabels, $jsonFlags); ?>,
        bandLabels: <?php echo json_encode([
            'none'     => xl('None'),
            'mild'     => xl('Mild'),
            'moderate' => xl('Moderate'),
            'severe'   => xl('Severe'),
        ], $jsonFlags); ?>,
        summaryLabels: <?php echo json_encode([
            'quality'      => xl('Quality'),
            'radiation'    => xl('Radiation'),
            'onset'        => xl('Onset'),
            'duration'     => xl('Duration'),
            'aggravating'  => xl('Aggravating'),
            'relieving'    => xl('Relieving'),
            'notes'        => xl('Notes'),
            'generalNotes' => xl('General Notes'),
            'noMarks'      => xl('No pain marks recorded.'),
        ], $jsonFlags); ?>,
        svgContent: <?php echo json_encode($svgContent, $jsonFlags); ?>,
        marks: <?php echo json_encode($clientMarks, $jsonFlags); ?>,
        loadedIds: <?php echo json_encode($loadedIds, $jsonFlags); ?>,
        saveUrl: <?php echo js_escape($saveUrl); ?>,
        exitUrl: 'javascript:parent.closeTab(window.name, false)'
    };

    let currentModalContext = null;
    let originalZoneStyles = new Map();

    // Zoom and Pan State
    let zoomScale = 1.0;
    let panX = 0;
    let panY = 0;
    let isPanning = false;
    let panStartX = 0;
    let panStartY = 0;
    let clickStartX = 0;
    let clickStartY = 0;

    const MIN_ZOOM = 1.0;
    const MAX_ZOOM = 5.0;
    const ZOOM_STEP = 1.25;

    function applyZoomPan(animate = false) {
        const container = document.getElementById('pz-svg-container');
        if (!container) return;

        if (animate) {
            container.style.transition = 'transform 0.18s cubic-bezier(0.2, 0.8, 0.2, 1)';
        } else {
            container.style.transition = 'none';
        }

        container.style.transform = `translate(${panX}px, ${panY}px) scale(${zoomScale})`;

        const label = document.getElementById('pz-zoom-label');
        if (label) {
            label.textContent = Math.round(zoomScale * 100) + '%';
        }
    }

    function resetZoomPan(animate = true) {
        zoomScale = 1.0;
        panX = 0;
        panY = 0;
        applyZoomPan(animate);
    }

    function zoomAtPoint(factor, clientX, clientY) {
        const viewport = document.getElementById('pz-map-viewport');
        if (!viewport) return;

        const rect = viewport.getBoundingClientRect();
        const originX = (clientX !== undefined) ? (clientX - rect.left) : (rect.width / 2);
        const originY = (clientY !== undefined) ? (clientY - rect.top) : (rect.height / 2);

        let newScale = zoomScale * factor;
        newScale = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, newScale));

        if (Math.abs(newScale - zoomScale) < 0.001) return;

        if (newScale <= MIN_ZOOM) {
            resetZoomPan(true);
            return;
        }

        panX = originX - (originX - panX) * (newScale / zoomScale);
        panY = originY - (originY - panY) * (newScale / zoomScale);
        zoomScale = newScale;

        applyZoomPan(true);
    }

    const SHAPE_TAGS = ['path', 'rect', 'circle', 'ellipse', 'polygon', 'polyline'];

    // Initialize dirty tracking on general notes
    const generalNotesEl = document.getElementById('pz-general-notes');
    if (generalNotesEl) {
        generalNotesEl.addEventListener('input', () => {
            PZ.dirty = true;
        });
    }

    // Navigation warning
    window.addEventListener('beforeunload', (e) => {
        if (PZ.dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Close modal on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' || e.key === 'Esc') {
            const modal = document.getElementById('pz-modal');
            if (modal && modal.style.display !== 'none') {
                closeMarkModal();
            }
        }
    });

    // Helper: update visual background of slider badge
    function updateSliderVisuals(rawVal) {
        let val = parseInt(rawVal, 10);
        if (isNaN(val) || val < 0) val = 0;
        if (val > 10) val = 10;
        const badge = document.getElementById('pz-modal-intensity-val');
        const pal = PZ.palette['level_' + val] || PZ.palette['level_0'];
        if (badge) {
            badge.textContent = String(val);
            badge.style.backgroundColor = pal.fill;
            badge.style.color = '#ffffff';
            badge.style.border = '1px solid ' + pal.stroke;
        }
    }

    document.getElementById('pz-modal-intensity').addEventListener('input', (e) => {
        updateSliderVisuals(e.target.value);
    });

    // Clicking a color block on the pain scale updates the slider when the modal is open
    document.querySelectorAll('.pz-scale-cell').forEach(cell => {
        cell.addEventListener('click', () => {
            const lvl = cell.getAttribute('data-level');
            const modal = document.getElementById('pz-modal');
            const slider = document.getElementById('pz-modal-intensity');
            if (modal && modal.style.display !== 'none' && slider && lvl !== null && PZ.canWrite) {
                slider.value = lvl;
                updateSliderVisuals(lvl);
            }
        });
    });

    // Modal display logic (supports read-only mode)
    function openMarkModal(context) {
        hideZoneTooltip();
        currentModalContext = context;
        const { isNew, mark } = context;

        const titleEl = document.getElementById('pz-modal-title');
        if (!PZ.canWrite) {
            titleEl.textContent = <?php echo xlj('View Pain Mark'); ?>;
        } else {
            titleEl.textContent = isNew
                ? <?php echo xlj('Add Pain Mark'); ?>
                : <?php echo xlj('Edit Pain Mark'); ?>;
        }

        document.getElementById('pz-modal-zone').textContent =
            <?php echo xlj('Anatomical Zone: '); ?> + formatZoneDisplay(mark.zone_id);

        const slider = document.getElementById('pz-modal-intensity');
        slider.value = mark.intensity;
        updateSliderVisuals(mark.intensity);

        const qualitySelect = document.getElementById('pz-modal-quality');
        const qualityCustom = document.getElementById('pz-modal-quality-custom');
        const radiationEl   = document.getElementById('pz-modal-radiation');
        const onsetEl       = document.getElementById('pz-modal-onset');
        const durationEl    = document.getElementById('pz-modal-duration');
        const aggravatingEl = document.getElementById('pz-modal-aggravating');
        const relievingEl   = document.getElementById('pz-modal-relieving');
        const notesEl       = document.getElementById('pz-modal-notes');

        const otherKey = PZ.qualityOptions.find(o => o.toLowerCase() === 'other') || 'other';
        const currentQuality = mark.pain_quality || '';
        if (currentQuality && !PZ.qualityOptions.includes(currentQuality)) {
            qualitySelect.value = otherKey;
            qualityCustom.value = currentQuality;
            qualityCustom.style.display = 'block';
        } else {
            qualitySelect.value = currentQuality;
            qualityCustom.value = '';
            qualityCustom.style.display = (currentQuality && currentQuality.toLowerCase() === 'other') ? 'block' : 'none';
        }
        radiationEl.value   = mark.radiation || '';
        onsetEl.value       = mark.onset || '';
        durationEl.value    = mark.duration || '';
        aggravatingEl.value = mark.aggravating || '';
        relievingEl.value   = mark.relieving || '';
        notesEl.value       = mark.notes || '';

        const delBtn     = document.getElementById('pz-modal-delete');
        const confirmBtn = document.getElementById('pz-modal-confirm');
        const cancelBtn  = document.getElementById('pz-modal-cancel');

        if (!PZ.canWrite) {
            // Read-only modal: disable all inputs, hide Confirm/Delete, show Close
            slider.disabled        = true;
            qualitySelect.disabled = true;
            qualityCustom.disabled = true;
            radiationEl.disabled   = true;
            onsetEl.disabled       = true;
            durationEl.disabled    = true;
            aggravatingEl.disabled = true;
            relievingEl.disabled   = true;
            notesEl.disabled       = true;

            delBtn.style.display     = 'none';
            confirmBtn.style.display = 'none';
            cancelBtn.style.display  = 'inline-block';
            cancelBtn.textContent    = <?php echo xlj('Close'); ?>;
        } else {
            // Writable modal: enable inputs, show buttons accordingly
            slider.disabled        = false;
            qualitySelect.disabled = false;
            qualityCustom.disabled = false;
            radiationEl.disabled   = false;
            onsetEl.disabled       = false;
            durationEl.disabled    = false;
            aggravatingEl.disabled = false;
            relievingEl.disabled   = false;
            notesEl.disabled       = false;

            delBtn.style.display     = isNew ? 'none' : 'inline-block';
            confirmBtn.style.display = 'inline-block';
            confirmBtn.textContent   = <?php echo xlj('Confirm'); ?>;
            cancelBtn.style.display  = 'inline-block';
            cancelBtn.textContent    = <?php echo xlj('Cancel'); ?>;
        }

        document.getElementById('pz-modal').style.display = 'flex';
    }

    function closeMarkModal() {
        hideZoneTooltip();
        const qualitySelect = document.getElementById('pz-modal-quality');
        const qualityCustom = document.getElementById('pz-modal-quality-custom');
        if (qualitySelect) qualitySelect.value = '';
        if (qualityCustom) {
            qualityCustom.value = '';
            qualityCustom.style.display = 'none';
        }
        document.getElementById('pz-modal').style.display = 'none';
        currentModalContext = null;
    }

    document.getElementById('pz-modal-quality').addEventListener('change', (e) => {
        const customEl = document.getElementById('pz-modal-quality-custom');
        if (e.target.value.toLowerCase() === 'other') {
            customEl.style.display = 'block';
            customEl.focus();
        } else {
            customEl.style.display = 'none';
            customEl.value = '';
        }
    });

    document.getElementById('pz-modal-close-x').addEventListener('click', closeMarkModal);
    document.getElementById('pz-modal-cancel').addEventListener('click', closeMarkModal);

    document.getElementById('pz-modal-confirm').addEventListener('click', () => {
        if (!PZ.canWrite || !currentModalContext) return;
        const { isNew, mark } = currentModalContext;
        const slider = document.getElementById('pz-modal-intensity');

        mark.intensity    = parseInt(slider.value, 10);
        const qualitySelect = document.getElementById('pz-modal-quality');
        const qualityCustom = document.getElementById('pz-modal-quality-custom');
        let qualityVal = qualitySelect.value.trim();
        if (qualityVal.toLowerCase() === 'other') {
            const customVal = qualityCustom.value.trim();
            if (customVal !== '') {
                qualityVal = customVal;
            }
        }
        mark.pain_quality = qualityVal;
        mark.radiation    = document.getElementById('pz-modal-radiation').value.trim();
        mark.onset        = document.getElementById('pz-modal-onset').value.trim();
        mark.duration     = document.getElementById('pz-modal-duration').value.trim();
        mark.aggravating  = document.getElementById('pz-modal-aggravating').value.trim();
        mark.relieving    = document.getElementById('pz-modal-relieving').value.trim();
        mark.notes        = document.getElementById('pz-modal-notes').value.trim();

        if (isNew) {
            PZ.marks.push(mark);
        }

        PZ.dirty = true;
        closeMarkModal();
        renderMarksOnSvg();
        renderSidebarMarks();
        updateRadioCounters();
        refreshSummaryText();
    });

    document.getElementById('pz-modal-delete').addEventListener('click', () => {
        if (!PZ.canWrite || !currentModalContext || currentModalContext.isNew) return;
        const { mark } = currentModalContext;
        if (confirm(<?php echo xlj('Are you sure you want to delete this pain mark?'); ?>)) {
            PZ.marks = PZ.marks.filter(m => m._client_id !== mark._client_id);
            PZ.dirty = true;
            closeMarkModal();
            renderMarksOnSvg();
            renderSidebarMarks();
            updateRadioCounters();
            refreshSummaryText();
        }
    });

    const summaryRegenerateBtn = document.getElementById('pz-summary-regenerate');
    if (summaryRegenerateBtn) {
        summaryRegenerateBtn.addEventListener('click', refreshSummaryText);
    }

    // Collect shape elements belonging to a zone, stopping at child valid zones
    function collectShapeElements(node, isRoot, activeZones) {
        const shapes = [];
        if (!isRoot && node.id && activeZones.includes(node.id)) {
            return shapes;
        }
        const tag = node.tagName.toLowerCase();
        if (SHAPE_TAGS.includes(tag)) {
            shapes.push(node);
        }
        for (let i = 0; i < node.children.length; i++) {
            shapes.push(...collectShapeElements(node.children[i], false, activeZones));
        }
        return shapes;
    }

    function formatZoneDisplay(zoneId) {
        if (!zoneId) return '';
        if (PZ.zoneLabels && PZ.zoneLabels[zoneId]) {
            return PZ.zoneLabels[zoneId];
        }
        return zoneId
            .split('_')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
            .join(' ');
    }

    // Mirrors pz_intensityBandLabel() on the server.
    function intensityBandLabel(intensity) {
        const n = parseInt(intensity, 10) || 0;
        let band = 'severe';
        if (n <= 0) band = 'none';
        else if (n <= 3) band = 'mild';
        else if (n <= 6) band = 'moderate';
        return (PZ.bandLabels && PZ.bandLabels[band]) ? PZ.bandLabels[band] : band;
    }

    // Mirrors pz_buildSummary() on the server so the preview matches what is stored.
    function buildSummaryText() {
        const lbl = PZ.summaryLabels || {};
        const fieldDefs = [
            ['pain_quality', lbl.quality     || 'Quality',     true],
            ['radiation',    lbl.radiation   || 'Radiation',   false],
            ['onset',        lbl.onset       || 'Onset',       false],
            ['duration',     lbl.duration    || 'Duration',    false],
            ['aggravating',  lbl.aggravating || 'Aggravating', false],
            ['relieving',    lbl.relieving   || 'Relieving',   false],
            ['notes',        lbl.notes       || 'Notes',       false],
        ];

        const active = PZ.marks.filter(m => m.active !== 0);
        const sorted = active.slice().sort((a, b) =>
            (parseInt(b.intensity, 10) || 0) - (parseInt(a.intensity, 10) || 0)
        );

        const lines = [];
        if (sorted.length === 0) {
            lines.push(lbl.noMarks || 'No pain marks recorded.');
        } else {
            sorted.forEach(m => {
                const n = parseInt(m.intensity, 10) || 0;
                const parts = [
                    formatZoneDisplay(m.zone_id) + ': ' + intensityBandLabel(n) + ' ' + n + '/10'
                ];
                fieldDefs.forEach(def => {
                    let value = (m[def[0]] == null) ? '' : String(m[def[0]]).trim();
                    if (value === '') return;
                    if (def[2] && PZ.qualityLabels && PZ.qualityLabels[value]) {
                        value = PZ.qualityLabels[value];
                    }
                    parts.push(def[1] + ': ' + value);
                });
                lines.push(parts.join('; '));
            });
        }

        const generalNotesEl = document.getElementById('pz-general-notes');
        const generalNotes = generalNotesEl ? generalNotesEl.value.trim() : '';
        if (generalNotes !== '') {
            lines.push((lbl.generalNotes || 'General Notes') + ': ' + generalNotes);
        }

        return lines.join('\n');
    }

    // Auto-fill the editable summary preview (never in read-only mode).
    function refreshSummaryText() {
        if (!PZ.canWrite) return;
        const el = document.getElementById('pz-summary-text');
        if (el) {
            el.value = buildSummaryText();
        }
    }

    function findZoneIdFromTarget(target, svg) {
        if (!svg || !target) return null;
        const activeZones = PZ.validZones[PZ.currentFigure][PZ.currentView] || [];

        let curr = target;
        while (curr && curr !== svg) {
            if (curr.classList && curr.classList.contains('pz-mark-pin')) {
                const markId = curr.getAttribute('data-client-id');
                const mark = PZ.marks.find(m => m._client_id === markId && m.active !== 0);
                if (mark && mark.zone_id) {
                    return mark.zone_id;
                }
            }
            if (curr.id && activeZones.includes(curr.id)) {
                return curr.id;
            }
            curr = curr.parentElement;
        }
        return null;
    }

    function buildZoneTooltipContent(zoneId) {
        if (!zoneId) return null;

        const zoneMarks = PZ.marks.filter(m =>
            m.active !== 0 &&
            m.figure === PZ.currentFigure &&
            m.body_view === PZ.currentView &&
            m.zone_id === zoneId
        );

        const container = document.createElement('div');

        const titleDiv = document.createElement('div');
        titleDiv.className = 'pz-tooltip-zone-title';
        titleDiv.textContent = formatZoneDisplay(zoneId);
        container.appendChild(titleDiv);

        if (zoneMarks.length === 0) {
            return container;
        }

        zoneMarks.forEach(m => {
            const markDiv = document.createElement('div');
            markDiv.className = 'pz-tooltip-mark-item';

            const headerRow = document.createElement('div');
            headerRow.className = 'd-flex align-items-center mb-1';

            const badge = document.createElement('span');
            badge.className = 'pz-tooltip-badge';
            const pal = PZ.palette['level_' + m.intensity] || PZ.palette['level_0'];
            badge.style.backgroundColor = pal.fill;
            badge.style.border = '1px solid ' + pal.stroke;
            badge.textContent = <?php echo xlj('Intensity: '); ?> + m.intensity;
            headerRow.appendChild(badge);

            if (m.pain_quality) {
                const qSpan = document.createElement('span');
                qSpan.className = 'font-weight-bold text-white small';
                qSpan.textContent = PZ.qualityLabels[m.pain_quality] || m.pain_quality;
                headerRow.appendChild(qSpan);
            }

            markDiv.appendChild(headerRow);

            if (m.radiation) {
                const radDiv = document.createElement('div');
                radDiv.className = 'pz-tooltip-detail';
                radDiv.textContent = <?php echo xlj('Radiation: '); ?> + m.radiation;
                markDiv.appendChild(radDiv);
            }

            if (m.onset || m.duration) {
                const timeDiv = document.createElement('div');
                timeDiv.className = 'pz-tooltip-detail';
                const parts = [];
                if (m.onset) parts.push(<?php echo xlj('Onset: '); ?> + m.onset);
                if (m.duration) parts.push(<?php echo xlj('Duration: '); ?> + m.duration);
                timeDiv.textContent = parts.join(' | ');
                markDiv.appendChild(timeDiv);
            }

            if (m.aggravating) {
                const aggDiv = document.createElement('div');
                aggDiv.className = 'pz-tooltip-detail';
                aggDiv.textContent = <?php echo xlj('Aggravating: '); ?> + m.aggravating;
                markDiv.appendChild(aggDiv);
            }

            if (m.relieving) {
                const relDiv = document.createElement('div');
                relDiv.className = 'pz-tooltip-detail';
                relDiv.textContent = <?php echo xlj('Relieving: '); ?> + m.relieving;
                markDiv.appendChild(relDiv);
            }

            if (m.notes) {
                const notesDiv = document.createElement('div');
                notesDiv.className = 'pz-tooltip-detail font-italic';
                notesDiv.textContent = <?php echo xlj('Note: '); ?> + m.notes;
                markDiv.appendChild(notesDiv);
            }

            container.appendChild(markDiv);
        });

        return container;
    }

    function showZoneTooltip(e, content) {
        const tooltip = document.getElementById('pz-zone-tooltip');
        if (!tooltip || !content) return;

        tooltip.textContent = '';
        if (typeof content === 'string') {
            tooltip.textContent = content;
        } else if (content instanceof Node) {
            tooltip.appendChild(content);
        }

        tooltip.style.display = 'block';

        const offset = 14;
        let x = e.clientX;
        let y = e.clientY - offset;

        const rect = tooltip.getBoundingClientRect();
        const halfWidth = rect.width / 2;

        if (x - halfWidth < 8) {
            x = halfWidth + 8;
        } else if (x + halfWidth > window.innerWidth - 8) {
            x = window.innerWidth - halfWidth - 8;
        }

        if (y - rect.height < 8) {
            y = e.clientY + offset;
            tooltip.style.transform = 'translate(-50%, 0)';
        } else {
            tooltip.style.transform = 'translate(-50%, -100%)';
        }

        tooltip.style.left = Math.round(x) + 'px';
        tooltip.style.top = Math.round(y) + 'px';
        tooltip.classList.add('show');
    }

    function hideZoneTooltip() {
        const tooltip = document.getElementById('pz-zone-tooltip');
        if (!tooltip) return;
        tooltip.classList.remove('show');
        tooltip.style.display = 'none';
    }

    function onSvgMouseMove(event) {
        const container = document.getElementById('pz-svg-container');
        const svg = container ? container.querySelector('svg') : null;
        if (!svg) {
            hideZoneTooltip();
            return;
        }

        const zoneId = findZoneIdFromTarget(event.target, svg);
        if (zoneId) {
            showZoneTooltip(event, buildZoneTooltipContent(zoneId));
        } else {
            hideZoneTooltip();
        }
    }

    function onSvgMouseLeave() {
        hideZoneTooltip();
    }

    // SVG Rendering and interaction
    function renderSvg() {
        hideZoneTooltip();
        const container = document.getElementById('pz-svg-container');
        container.innerHTML = PZ.svgContent[PZ.currentFigure][PZ.currentView] || '';

        // Reset saved original styles because SVG DOM was reinjected
        originalZoneStyles = new Map();

        const svg = container.querySelector('svg');
        if (!svg) return;

        // Remove <title> elements from SVG to prevent browser native tooltips
        svg.querySelectorAll('title').forEach(el => el.remove());
        svg.removeAttribute('title');

        svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');

        const activeZones = PZ.validZones[PZ.currentFigure][PZ.currentView] || [];
        activeZones.forEach(zoneId => {
            const el = svg.getElementById(zoneId);
            if (el && PZ.canWrite) {
                el.classList.add('pz-zone-clickable');
            }
        });

        if (PZ.canWrite) {
            svg.addEventListener('click', onSvgClick);
        }

        svg.addEventListener('mousemove', onSvgMouseMove);
        svg.addEventListener('mouseleave', onSvgMouseLeave);

        renderMarksOnSvg();
    }

    function onSvgClick(event) {
        if (!PZ.canWrite) return;

        // If the user dragged more than 6px, treat as drag/pan, not mark placement
        const dragDist = Math.hypot(event.clientX - clickStartX, event.clientY - clickStartY);
        if (dragDist > 6) return;

        const container = document.getElementById('pz-svg-container');
        const svg = container ? container.querySelector('svg') : null;
        if (!svg) return;

        const zoneId = findZoneIdFromTarget(event.target, svg);
        if (!zoneId) return;

        hideZoneTooltip();

        const pt = svg.createSVGPoint();
        pt.x = event.clientX;
        pt.y = event.clientY;
        const ctm = svg.getScreenCTM();
        if (!ctm) return;

        const svgP = pt.matrixTransform(ctm.inverse());
        const vb = svg.viewBox.baseVal;
        if (!vb || vb.width <= 0 || vb.height <= 0) return;

        const xPct = Math.max(0, Math.min(100, ((svgP.x - vb.x) / vb.width) * 100));
        const yPct = Math.max(0, Math.min(100, ((svgP.y - vb.y) / vb.height) * 100));

        let laterality = 'midline';
        if (zoneId.endsWith('_left')) laterality = 'left';
        if (zoneId.endsWith('_right')) laterality = 'right';

        openMarkModal({
            isNew: true,
            mark: {
                db_id: null,
                _client_id: 'new_' + (++PZ.clientCounter),
                figure: PZ.currentFigure,
                body_view: PZ.currentView,
                zone_id: zoneId,
                laterality: laterality,
                x_pct: Number(xPct.toFixed(3)),
                y_pct: Number(yPct.toFixed(3)),
                intensity: 5,
                pain_quality: '',
                radiation: '',
                onset: '',
                duration: '',
                aggravating: '',
                relieving: '',
                notes: ''
            }
        });
    }

    function renderMarksOnSvg() {
        const container = document.getElementById('pz-svg-container');
        const svg = container.querySelector('svg');
        if (!svg) return;

        const activeZones = PZ.validZones[PZ.currentFigure][PZ.currentView] || [];

        // 1. Restore previous tints on zone elements
        originalZoneStyles.forEach((orig, el) => {
            el.style.fill          = orig.fill;
            el.style.fillOpacity   = orig.fillOpacity;
            el.style.stroke        = orig.stroke;
            el.style.strokeWidth   = orig.strokeWidth;
            el.style.strokeOpacity = orig.strokeOpacity;
        });
        originalZoneStyles.clear();

        // 1b. Classify zones as marked or unmarked (for hover feedback)
        const markedZoneIds = new Set();
        PZ.marks.forEach(m => {
            if (m.figure === PZ.currentFigure && m.body_view === PZ.currentView && (m.active === undefined || m.active !== 0)) {
                markedZoneIds.add(m.zone_id);
            }
        });

        activeZones.forEach(zoneId => {
            const zoneEl = svg.getElementById(zoneId);
            if (!zoneEl) return;
            if (markedZoneIds.has(zoneId)) {
                zoneEl.classList.remove('pz-zone-unmarked');
                zoneEl.classList.add('pz-zone-marked');
            } else {
                zoneEl.classList.remove('pz-zone-marked');
                zoneEl.classList.add('pz-zone-unmarked');
            }
        });

        // 2. Find highest intensity (> 0) per zone in current panel
        const maxIntensityByZone = new Map();
        PZ.marks.forEach(m => {
            if (m.figure === PZ.currentFigure && m.body_view === PZ.currentView && m.intensity > 0) {
                const cur = maxIntensityByZone.get(m.zone_id) || 0;
                if (m.intensity > cur) {
                    maxIntensityByZone.set(m.zone_id, m.intensity);
                }
            }
        });

        // 3. Apply zone tints (applies in both read-only and write mode)
        maxIntensityByZone.forEach((highestLvl, zoneId) => {
            const zoneEl = svg.getElementById(zoneId);
            if (!zoneEl) return;

            const pal = PZ.palette['level_' + highestLvl] || PZ.palette['level_0'];
            const shapeElements = collectShapeElements(zoneEl, true, activeZones);

            shapeElements.forEach(el => {
                if (!originalZoneStyles.has(el)) {
                    originalZoneStyles.set(el, {
                        fill: el.style.fill,
                        fillOpacity: el.style.fillOpacity,
                        stroke: el.style.stroke,
                        strokeWidth: el.style.strokeWidth,
                        strokeOpacity: el.style.strokeOpacity
                    });
                }
                el.style.fill        = pal.fill;
                el.style.fillOpacity = '0.45';
                el.style.stroke      = pal.stroke;
                if (!el.style.strokeWidth || el.style.strokeWidth === '0px') {
                    el.style.strokeWidth = '1.5px';
                }
            });
        });

        // 4. Draw mark circles (always on top in #pz-marks-layer)
        let layer = svg.querySelector('#pz-marks-layer');
        if (!layer) {
            layer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            layer.setAttribute('id', 'pz-marks-layer');
            svg.appendChild(layer);
        }
        layer.textContent = '';

        const vb = svg.viewBox.baseVal;
        if (!vb || vb.height <= 0) return;

        const r = Math.max(12, vb.height * 0.016);
        const fontSize = Math.max(11, r * 1.15);

        const viewMarks = PZ.marks.filter(m =>
            m.figure === PZ.currentFigure && m.body_view === PZ.currentView
        );

        viewMarks.forEach(mark => {
            const cx = vb.x + (mark.x_pct / 100) * vb.width;
            const cy = vb.y + (mark.y_pct / 100) * vb.height;
            const pal = PZ.palette['level_' + mark.intensity] || PZ.palette['level_0'];

            const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            g.setAttribute('class', 'pz-mark-pin');
            g.setAttribute('data-client-id', mark._client_id);
            g.style.cursor = 'pointer'; // clickable in both read and write modes

            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('cx', String(cx));
            circle.setAttribute('cy', String(cy));
            circle.setAttribute('r', String(r));
            circle.setAttribute('fill', pal.fill);
            circle.setAttribute('stroke', pal.stroke);
            circle.setAttribute('stroke-width', String(Math.max(2, r * 0.15)));

            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', String(cx));
            text.setAttribute('y', String(cy));
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'central');
            text.setAttribute('font-size', String(fontSize));
            text.setAttribute('font-weight', 'bold');
            text.setAttribute('font-family', 'sans-serif');
            text.setAttribute('fill', '#ffffff');
            text.setAttribute('style', 'pointer-events: none; text-shadow: 0 1px 2px rgba(0,0,0,0.8);');
            text.textContent = String(mark.intensity);

            g.appendChild(circle);
            g.appendChild(text);

            // Clicking mark opens modal (read-only or edit based on PZ.canWrite)
            g.addEventListener('click', (e) => {
                e.stopPropagation();
                const dragDist = Math.hypot(e.clientX - clickStartX, e.clientY - clickStartY);
                if (dragDist > 6) return;
                openMarkModal({ isNew: false, mark: mark });
            });

            layer.appendChild(g);
        });
    }

    // Sidebar list rendering - lists ALL marks across all panels
    function renderSidebarMarks() {
        const listEl = document.getElementById('pz-marks-list');
        listEl.textContent = '';

        if (PZ.marks.length === 0) {
            const emptyDiv = document.createElement('div');
            emptyDiv.className = 'text-muted text-center py-4';
            emptyDiv.textContent = <?php echo xlj('No marks recorded.'); ?>;
            listEl.appendChild(emptyDiv);
            return;
        }

        PZ.marks.forEach(mark => {
            const card = document.createElement('div');
            card.className = 'pz-mark-card card mb-2 shadow-sm';
            if (mark.figure === PZ.currentFigure && mark.body_view === PZ.currentView) {
                card.classList.add('border-primary');
            }

            const cardBody = document.createElement('div');
            cardBody.className = 'card-body p-2';

            const row = document.createElement('div');
            row.className = 'd-flex align-items-center justify-content-between';

            const leftGroup = document.createElement('div');
            leftGroup.className = 'd-flex align-items-center flex-grow-1 mr-2';

            const badge = document.createElement('span');
            badge.className = 'badge badge-pill mr-2 px-2 py-1';
            const pal = PZ.palette['level_' + mark.intensity] || PZ.palette['level_0'];
            badge.style.backgroundColor = pal.fill;
            badge.style.color = '#ffffff';
            badge.style.border = '1px solid ' + pal.stroke;
            badge.style.fontWeight = 'bold';
            badge.textContent = String(mark.intensity);

            const detailsDiv = document.createElement('div');
            const headerLine = document.createElement('div');
            headerLine.className = 'd-flex align-items-center flex-wrap';

            const zoneTitle = document.createElement('span');
            zoneTitle.className = 'font-weight-bold text-dark small mr-2';
            zoneTitle.textContent = formatZoneDisplay(mark.zone_id);
            headerLine.appendChild(zoneTitle);

            // Figure & View badge tag
            const tag = document.createElement('span');
            tag.className = 'badge badge-light border text-muted small';
            const figLabel = (mark.figure === 'adult_female')
                ? <?php echo xlj('Female'); ?>
                : <?php echo xlj('Male'); ?>;
            const viewLabel = (mark.body_view === 'posterior')
                ? <?php echo xlj('Posterior'); ?>
                : <?php echo xlj('Anterior'); ?>;
            tag.textContent = figLabel + ' / ' + viewLabel;
            headerLine.appendChild(tag);

            detailsDiv.appendChild(headerLine);

            if (mark.pain_quality || mark.notes) {
                const sub = document.createElement('div');
                sub.className = 'text-muted small';
                const qualityDisplay = (PZ.qualityLabels && PZ.qualityLabels[mark.pain_quality])
                    ? PZ.qualityLabels[mark.pain_quality]
                    : mark.pain_quality;
                const textSnippet = qualityDisplay || mark.notes;
                sub.textContent = (textSnippet.length > 40) ? textSnippet.substring(0, 40) + '...' : textSnippet;
                detailsDiv.appendChild(sub);
            }

            leftGroup.appendChild(badge);
            leftGroup.appendChild(detailsDiv);
            row.appendChild(leftGroup);

            const handleSelectAndEdit = () => {
                // If mark belongs to another panel, switch panel and update UI
                if (mark.figure !== PZ.currentFigure || mark.body_view !== PZ.currentView) {
                    PZ.currentFigure = mark.figure;
                    PZ.currentView   = mark.body_view;

                    const figSelect = document.getElementById('pz-figure-select');
                    if (figSelect) figSelect.value = mark.figure;

                    const viewRadio = document.querySelector('input[name="body_view"][value="' + mark.body_view + '"]');
                    if (viewRadio) viewRadio.checked = true;

                    renderSvg();
                    updateRadioCounters();
                    renderSidebarMarks();
                }
                openMarkModal({ isNew: false, mark: mark });
            };

            // Card is always clickable to open modal (both read and write)
            card.style.cursor = 'pointer';
            card.addEventListener('click', handleSelectAndEdit);

            if (PZ.canWrite) {
                const btnGroup = document.createElement('div');
                btnGroup.className = 'btn-group btn-group-sm';

                const editBtn = document.createElement('button');
                editBtn.type = 'button';
                editBtn.className = 'btn btn-outline-primary btn-sm';
                editBtn.textContent = <?php echo xlj('Edit'); ?>;
                editBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    handleSelectAndEdit();
                });

                const delBtn = document.createElement('button');
                delBtn.type = 'button';
                delBtn.className = 'btn btn-outline-danger btn-sm';
                delBtn.textContent = <?php echo xlj('Delete'); ?>;
                delBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (confirm(<?php echo xlj('Are you sure you want to delete this mark?'); ?>)) {
                        PZ.marks = PZ.marks.filter(m => m._client_id !== mark._client_id);
                        PZ.dirty = true;
                        renderMarksOnSvg();
                        renderSidebarMarks();
                        updateRadioCounters();
                        refreshSummaryText();
                    }
                });

                btnGroup.appendChild(editBtn);
                btnGroup.appendChild(delBtn);
                row.appendChild(btnGroup);
            }

            cardBody.appendChild(row);
            card.appendChild(cardBody);
            listEl.appendChild(card);
        });
    }

    // Radio counter synchronization
    function updateRadioCounters() {
        let antTotal = 0;
        let postTotal = 0;

        PZ.marks.forEach(m => {
            if (m.figure === PZ.currentFigure) {
                if (m.body_view === 'anterior') antTotal++;
                if (m.body_view === 'posterior') postTotal++;
            }
        });

        const lblAnt = document.getElementById('pz-lbl-view-ant');
        if (lblAnt) lblAnt.textContent = <?php echo xlj('Anterior'); ?> + ' (' + antTotal + ')';

        const lblPost = document.getElementById('pz-lbl-view-post');
        if (lblPost) lblPost.textContent = <?php echo xlj('Posterior'); ?> + ' (' + postTotal + ')';
    }

    // Figure selector: only present when a saved form holds marks on the
    // opposite figure (legacy data). Its purpose is to reach those marks, not
    // to freely pick a figure.
    const figureSelect = document.getElementById('pz-figure-select');
    if (figureSelect) {
        figureSelect.addEventListener('change', (e) => {
            PZ.currentFigure = e.target.value;
            resetZoomPan(false);
            renderSvg();
            renderSidebarMarks();
            updateRadioCounters();
        });
    }

    document.querySelectorAll('input[name="body_view"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            PZ.currentView = e.target.value;
            resetZoomPan(false);
            renderSvg();
            renderSidebarMarks();
            updateRadioCounters();
        });
    });

    // Cancel Button logic
    document.getElementById('pz-cancel-btn').addEventListener('click', () => {
        if (PZ.dirty) {
            if (!confirm(<?php echo xlj('You have unsaved changes. Are you sure you want to exit?'); ?>)) {
                return;
            }
        }
        PZ.dirty = false;
        if (parent && typeof parent.closeTab === 'function') {
            parent.closeTab(window.name, false);
        } else {
            window.close();
        }
    });

    // Compare Visits Button logic
    document.getElementById('pz-compare-btn').addEventListener('click', () => {
        pzOpenCompare();
    });

    // Save logic
    const saveBtn = document.getElementById('pz-save-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', submitForm);
    }

    function showError(message) {
        const banner = document.getElementById('pz-error-banner');
        if (banner) {
            banner.textContent = message;
            banner.style.display = 'block';
            banner.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else {
            alert(message);
        }
    }

    function pzOpenCompare() {
        // Navigate the current encounter tab to the comparison page, matching the
        // AI assistant module: the editor replaces this view inside the same
        // iframe, and its "Go Back" button navigates back to this form.
        if (typeof top.restoreSession === 'function') {
            top.restoreSession();
        } else if (parent && typeof parent.restoreSession === 'function') {
            parent.restoreSession();
        }
        window.location.href = <?php echo json_encode($compareUrl, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    }

    function submitForm() {
        if (!PZ.canWrite) return;

        if (saveBtn) {
            saveBtn.disabled = true;
        }

        const banner = document.getElementById('pz-error-banner');
        if (banner) {
            banner.style.display = 'none';
            banner.textContent = '';
        }

        if (parent && typeof parent.restoreSession === 'function') {
            parent.restoreSession();
        } else if (typeof top.restoreSession === 'function') {
            top.restoreSession();
        }

        const csrfInput = document.getElementById('pz-csrf');
        const formData = new FormData();
        formData.append('csrf_token_form', csrfInput ? csrfInput.value : '');

        const cleanMarks = PZ.marks.map(m => {
            const c = Object.assign({}, m);
            delete c._client_id;
            return c;
        });

        formData.append('marks_json', JSON.stringify(cleanMarks));
        formData.append('loaded_ids', JSON.stringify(PZ.loadedIds));
        formData.append('general_notes', generalNotesEl ? generalNotesEl.value : '');
        const summaryTextEl = document.getElementById('pz-summary-text');
        formData.append('summary_text', summaryTextEl ? summaryTextEl.value : '');

        fetch(PZ.saveUrl, {
            method: 'POST',
            body: formData
        })
        .then(async response => {
            const rawText = await response.text();
            let data = null;
            try {
                data = JSON.parse(rawText);
            } catch (e) {
                console.error('Server save error response:', rawText);
                let cleanErr = rawText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                if (cleanErr.length > 250) cleanErr = cleanErr.substring(0, 250) + '...';
                throw new Error(cleanErr || <?php echo xlj('Server returned an invalid response. Please try again.'); ?>);
            }

            if (!response.ok || !data.success) {
                const msg = (data && data.errors && data.errors.length)
                    ? data.errors.join("\n")
                    : <?php echo xlj('An error occurred while saving.'); ?>;
                showError(msg);
                if (saveBtn) saveBtn.disabled = false;
                return;
            }

            PZ.dirty = false;
            if (data.action === 'closeTab') {
                if (parent && typeof parent.closeTab === 'function') {
                    parent.closeTab(window.name, true);
                } else {
                    window.close();
                }
            } else if (data.redirect) {
                window.location.href = data.redirect;
            }
        })
        .catch(err => {
            showError(err.message || <?php echo xlj('A network error occurred while saving.'); ?>);
            if (saveBtn) saveBtn.disabled = false;
        });
    }

    // Setup Viewport Zoom & Pan interactions
    const viewport = document.getElementById('pz-map-viewport');
    if (viewport) {
        // 1. Mouse wheel zoom
        viewport.addEventListener('wheel', (e) => {
            e.preventDefault();
            hideZoneTooltip();
            const factor = e.deltaY < 0 ? ZOOM_STEP : (1 / ZOOM_STEP);
            zoomAtPoint(factor, e.clientX, e.clientY);
        }, { passive: false });

        // 2. Prevent right-click context menu inside the anatomical map
        viewport.addEventListener('contextmenu', (e) => {
            e.preventDefault();
        });

        // 3. Mouse down: right-button pans, left-button tracks click position
        viewport.addEventListener('mousedown', (e) => {
            if (e.button === 2) {
                e.preventDefault();
                hideZoneTooltip();
                isPanning = true;
                panStartX = e.clientX - panX;
                panStartY = e.clientY - panY;
                viewport.classList.add('is-panning');
            } else if (e.button === 0) {
                clickStartX = e.clientX;
                clickStartY = e.clientY;
            }
        });

        // 4. Mouse move: execute panning if active
        window.addEventListener('mousemove', (e) => {
            if (!isPanning) return;
            panX = e.clientX - panStartX;
            panY = e.clientY - panStartY;
            applyZoomPan(false);
        });

        // 5. Mouse up: finish panning
        window.addEventListener('mouseup', (e) => {
            if (isPanning && (e.button === 2 || e.buttons === 0)) {
                isPanning = false;
                if (viewport) viewport.classList.remove('is-panning');
            }
        });

        // 6. Touch gestures for mobile/tablet (pinch to zoom and drag to pan)
        let touchStartDist = 0;
        let touchStartScale = 1;
        let touchPanStartX = 0;
        let touchPanStartY = 0;

        viewport.addEventListener('touchstart', (e) => {
            if (e.touches.length === 2) {
                touchStartDist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                touchStartScale = zoomScale;
            } else if (e.touches.length === 1) {
                touchPanStartX = e.touches[0].clientX - panX;
                touchPanStartY = e.touches[0].clientY - panY;
                clickStartX = e.touches[0].clientX;
                clickStartY = e.touches[0].clientY;
            }
        }, { passive: true });

        viewport.addEventListener('touchmove', (e) => {
            if (e.touches.length === 2 && touchStartDist > 0) {
                e.preventDefault();
                hideZoneTooltip();
                const dist = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                const midX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                const midY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
                const ratio = dist / touchStartDist;
                zoomAtPoint(ratio / (zoomScale / touchStartScale), midX, midY);
            } else if (e.touches.length === 1 && zoomScale > 1.0) {
                e.preventDefault();
                hideZoneTooltip();
                panX = e.touches[0].clientX - touchPanStartX;
                panY = e.touches[0].clientY - touchPanStartY;
                applyZoomPan(false);
            }
        }, { passive: false });
    }

    // Zoom Buttons
    const btnZoomIn = document.getElementById('pz-zoom-in');
    if (btnZoomIn) {
        btnZoomIn.addEventListener('click', (e) => {
            e.stopPropagation();
            hideZoneTooltip();
            zoomAtPoint(ZOOM_STEP);
        });
    }

    const btnZoomOut = document.getElementById('pz-zoom-out');
    if (btnZoomOut) {
        btnZoomOut.addEventListener('click', (e) => {
            e.stopPropagation();
            hideZoneTooltip();
            zoomAtPoint(1 / ZOOM_STEP);
        });
    }

    const btnZoomReset = document.getElementById('pz-zoom-reset');
    if (btnZoomReset) {
        btnZoomReset.addEventListener('click', (e) => {
            e.stopPropagation();
            hideZoneTooltip();
            resetZoomPan(true);
        });
    }

    // Initial load
    renderSvg();
    renderSidebarMarks();
    updateRadioCounters();
})();
</script>
</body>
</html>
