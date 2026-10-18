<?php

/**
 * pain_zones.inc.php  -  Shared helpers for the Pain Zones encounter form.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/../../globals.php';

if (!function_exists('addForm')) {
    $srcdir = $GLOBALS['srcdir'] ?? '';
    if (empty($srcdir) && class_exists(\OpenEMR\Core\OEGlobalsBag::class)) {
        $srcdir = \OpenEMR\Core\OEGlobalsBag::getInstance()->getString('srcdir');
    }
    if (empty($srcdir)) {
        $srcdir = dirname(__DIR__, 2) . '/library';
    }
    if (file_exists("$srcdir/forms.inc.php")) {
        require_once "$srcdir/forms.inc.php";
    }
}

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Acl\AccessDeniedHelper;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

/** Canonical map: figure -> view -> SVG filename (in assets/) */
const PZ_FIGURE_MAP = [
    'adult_male'   => ['anterior' => 'male_anterior.svg',   'posterior' => 'male_posterior.svg'],
    'adult_female' => ['anterior' => 'female_anterior.svg', 'posterior' => 'female_posterior.svg'],
];

/** Known anatomical region prefixes for zone-id validation. */
const PZ_REGION_PREFIXES = [
    'head', 'neck', 'chest', 'abdomen', 'pelvis', 'genitals', 'perineum',
    'shoulder', 'clavicle', 'upper_arm', 'elbow', 'forearm', 'wrist', 'hand',
    'hip', 'thigh', 'knee', 'lower_leg', 'ankle', 'foot',
    'back', 'buttock', 'spine',
];

/** Exact-match exceptions: valid zone ids that do not follow prefix_subzone pattern. */
const PZ_ZONE_EXACT_EXCEPTIONS = ['genitals', 'perineum'];

/** SVG ids that are always SVG/Inkscape infrastructure (never anatomical zones). */
const PZ_ZONE_EXPLICIT_EXCLUDE = ['body-outline', 'outline', 'body_outline'];

/** Maximum marks allowed in a single POST. */
const PZ_MAX_MARKS = 500;

/** Maximum character length for free-text fields (must match table.sql). */
const PZ_TEXT_MAX = [
    'pain_quality'  => 64,
    'radiation'     => 255,
    'onset'         => 100,
    'duration'      => 100,
    'aggravating'   => 255,
    'relieving'     => 255,
    'notes'         => 5000,
    'general_notes' => 5000,
];

// ---------------------------------------------------------------------------
// Custom exception: user-input errors -> HTTP 400
// ---------------------------------------------------------------------------

class PzValidationException extends \RuntimeException {}

// ---------------------------------------------------------------------------
// SVG helpers
// ---------------------------------------------------------------------------

/**
 * Returns the absolute filesystem path to the SVG asset.
 * @throws \InvalidArgumentException on unknown figure/view combination.
 */
function pz_svgFilePath(string $figure, string $body_view): string
{
    if (!isset(PZ_FIGURE_MAP[$figure][$body_view])) {
        throw new \InvalidArgumentException(
            "Unknown figure/view combination: {$figure} / {$body_view}"
        );
    }
    return __DIR__ . '/assets/' . PZ_FIGURE_MAP[$figure][$body_view];
}

/**
 * Returns the raw SVG string stripped of its XML declaration, ready for inline HTML embedding.
 */
function pz_getSvgContent(string $figure, string $body_view): string
{
    $path    = pz_svgFilePath($figure, $body_view);
    $content = file_get_contents($path);
    if ($content === false) {
        throw new \RuntimeException("Cannot read SVG file: {$path}");
    }
    // Strip the leading <?xml ... ? > declaration (split to prevent PHP tag close).
    // Pattern split so literal '? >' never appears inside a PHP string (would close PHP block).
    $xmlDeclRe = '/\A<[?]xml[^?]*[?]' . chr(62) . '\s*/s';
    return (string) preg_replace($xmlDeclRe, '', $content);
}

/**
 * Extracts the list of valid anatomical zone ids from an SVG file using a
 * region-prefix whitelist plus exact-match exceptions. Results are cached
 * per figure+view combination for the lifetime of the request.
 *
 * @return string[]
 */
