<?php

/**
 * save.php  -  Pain Zones form: receive POST, validate, persist.
 *
 * Accepts a single POST with:
 *   csrf_token_form  - CSRF token
 *   marks_json       - JSON array of mark objects
 *   loaded_ids       - JSON array of db_id integers that were loaded at open time
 *   general_notes    - free-text field
 *
 * Always responds JSON:
 *   {success: true,  action: "closeTab"}
 *   {success: false, errors: ["..."]}
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/pain_zones.inc.php';

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Csrf\CsrfInvalidException;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;

// ---------------------------------------------------------------------------
// Ownership check MUST run before the JSON header
// because pz_assertFormBelongsToSessionPatient() calls exit() on mismatch.
// We parse mode/id here so the check can run early.
// ---------------------------------------------------------------------------

$mode = filter_input(INPUT_GET, 'mode');
if (!in_array($mode, ['new', 'update'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => [xl('Invalid request mode.')]]);
    exit;
}

$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($mode === 'update' && $formId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => [xl('A valid form id is required for update.')]]);
    exit;
}

// Ownership check: calls AccessDeniedHelper::deny() -> exit() on mismatch.
// Must run before the JSON Content-Type header to allow the HTML 404 response.
if ($mode === 'update') {
    pz_assertFormBelongsToSessionPatient($formId, 'pain_zones');
}

// From here all responses are JSON.
header('Content-Type: application/json; charset=utf-8');

/**
 * Send a JSON error response and terminate.
 *
 * @param string[]|string $errors
 */
function pz_jsonError(array|string $errors, int $httpCode = 400): never
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'errors'  => is_array($errors) ? $errors : [$errors],
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 1. CSRF check (before anything else, including ACL)
// ---------------------------------------------------------------------------

try {
    CsrfUtils::checkCsrfInput(INPUT_POST); // throws CsrfInvalidException on failure
} catch (CsrfInvalidException $e) {
    pz_jsonError(xl('Authentication error: CSRF token invalid or missing.'), 403);
}

// ---------------------------------------------------------------------------
// 2. ACL check ($mode and $formId already set before the JSON header above)
// ---------------------------------------------------------------------------

if (!pz_canWrite($mode)) {
    pz_jsonError(xl('Access denied: insufficient permissions.'), 403);
}

// ---------------------------------------------------------------------------
// 3. Session values - pid and encounter (never from client)
// ---------------------------------------------------------------------------

$pid = PatientSessionUtil::getPid();
if ($pid <= 0) {
    pz_jsonError(xl('No patient selected in session.'));
}

$encounter = EncounterSessionUtil::getEncounter();
if (empty($encounter)) {
    pz_jsonError(xl('No active encounter in session. Please open an encounter before saving.'));
}

$session      = SessionWrapperFactory::getInstance()->getActiveSession();
$authUser     = (string)($session->get('authUser')     ?? '');
$authProvider = (string)($session->get('authProvider') ?? '');
// getUserAuthorized() already returns int; no additional cast needed.
$userAuth     = PatientSessionUtil::getUserAuthorized();

// ---------------------------------------------------------------------------
// 4. (Ownership check already ran before the JSON header above)
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// 5. Decode and pre-validate the marks JSON
// ---------------------------------------------------------------------------

$rawMarksJson = filter_input(INPUT_POST, 'marks_json', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? '';
$marksRaw     = json_decode((string)$rawMarksJson, true);

if (!is_array($marksRaw) || !array_is_list($marksRaw)) {
    pz_jsonError(xl('Invalid marks payload: must be a JSON array (list).'));
}

if (count($marksRaw) > PZ_MAX_MARKS) {
    pz_jsonError(sprintf(xl('Too many marks in a single submission (maximum %d).'), PZ_MAX_MARKS));
}

// loaded_ids: the db_ids that were loaded when the form was opened
$rawLoadedJson = filter_input(INPUT_POST, 'loaded_ids', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? '[]';
$loadedIds     = json_decode((string)$rawLoadedJson, true);
if (!is_array($loadedIds)) {
    $loadedIds = [];
}
// Sanitize to positive ints
$loadedIds = array_values(array_filter(
    array_map(static fn($v) => (filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])), $loadedIds),
    static fn($v) => $v !== false
));