function pz_getSvgZoneIds(string $figure, string $body_view): array
{
    static $cache = [];
    $key = $figure . '_' . $body_view;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $path = pz_svgFilePath($figure, $body_view);

    // Load with LIBXML_NONET to prevent external entity/network access.
    $doc = new \DOMDocument();
    $loaded = @$doc->load($path, LIBXML_NONET);
    if (!$loaded) {
        throw new \RuntimeException("Cannot parse SVG file: {$path}");
    }
    $xpath = new \DOMXPath($doc);
    // Register the SVG namespace so xpath can reach elements inside it.
    $xpath->registerNamespace('svg', 'http://www.w3.org/2000/svg');

    // Collect every id attribute in the document regardless of namespace.
    $nodeList = $xpath->query('//@id');
    $allIds   = [];
    if ($nodeList !== false) {
        foreach ($nodeList as $attr) {
            /** @var \DOMAttr $attr */
            $id = $attr->value;
            if ($id !== '') {
                $allIds[] = $id;
            }
        }
    }

    // Build the prefix regex from the known list.
    $prefixAlt  = implode(
        '|',
        array_map(
            static fn(string $p): string => preg_quote($p, '/'),
            PZ_REGION_PREFIXES
        )
    );
    $prefixRegex = '/^(' . $prefixAlt . ')_[a-z0-9]+(_[a-z0-9]+)*$/';

    $zones = [];
    foreach (array_unique($allIds) as $id) {
        $isExact    = in_array($id, PZ_ZONE_EXACT_EXCEPTIONS, true);
        $isPrefix   = (bool) preg_match($prefixRegex, $id);
        $isExcluded = in_array($id, PZ_ZONE_EXPLICIT_EXCLUDE, true);

        if (($isExact || $isPrefix) && !$isExcluded) {
            $zones[] = $id;
        }
    }

    $cache[$key] = $zones;
    return $zones;
}

// ---------------------------------------------------------------------------
// Color palette
// ---------------------------------------------------------------------------

/**
 * Hard-coded default palette matching table.sql INSERT values.
 * Used as fallback when DB values are missing or invalid.
 *
 * @return array<string, array{stroke: string, fill: string}>
 */
function pz_defaultLevelColors(): array
{
    return [
        'level_0'  => ['stroke' => '#848484', 'fill' => '#BDBDBD'],
        'level_1'  => ['stroke' => '#1565C0', 'fill' => '#42A5F5'],
        'level_2'  => ['stroke' => '#0277BD', 'fill' => '#29B6F6'],
        'level_3'  => ['stroke' => '#00695C', 'fill' => '#26A69A'],
        'level_4'  => ['stroke' => '#2E7D32', 'fill' => '#66BB6A'],
        'level_5'  => ['stroke' => '#558B2F', 'fill' => '#D4E157'],
        'level_6'  => ['stroke' => '#F9A825', 'fill' => '#FFEE58'],
        'level_7'  => ['stroke' => '#E65100', 'fill' => '#FFA726'],
        'level_8'  => ['stroke' => '#BF360C', 'fill' => '#FF7043'],
        'level_9'  => ['stroke' => '#B71C1C', 'fill' => '#EF5350'],
        'level_10' => ['stroke' => '#4A148C', 'fill' => '#AB47BC'],
    ];
}

/**
 * Loads the pain-level color palette from list_options.
 * Validates each color against ^#[0-9A-Fa-f]{6}$.
 * Falls back to the built-in default palette for any missing or invalid entry,
 * and always guarantees level_0 through level_10 exist.
 *
 * @return array<string, array{stroke: string, fill: string}>
 *   Keyed by option_id (e.g. 'level_0' .. 'level_10').
 */
function pz_getLevelColors(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $colorRegex = '/^#[0-9A-Fa-f]{6}$/';
    $defaults   = pz_defaultLevelColors();

    $res    = sqlStatement(
        "SELECT option_id, notes
         FROM list_options
         WHERE list_id = 'pain_zones_level' AND activity = 1
         ORDER BY seq"
    );
    $colors = [];
    while ($row = sqlFetchArray($res)) {
        $optId  = (string)$row['option_id'];
        $notes  = (string)($row['notes'] ?? '');
        $stroke = '';
        $fill   = '';
        // notes format: "stroke:#RRGGBB,fill:#RRGGBB"
        foreach (explode(',', $notes) as $part) {
            [$k, $v] = array_pad(explode(':', $part, 2), 2, '');
            $k = trim($k);
            $v = '#' . ltrim(trim($v), '#');
            if ($k === 'stroke') {
                $stroke = $v;
            } elseif ($k === 'fill') {
                $fill = $v;
            }
        }
        // Validate both colors; fall back to default for this level if invalid.
        $strokeOk = (bool) preg_match($colorRegex, $stroke);
        $fillOk   = (bool) preg_match($colorRegex, $fill);
        if (!$strokeOk || !$fillOk) {
            error_log("pain_zones: invalid color for {$optId} (stroke='{$stroke}' fill='{$fill}'); using default.");
            $colors[$optId] = $defaults[$optId] ?? ['stroke' => '#848484', 'fill' => '#BDBDBD'];
        } else {
            $colors[$optId] = ['stroke' => $stroke, 'fill' => $fill];
        }
    }

    // Guarantee all 11 levels exist (fill missing ones from defaults).
    for ($i = 0; $i <= 10; $i++) {
        $lvl = 'level_' . $i;
        if (!isset($colors[$lvl])) {
            error_log("pain_zones: missing color entry for {$lvl} in list_options; using default.");
            $colors[$lvl] = $defaults[$lvl];
        }
    }

    $cache = $colors;
    return $cache;
}

// ---------------------------------------------------------------------------
// Laterality derivation (always server-side)
// ---------------------------------------------------------------------------

function pz_deriveLaterality(string $zone_id): string
{
    if (str_ends_with($zone_id, '_left'))  return 'left';
    if (str_ends_with($zone_id, '_right')) return 'right';
    return 'midline';
}

// ---------------------------------------------------------------------------
// ACL helpers
// ---------------------------------------------------------------------------

/** True if the current user has any (read) access to patients/med. */
function pz_canRead(): bool
{
    return (bool) AclMain::aclCheckCore('patients', 'med');
}

/**
 * True if the current user can write (or add) to patients/med.
 *
 * @param string $mode  'new'    -> write OR addonly accepted
 *                      'update' -> only write accepted
 */
function pz_canWrite(string $mode = 'new'): bool
{
    $required = ($mode === 'update') ? 'write' : ['write', 'addonly'];
    return (bool) AclMain::aclCheckCore('patients', 'med', '', $required);
}

/**
 * Abort with the unauthorized HTML template if the user cannot read patients/med.
 * Use only in HTML-rendering entry points (new.php, view.php).
 */
function pz_requireRead(): void
{
    if (!pz_canRead()) {
        AccessDeniedHelper::denyWithTemplate(
            'ACL check failed for pain_zones: read access required',
            xlt('Pain Zones')
        );
    }
}

/**
 * Asserts that the form belongs to the active session patient.
 * Compatible with OpenEMR 8.4+ (EncounterFormAccess) and OpenEMR 8.2 (fallback).
 */
function pz_assertFormBelongsToSessionPatient(int $formId, string $formdir = 'pain_zones'): void
{
    if (class_exists(\OpenEMR\Common\Forms\EncounterFormAccess::class)) {
        \OpenEMR\Common\Forms\EncounterFormAccess::assertFormBelongsToSessionPatient($formId, $formdir);
        return;
    }

    $sessionPid = \OpenEMR\Common\Session\PatientSessionUtil::getPid();
    if ($sessionPid <= 0) {
        AccessDeniedHelper::deny('No active patient in session');
    }

    $row = \OpenEMR\Common\Database\QueryUtils::fetchRecords(
        "SELECT pid FROM forms WHERE form_id = ? AND formdir = ? AND deleted = 0 LIMIT 1",
        [$formId, $formdir]
    );

    if (empty($row) || (int)$row[0]['pid'] !== $sessionPid) {
        AccessDeniedHelper::deny('Form does not belong to session patient');
    }
}

/**
 * Returns the web path to the OpenEMR interface directory (e.g. /interface or /openemr/interface).
 */