// ---------------------------------------------------------------------------
// 6. Check for duplicate db_ids in payload
// ---------------------------------------------------------------------------

$seenPayloadDbIds = [];
foreach ($marksRaw as $i => $m) {
    if (!is_array($m)) {
        pz_jsonError(xl('Mark') . " #{$i}: " . xl('each mark must be a JSON object.'));
    }
    $rawDbId = $m['db_id'] ?? null;
    if ($rawDbId !== null && $rawDbId !== '') {
        $dbIdInt = filter_var($rawDbId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($dbIdInt !== false) {
            if (in_array((int)$dbIdInt, $seenPayloadDbIds, true)) {
                pz_jsonError(xl('Duplicate db_id in payload') . ": {$dbIdInt}");
            }
            $seenPayloadDbIds[] = (int)$dbIdInt;
        }
    }
}

// ---------------------------------------------------------------------------
// 7. Build server-side zone whitelist for all figure/view combinations
// ---------------------------------------------------------------------------

$validZonesMap = [];
foreach (PZ_FIGURE_MAP as $fig => $views) {
    foreach ($views as $view => $_) {
        $validZonesMap[$fig][$view] = pz_getSvgZoneIds($fig, $view);
    }
}

// ---------------------------------------------------------------------------
// 8. Validate and sanitize general_notes
// ---------------------------------------------------------------------------

$rawNotes    = filter_input(INPUT_POST, 'general_notes', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR) ?? null;
$notesResult = pz_sanitizeText(($rawNotes === '' ? null : (string)$rawNotes), PZ_TEXT_MAX['general_notes'], 'general_notes');
if (!$notesResult['ok']) {
    pz_jsonError($notesResult['error']);
}
$generalNotes = $notesResult['value'];

// ---------------------------------------------------------------------------
// 9. Validate all marks (must all pass before touching the DB)
// ---------------------------------------------------------------------------

/** @var array<int, array<string, mixed>> $validatedMarks */
$validatedMarks = [];

foreach ($marksRaw as $i => $m) {
    // Already confirmed each element is an array above.
    /** @var array<mixed> $m */
    $fig  = is_string($m['figure']    ?? null) ? $m['figure']    : '';
    $view = is_string($m['body_view'] ?? null) ? $m['body_view'] : '';

    $allowedZones = $validZonesMap[$fig][$view] ?? [];
    $result       = pz_validateMark($m, $allowedZones);

    if (!$result['ok']) {
        pz_jsonError(
            array_map(static fn(string $e): string => xl('Mark') . " #{$i}: {$e}", $result['errors'])
        );
    }

    $validatedMarks[] = $result['mark'];
}

// ---------------------------------------------------------------------------
// 10. Persist inside a single transaction
// ---------------------------------------------------------------------------

try {
    QueryUtils::inTransaction(function () use (
        $mode, $formId, $pid, $encounter,
        $authUser, $authProvider, $userAuth,
        $validatedMarks, $generalNotes, $loadedIds
    ): void {

        // ----------------------------------------------------------------
        // A. Header: insert (new) or update (existing, date not modified)
        // ----------------------------------------------------------------
        if ($mode === 'new') {
            $formId = (int) sqlInsert(
                "INSERT INTO form_pain_zones
                 (date, pid, user, groupname, authorized, activity, general_notes)
                 VALUES (NOW(), ?, ?, ?, ?, 1, ?)",
                [$pid, $authUser, $authProvider, $userAuth, $generalNotes]
            );
            if ($formId <= 0) {
                throw new \RuntimeException('Failed to insert form_pain_zones header.');
            }
            // addForm() inside the transaction, only on insert
            addForm(
                $encounter,
                'Pain Zones',
                $formId,
                'pain_zones',
                $pid,
                $userAuth
            );
        } else {
            // UPDATE: do NOT touch the 'date' column (creation date)
            sqlStatement(
                "UPDATE form_pain_zones
                 SET general_notes = ?, user = ?, groupname = ?, authorized = ?
                 WHERE id = ? AND pid = ?",
                [$generalNotes, $authUser, $authProvider, $userAuth, $formId, $pid]
            );
        }

        // ----------------------------------------------------------------
        // B. Load existing active marks from DB (keyed by id)
        // ----------------------------------------------------------------
        /** @var array<int, array<string, mixed>> $dbById */
        $dbById = [];
        $dbRes  = sqlStatement(
            "SELECT * FROM form_pain_zones_marks
             WHERE form_id = ? AND pid = ? AND activity = 1
             FOR UPDATE",
            [$formId, $pid]
        );
        while ($row = sqlFetchArray($dbRes)) {
            $dbById[(int)$row['id']] = $row;
        }

        // ----------------------------------------------------------------
        // C. Process each validated mark: INSERT or conditional UPDATE
        // ----------------------------------------------------------------
        $seenDbIds = []; // db_ids seen in this payload (for soft-delete diff)

        foreach ($validatedMarks as $sanitized) {
            $dbId = $sanitized['db_id'];

            if ($dbId === null) {
                // -- INSERT new mark ----------------------------------------
                $newId = (int) sqlInsert(
                    "INSERT INTO form_pain_zones_marks
                     (form_id, pid, figure, body_view, zone_id, laterality,
                      x_pct, y_pct, intensity,
                      pain_quality, radiation, onset, duration,
                      aggravating, relieving, notes,
                      activity, date_created)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())",
                    [
                        $formId, $pid,
                        $sanitized['figure'],    $sanitized['body_view'],
                        $sanitized['zone_id'],   $sanitized['laterality'],
                        $sanitized['x_pct'],     $sanitized['y_pct'],
                        $sanitized['intensity'],
                        $sanitized['pain_quality'] ?? null,
                        $sanitized['radiation']    ?? null,
                        $sanitized['onset']        ?? null,
                        $sanitized['duration']     ?? null,
                        $sanitized['aggravating']  ?? null,
                        $sanitized['relieving']    ?? null,
                        $sanitized['notes']        ?? null,
                    ]
                );
                if ($newId <= 0) {
                    throw new \RuntimeException("Failed to insert mark for form {$formId}.");
                }
                sqlInsert(
                    "INSERT INTO form_pain_zones_history
                     (mark_id, form_id, pid, action, changed_by, changed_at, snapshot)
                     VALUES (?,?,?,'insert',?,NOW(),?)",
                    [
                        $newId, $formId, $pid, $authUser,
                        json_encode(
                            array_merge($sanitized, ['db_id' => $newId]),
                            JSON_THROW_ON_ERROR
                        ),
                    ]
                );

            } else {
                // -- UPDATE existing mark (if still active + belongs to form) -
                if (!isset($dbById[$dbId])) {
                    throw new PzValidationException(
                        xl('Mark') . " #{$dbId}: " . xl('not found or already deleted.')
                    );
                }
                $db = $dbById[$dbId];

                // Verify figure/body_view have not changed
                if ($sanitized['figure'] !== $db['figure'] ||
                    $sanitized['body_view'] !== $db['body_view']) {
                    throw new PzValidationException(
                        xl('Mark') . " #{$dbId}: figure/body_view mismatch with stored record."
                    );
                }

                $seenDbIds[] = $dbId;

                // Normalize DB null-ish strings to null for comparison
                $dbNorm = static fn(?string $v): ?string => ($v === '' || $v === null) ? null : $v;

                $changed =
                    $sanitized['intensity']    !== (int)$db['intensity']
                    || $sanitized['zone_id']   !== (string)$db['zone_id']
                    || !pz_floatEq($sanitized['x_pct'], round((float)$db['x_pct'], 3))
                    || !pz_floatEq($sanitized['y_pct'], round((float)$db['y_pct'], 3))
                    || ($sanitized['pain_quality'] ?? null) !== $dbNorm($db['pain_quality'] ?? null)
                    || ($sanitized['radiation']    ?? null) !== $dbNorm($db['radiation']    ?? null)
                    || ($sanitized['onset']        ?? null) !== $dbNorm($db['onset']        ?? null)
                    || ($sanitized['duration']     ?? null) !== $dbNorm($db['duration']     ?? null)
                    || ($sanitized['aggravating']  ?? null) !== $dbNorm($db['aggravating']  ?? null)
                    || ($sanitized['relieving']    ?? null) !== $dbNorm($db['relieving']    ?? null)
                    || ($sanitized['notes']        ?? null) !== $dbNorm($db['notes']        ?? null);

                if ($changed) {
                    sqlStatement(
                        "UPDATE form_pain_zones_marks
                         SET intensity    = ?, zone_id = ?, laterality = ?,
                             x_pct       = ?, y_pct   = ?,
                             pain_quality = ?, radiation   = ?, onset    = ?,
                             duration     = ?, aggravating = ?, relieving = ?,
                             notes        = ?, date_modified = NOW()
                         WHERE id = ? AND form_id = ? AND pid = ?",
                        [
                            $sanitized['intensity'],
                            $sanitized['zone_id'],
                            $sanitized['laterality'],
                            $sanitized['x_pct'],
                            $sanitized['y_pct'],
                            $sanitized['pain_quality'] ?? null,
                            $sanitized['radiation']    ?? null,
                            $sanitized['onset']        ?? null,
                            $sanitized['duration']     ?? null,
                            $sanitized['aggravating']  ?? null,
                            $sanitized['relieving']    ?? null,
                            $sanitized['notes']        ?? null,
                            $dbId, $formId, $pid,
                        ]
                    );
                    sqlInsert(
                        "INSERT INTO form_pain_zones_history
                         (mark_id, form_id, pid, action, changed_by, changed_at, snapshot)
                         VALUES (?,?,?,'update',?,NOW(),?)",
                        [
                            $dbId, $formId, $pid, $authUser,
                            json_encode($sanitized, JSON_THROW_ON_ERROR),
                        ]
                    );
                }
            }
        }

        // ----------------------------------------------------------------
        // D. Soft-delete marks that were loaded but are absent from payload
        //    Only marks in loaded_ids and missing from payload are touched;
        //    marks not in loaded_ids (e.g. added by another session) are left alone.
        // ----------------------------------------------------------------
        foreach ($loadedIds as $loadedId) {
            if (!in_array($loadedId, $seenDbIds, true) && isset($dbById[$loadedId])) {
                sqlStatement(
                    "UPDATE form_pain_zones_marks
                     SET activity = 0, date_modified = NOW()
                     WHERE id = ? AND form_id = ? AND pid = ?",
                    [$loadedId, $formId, $pid]
                );
                sqlInsert(
                    "INSERT INTO form_pain_zones_history
                     (mark_id, form_id, pid, action, changed_by, changed_at, snapshot)
                     VALUES (?,?,?,'delete',?,NOW(),?)",
                    [
                        $loadedId, $formId, $pid, $authUser,
                        json_encode($dbById[$loadedId], JSON_THROW_ON_ERROR),
                    ]
                );
            }
        }

        // ----------------------------------------------------------------
        // E. Regenerate the plain-text summary from the CURRENT active marks
        //    (re-read after the insert/update/soft-delete so it reflects the
        //    saved state exactly). Pure text, stored on the parent form row.
        // ----------------------------------------------------------------
        $activeRes = sqlStatement(
            "SELECT zone_id, intensity, pain_quality, radiation, onset,
                    duration, aggravating, relieving, notes
             FROM form_pain_zones_marks
             WHERE form_id = ? AND pid = ? AND activity = 1",
            [$formId, $pid]
        );
        $activeMarks = [];
        while ($row = sqlFetchArray($activeRes)) {
            $activeMarks[] = $row;
        }
        sqlStatement(
            "UPDATE form_pain_zones SET summary_text = ? WHERE id = ? AND pid = ?",
            [pz_buildSummary($activeMarks, $generalNotes), $formId, $pid]
        );
    });

    // Success: instruct client to close the encounter tab (same as formJump())
    echo json_encode(['success' => true, 'action' => 'closeTab']);

} catch (PzValidationException $e) {
    // User-input error - specific message is safe to send to client
    pz_jsonError($e->getMessage(), 400);

} catch (\Throwable $e) {
    // Unexpected server error - log detail, return generic message
    // inTransaction() already rolled back the transaction before rethrowing.
    error_log(
        'pain_zones save.php error [pid=' . $pid . ' encounter=' . $encounter . ']: '
        . $e->getMessage() . "\n" . $e->getTraceAsString()
    );
    pz_jsonError(
        xl('An error occurred while saving. Please try again or contact your administrator.'),
        500
    );
}