function pz_rootdir(): string
{
    if (isset($GLOBALS['rootdir']) && is_string($GLOBALS['rootdir']) && $GLOBALS['rootdir'] !== '') {
        return rtrim($GLOBALS['rootdir'], '/');
    }
    if (class_exists(\OpenEMR\Core\OEGlobalsBag::class)) {
        $r = (string)\OpenEMR\Core\OEGlobalsBag::getInstance()->get('rootdir');
        if ($r !== '') {
            return rtrim($r, '/');
        }
    }
    $webRoot = (string)($GLOBALS['web_root'] ?? '');
    return rtrim($webRoot . '/interface', '/');
}

// ---------------------------------------------------------------------------
// Text sanitization
// ---------------------------------------------------------------------------

/**
 * Validates a nullable text value without silent truncation.
 *
 * Allowed control characters: \t (0x09), \n (0x0A), \r (0x0D).
 * Rejected: \x00-\x08, \x0B, \x0C, \x0E-\x1F, \x7F, invalid UTF-8,
 *           and strings longer than $maxLen UTF-8 characters.
 *
 * @return array{ok: bool, error: string, value: string|null}
 */
function pz_sanitizeText(?string $val, int $maxLen, string $fieldName): array
{
    if ($val === null || $val === '') {
        return ['ok' => true, 'error' => '', 'value' => null];
    }

    if (!mb_check_encoding($val, 'UTF-8')) {
        return ['ok' => false, 'error' => "{$fieldName}: invalid UTF-8 encoding", 'value' => null];
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $val)) {
        return ['ok' => false, 'error' => "{$fieldName}: contains invalid control characters", 'value' => null];
    }

    $len = mb_strlen($val, 'UTF-8');
    if ($len > $maxLen) {
        return [
            'ok'    => false,
            'error' => "{$fieldName}: exceeds maximum length of {$maxLen} characters (got {$len})",
            'value' => null,
        ];
    }

    return ['ok' => true, 'error' => '', 'value' => $val];
}

// ---------------------------------------------------------------------------
// Float comparison with tolerance
// ---------------------------------------------------------------------------

function pz_floatEq(float $a, float $b, float $epsilon = 0.0005): bool
{
    return abs($a - $b) < $epsilon;
}

// ---------------------------------------------------------------------------
// Mark validation -> typed, sanitized mark array
// ---------------------------------------------------------------------------

/**
 * Validates a raw mark array from the client payload.
 *
 * Returns the sanitized+typed mark in the 'mark' key. All subsequent code
 * must use this version instead of the raw input. The 'pid' is NEVER taken
 * from the client; 'laterality' is always derived server-side from 'zone_id'.
 *
 * @param array<mixed> $raw          Raw associative array decoded from JSON
 * @param string[]     $validZoneIds Valid zone ids for this figure+view combination
 * @return array{ok: bool, errors: string[], mark: array<string, mixed>}
 */
function pz_validateMark(array $raw, array $validZoneIds): array
{
    $errors = [];
    $mark   = [];

    // --- db_id: null (new mark) or positive int (existing mark) --------------
    // Reject bool/float/non-scalar before filter_var (true casts to 1 and would pass).
    $rawDbId = $raw['db_id'] ?? null;
    if ($rawDbId !== null && $rawDbId !== '') {
        if (!is_int($rawDbId) && !(is_string($rawDbId) && ctype_digit($rawDbId))) {
            $errors[] = 'db_id: must be null or a positive integer';
            $mark['db_id'] = null;
        } else {
            $dbId = filter_var($rawDbId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($dbId === false) {
                $errors[] = 'db_id: must be null or a positive integer';
                $mark['db_id'] = null;
            } else {
                $mark['db_id'] = (int)$dbId;
            }
        }
    } else {
        $mark['db_id'] = null;
    }

    // --- figure ---------------------------------------------------------------
    if (!isset($raw['figure']) || !is_string($raw['figure'])) {
        $errors[] = 'figure: missing or not a string';
        $mark['figure'] = '';
    } elseif (!array_key_exists($raw['figure'], PZ_FIGURE_MAP)) {
        $safe = mb_substr($raw['figure'], 0, 50, 'UTF-8');
        $errors[] = "figure: invalid value \"{$safe}\"";
        $mark['figure'] = '';
    } else {
        $mark['figure'] = $raw['figure'];
    }

    // --- body_view ------------------------------------------------------------
    if (!isset($raw['body_view']) || !is_string($raw['body_view'])) {
        $errors[] = 'body_view: missing or not a string';
        $mark['body_view'] = '';
    } elseif (!in_array($raw['body_view'], ['anterior', 'posterior'], true)) {
        $safe = mb_substr($raw['body_view'], 0, 50, 'UTF-8');
        $errors[] = "body_view: invalid value \"{$safe}\"";
        $mark['body_view'] = '';
    } else {
        $mark['body_view'] = $raw['body_view'];
    }

    // --- zone_id: format + whitelist (only when figure/view are valid) --------
    if (!isset($raw['zone_id']) || !is_string($raw['zone_id'])) {
        $errors[] = 'zone_id: missing or not a string';
    } elseif (mb_strlen($raw['zone_id'], 'UTF-8') > 64) {
        $errors[] = 'zone_id: exceeds 64 characters';
    } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $raw['zone_id'])) {
        $errors[] = 'zone_id: contains invalid characters';
    } elseif (!in_array($raw['zone_id'], $validZoneIds, true)) {
        $safe = mb_substr($raw['zone_id'], 0, 50, 'UTF-8');
        $errors[] = "zone_id: \"{$safe}\" is not valid for this figure/view";
    } else {
        $mark['zone_id']    = $raw['zone_id'];
        $mark['laterality'] = pz_deriveLaterality($raw['zone_id']); // always server-derived
    }

    // --- x_pct ---------------------------------------------------------------
    // Reject booleans and non-numeric types before filter_var.
    $rawXPct = $raw['x_pct'] ?? null;
    if (!is_int($rawXPct) && !is_float($rawXPct) && !(is_string($rawXPct) && is_numeric($rawXPct))) {
        $errors[] = 'x_pct: must be a number between 0 and 100';
    } else {
        $xPct = filter_var($rawXPct, FILTER_VALIDATE_FLOAT);
        if ($xPct === false || $xPct < 0.0 || $xPct > 100.0) {
            $errors[] = 'x_pct: must be a float between 0 and 100';
        } else {
            $mark['x_pct'] = round($xPct, 3);
        }
    }

    // --- y_pct ---------------------------------------------------------------
    $rawYPct = $raw['y_pct'] ?? null;
    if (!is_int($rawYPct) && !is_float($rawYPct) && !(is_string($rawYPct) && is_numeric($rawYPct))) {
        $errors[] = 'y_pct: must be a number between 0 and 100';
    } else {
        $yPct = filter_var($rawYPct, FILTER_VALIDATE_FLOAT);
        if ($yPct === false || $yPct < 0.0 || $yPct > 100.0) {
            $errors[] = 'y_pct: must be a float between 0 and 100';
        } else {
            $mark['y_pct'] = round($yPct, 3);
        }
    }

    // --- intensity -----------------------------------------------------------
    // Reject booleans before filter_var (true -> 1 would pass min/max check).
    $rawIntensity = $raw['intensity'] ?? null;
    if (!is_int($rawIntensity) && !(is_string($rawIntensity) && ctype_digit($rawIntensity))) {
        $errors[] = 'intensity: must be an integer between 0 and 10';
    } else {
        $intensity = filter_var(
            $rawIntensity,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 10]]
        );
        if ($intensity === false) {
            $errors[] = 'intensity: must be an integer between 0 and 10';
        } else {
            $mark['intensity'] = (int)$intensity;
        }
    }

    // --- optional text fields ------------------------------------------------
    foreach (['pain_quality', 'radiation', 'onset', 'duration', 'aggravating', 'relieving', 'notes'] as $field) {
        $rawVal = $raw[$field] ?? null;
        if ($rawVal !== null && !is_string($rawVal)) {
            $errors[] = "{$field}: must be a string or null";
            continue;
        }
        $result = pz_sanitizeText(($rawVal === '') ? null : $rawVal, PZ_TEXT_MAX[$field], $field);
        if (!$result['ok']) {
            $errors[] = $result['error'];
        } else {
            $mark[$field] = $result['value'];
        }
    }

    return [
        'ok'     => empty($errors),
        'errors' => $errors,
        'mark'   => $mark,
    ];
}
