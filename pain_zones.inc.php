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
    'summary_text'  => 1000000,
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
// SVG rendering helpers (shared by compare.php and the encounter report)
//
// These operate on the raw SVG markup: they tint anatomical zones and add a
// pins layer. They are used both for the on-screen comparison page and for the
// figure embedded in the encounter report / PDF.
// ---------------------------------------------------------------------------

/**
 * Parses the SVG returned by pz_getSvgContent() and removes every base <title>
 * element (Inkscape figure title and RDF metadata copies) so they cannot mask
 * the tooltips we add.
 */
function pz_svgLoad(string $svg): \DOMDocument
{
    $doc = new \DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadXML($svg, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || $doc->documentElement === null) {
        throw new \RuntimeException('Unable to parse the Pain Zones SVG.');
    }

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
function pz_svgIdMap(\DOMDocument $doc): array
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
function pz_svgCollectShapes(\DOMElement $node, bool $isRoot, array $validZoneIds): array
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
            $childShapes = pz_svgCollectShapes($child, false, $validZoneIds);
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
function pz_svgApplyHeat(string $svg, array $zoneHeat, array $validZoneIds): string
{
    $doc   = pz_svgLoad($svg);
    $idMap = pz_svgIdMap($doc);

    foreach ($zoneHeat as $zoneId => $heat) {
        if (!isset($idMap[$zoneId])) {
            continue;
        }

        $zoneEl = $idMap[$zoneId];
        $shapes = pz_svgCollectShapes($zoneEl, true, $validZoneIds);

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
function pz_svgAddPins(string $svg, array $pins): string
{
    if ($pins === []) {
        return $svg;
    }

    $doc  = pz_svgLoad($svg);
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

/**
 * Strips everything from an SVG that mPDF's SVG parser cannot draw, so the
 * figure renders reliably in the PDF export.
 *
 * Specifically removed: the <metadata> block, the Inkscape <sodipodi:namedview>
 * element, non-drawing <defs> members (Inkscape path-effects that have no
 * <linearGradient>/<radialGradient> counterpart), every element attribute and
 * namespace that is not part of plain SVG, and the root <title>.
 *
 * Kept: geometry (paths, gradients) and inline presentation styles, so the
 * vector figure stays sharp at any zoom.
 */
function pz_sanitizeSvgForPdf(string $svg): string
{
    try {
        $doc = pz_svgLoad($svg);
    } catch (\RuntimeException) {
        return $svg;
    }

    $root = $doc->documentElement;
    if ($root === null) {
        return $svg;
    }

    $svgNs = 'http://www.w3.org/2000/svg';
    $xlinkNs = 'http://www.w3.org/1999/xlink';

    // Drop non-drawing top-level blocks outright.
    foreach (['metadata', 'namedview'] as $localName) {
        $nodes = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if ($el instanceof \DOMElement && strtolower((string)$el->localName) === $localName) {
                $nodes[] = $el;
            }
        }
        foreach ($nodes as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    // Remove non-drawing members from <defs> (keep gradients and their stops).
    $defsNodes = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if ($el instanceof \DOMElement && strtolower((string)$el->localName) === 'defs') {
            $defsNodes[] = $el;
        }
    }
    foreach ($defsNodes as $defs) {
        $remove = [];
        foreach ($defs->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower((string)$child->localName);
            if (!in_array($tag, ['lineargradient', 'radialgradient', 'clipPath', 'clippath', 'pattern'], true)) {
                $remove[] = $child;
            }
        }
        foreach ($remove as $child) {
            $defs->removeChild($child);
        }
        if (!$defs->hasChildNodes() || trim($defs->textContent) === '' && $defs->childNodes->length === 0) {
            $defs->parentNode?->removeChild($defs);
        }
    }

    // Rebuild the root element with a minimal, namespace-clean attribute set.
    // mPDF only needs: version, viewBox, width/height (optional), and the two
    // namespaces it resolves (svg and xlink).
    $keepRootAttrs = ['viewbox', 'width', 'height', 'version', 'preserveaspectratio'];
    $newRootAttrs  = [];
    foreach ($root->attributes as $attr) {
        if (!$attr instanceof \DOMAttr) {
            continue;
        }
        $name = strtolower($attr->nodeName);
        if (in_array($name, $keepRootAttrs, true)) {
            $newRootAttrs[$attr->nodeName] = $attr->value;
        }
    }

    // Also strip all custom/unknown attributes from every descendant, keeping
    // only presentation and geometry attributes mPDF understands.
    $allowedAttrs = [
        'id', 'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'width', 'height', 'points', 'transform', 'gradienttransform', 'gradientunits',
        'offset', 'stop-color', 'stop-opacity', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap', 'stroke-linejoin',
        'stroke-dasharray', 'stroke-dashoffset', 'style', 'opacity', 'class',
        'font-size', 'font-family', 'font-weight', 'font-style', 'text-anchor',
        'dominant-baseline', 'paint-order', 'xlink:href', 'href', 'clip-path',
    ];
    foreach ($doc->getElementsByTagName('*') as $el) {
        if (!$el instanceof \DOMElement || $el === $root) {
            continue;
        }
        $removeAttrs = [];
        foreach ($el->attributes as $attr) {
            if (!$attr instanceof \DOMAttr) {
                continue;
            }
            if (!in_array(strtolower($attr->nodeName), $allowedAttrs, true)) {
                $removeAttrs[] = $attr->nodeName;
            }
        }
        foreach ($removeAttrs as $name) {
            $el->removeAttribute($name);
        }
    }

    $out = '';
    // Serialise manually so we fully control the root tag and namespaces.
    $body = '';
    foreach ($root->childNodes as $child) {
        $body .= $doc->saveXML($child);
    }

    $attrs = ' xmlns="' . $svgNs . '" xmlns:xlink="' . $xlinkNs . '"';
    foreach ($newRootAttrs as $name => $value) {
        $attrs .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '"';
    }

    $out = '<svg' . $attrs . '>' . $body . '</svg>';

    return $out;
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

/**
 * Loads pain quality options from list_options (list_id = 'pain_zones_quality').
 * Falls back to built-in clinical defaults if the list is missing or empty in the database.
 *
 * @return array<string, string> Keyed by option_id => title.
 */
function pz_getPainQualityOptions(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $res = sqlStatement(
        "SELECT option_id, title
         FROM list_options
         WHERE list_id = 'pain_zones_quality' AND activity = 1
         ORDER BY seq, title"
    );
    $options = [];
    while ($row = sqlFetchArray($res)) {
        $optId = trim((string)($row['option_id'] ?? ''));
        $title = trim((string)($row['title'] ?? ''));
        if ($optId !== '') {
            $options[$optId] = ($title !== '') ? $title : $optId;
        }
    }

    if (empty($options)) {
        $options = [
            'sharp'     => 'Sharp',
            'dull'      => 'Dull',
            'burning'   => 'Burning',
            'throbbing' => 'Throbbing',
            'stabbing'  => 'Stabbing',
            'cramping'  => 'Cramping',
            'pressing'  => 'Pressing',
            'shooting'  => 'Shooting',
            'aching'    => 'Aching',
            'electric'  => 'Electric',
            'tingling'  => 'Tingling',
            'heavy'     => 'Heavy',
            'other'     => 'Other',
        ];
    }

    $cache = $options;
    return $cache;
}

/**
 * Resolve a stored pain-quality value to its translated display label.
 *
 * Marks store either an option_id from `pain_zones_quality` (e.g. 'throbbing')
 * or free text typed for "Other". Option ids are mapped to their translated
 * title; free text is returned unchanged.
 *
 * NOTE: requires DB access (list_options, cached). Do not call from the pure
 * summary builder; resolve the values before calling pz_buildSummary().
 *
 * @param string $quality Stored option_id or custom text.
 * @return string Translated, human-readable quality label.
 */
function pz_qualityLabel(string $quality): string
{
    $quality = trim($quality);
    if ($quality === '') {
        return '';
    }

    $options = pz_getPainQualityOptions();
    if (isset($options[$quality])) {
        return xl($options[$quality]);
    }

    return $quality;
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
// Anatomical Zone Labels & Clinical Descriptions
// ---------------------------------------------------------------------------

/**
 * Base anatomical zone labels (English).
 * Stripped of lateral suffixes (_left, _right).
 * Translated via xl() in pz_zoneLabel().
 */
const PZ_ZONE_LABELS = [
    'abdomen_epigastric'    => 'Epigastrium',
    'abdomen_flank'         => 'Flank',
    'abdomen_hypochondriac' => 'Hypochondrium',
    'abdomen_iliac'         => 'Iliac Fossa',
    'abdomen_suprapubic'    => 'Suprapubic Region',
    'abdomen_umbilical'     => 'Umbilical Region',
    'ankle_achilles'        => 'Achilles Tendon',
    'ankle_anterior'        => 'Anterior Ankle',
    'back_iliac_crest'      => 'Iliac Crest',
    'back_interscapular'    => 'Interscapular Region',
    'back_lumbar'           => 'Lumbar Region',
    'back_sacrum'           => 'Sacrum',
    'back_scapula'          => 'Scapula',
    'back_thoracic'         => 'Thoracic Back',
    'back_trapezius'        => 'Trapezius',
    'buttock'               => 'Buttock',
    'chest_axilla'          => 'Axilla',
    'chest_breast'          => 'Breast',
    'chest_pectoral'        => 'Pectoral Region',
    'chest_rib'             => 'Rib Cage',
    'chest_sternum'         => 'Sternum',
    'clavicle'              => 'Clavicle',
    'elbow_antecubital'     => 'Antecubital Fossa',
    'elbow_olecranon'       => 'Olecranon',
    'foot_dorsum'           => 'Foot Dorsum',
    'foot_heel'             => 'Heel',
    'foot_toes'             => 'Toes',
    'forearm_anterior'      => 'Anterior Forearm',
    'forearm_posterior'     => 'Posterior Forearm',
    'genitals'              => 'Genitals',
    'genitals_penis'        => 'Penis',
    'genitals_testicle'     => 'Testicle',
    'hand_dorsum'           => 'Hand Dorsum',
    'hand_fingers'          => 'Fingers',
    'hand_palm'             => 'Palm',
    'head_ear'              => 'Ear',
    'head_eye'              => 'Eye',
    'head_eyebrow'          => 'Eyebrow',
    'head_face'             => 'Cheek',
    'head_forehead'         => 'Forehead',
    'head_jaw'              => 'Jaw',
    'head_mouth'            => 'Mouth',
    'head_nose'             => 'Nose',
    'head_occipital'        => 'Occipital Region',
    'head_parietal'         => 'Parietal Region',
    'head_temple'           => 'Temple',
    'hip_lateral'           => 'Lateral Hip',
    'knee_patella'          => 'Patella',
    'knee_popliteal'        => 'Popliteal Fossa',
    'lower_leg_anterior'    => 'Anterior Leg',
    'lower_leg_posterior'   => 'Calf',
    'neck_anterior'         => 'Anterior Neck',
    'neck_lateral'          => 'Lateral Neck',
    'neck_posterior'        => 'Posterior Neck',
    'pelvis_groin'          => 'Groin',
    'pelvis_pubic'          => 'Pubis',
    'perineum'              => 'Perineum',
    'shoulder_anterior'     => 'Anterior Shoulder',
    'shoulder_posterior'    => 'Posterior Shoulder',
    'spine_cervical'        => 'Cervical Spine',
    'spine_coccyx'          => 'Coccyx',
    'spine_lumbar'          => 'Lumbar Spine',
    'spine_sacral'          => 'Sacral Spine',
    'spine_thoracic'        => 'Thoracic Spine',
    'thigh_anterior'        => 'Anterior Thigh',
    'thigh_posterior'       => 'Posterior Thigh',
    'upper_arm_anterior'    => 'Anterior Upper Arm',
    'upper_arm_posterior'   => 'Posterior Upper Arm',
    'wrist_anterior'        => 'Anterior Wrist',
    'wrist_posterior'       => 'Posterior Wrist',
];

/**
 * Returns the translated clinical label for a zone ID, appending
 * translated laterality in parentheses when applicable (e.g. 'Patella (Left)').
 * If the zone ID is not in the dictionary, returns it formatted with spaces.
 */
function pz_zoneLabel(string $zone_id): string
{
    $zone_id = trim($zone_id);
    if ($zone_id === '') {
        return '';
    }

    $laterality = '';
    if (str_ends_with($zone_id, '_left')) {
        $laterality = xl('Left');
        $baseId = substr($zone_id, 0, -5);
    } elseif (str_ends_with($zone_id, '_right')) {
        $laterality = xl('Right');
        $baseId = substr($zone_id, 0, -6);
    } else {
        $baseId = $zone_id;
    }

    if (isset(PZ_ZONE_LABELS[$baseId])) {
        $label = xl(PZ_ZONE_LABELS[$baseId]);
    } else {
        $label = ucwords(str_replace('_', ' ', $baseId));
    }

    if ($laterality !== '') {
        $label .= ' (' . $laterality . ')';
    }

    return $label;
}

// ---------------------------------------------------------------------------
// Clinical summary (plain text, one line per mark)
// ---------------------------------------------------------------------------

/**
 * Translate a pain-level number (0..10) to its severity band label.
 *
 * Pure function (no database access); the label is fully translatable via xl().
 * Matches the mapping used in the marks table (`form_pain_zones_marks.intensity`):
 * 0 none / 1-3 mild / 4-6 moderate / 7-10 severe.
 *
 * @param int $intensity
 * @return string Translated severity band (e.g. "Intense", "Moderate", "Mild", "None").
 */
function pz_intensityBandLabel(int $intensity): string
{
    if ($intensity <= 0) {
        return xl('None');
    }
    if ($intensity <= 3) {
        return xl('Mild');
    }
    if ($intensity <= 6) {
        return xl('Moderate');
    }
    return xl('Severe');
}

/**
 * Build the plain-text clinical summary for a set of marks plus optional general notes.
 *
 * Pure function (no database access, no side effects). Output is a fixed,
 * deterministic template — no AI, no free-form generation. It is meant to be
 * stored in `form_pain_zones.summary_text` and consumed by the SOAP note.
 *
 * Format (one line per mark, ordered by intensity DESC):
 *
 *     Rótula (Izquierda): intenso 8/10; cualidad: punzante; irradia a: pierna;
 *     inicio: hace 3 días; duración: ...; agrava: ...; alivia: ...; notas: ...
 *
 * Only fields with a non-empty value are included. The general notes are
 * appended as a final line when present. With no marks, a single fixed
 * translatable phrase is returned (plus the general notes when present).
 *
 * Never include patient-identifying data here (no name, no DOB, no MRN).
 *
 * @param array<int, array<string, mixed>> $marks Rows from form_pain_zones_marks
 *                                                 (zone_id, intensity, pain_quality,
 *                                                 radiation, onset, duration,
 *                                                 aggravating, relieving, notes).
 * @param string|null                      $generalNotes Optional free-text notes for the form.
 * @return string Plain-text summary (caller is responsible for text() on HTML output).
 */
function pz_buildSummary(array $marks, ?string $generalNotes): string
{
    $lines = [];

    if (empty($marks)) {
        $lines[] = xl('No pain marks recorded.');
    } else {
        // Copy so we do not mutate the caller's array; sort by intensity DESC.
        $sorted = $marks;
        usort($sorted, function ($a, $b): int {
            return ((int)($b['intensity'] ?? 0)) <=> ((int)($a['intensity'] ?? 0));
        });

        foreach ($sorted as $mark) {
            $intensity = (int)($mark['intensity'] ?? 0);
            $zoneLabel = pz_zoneLabel((string)($mark['zone_id'] ?? ''));
            $band      = pz_intensityBandLabel($intensity);

            // "Zone: intenso 8/10; cualidad: ...; ..."
            $parts = [$zoneLabel . ': ' . $band . ' ' . $intensity . '/10'];

            $fields = [
                xl('Quality')          => $mark['pain_quality'] ?? '',
                xl('Radiation')        => $mark['radiation'] ?? '',
                xl('Onset')            => $mark['onset'] ?? '',
                xl('Duration')         => $mark['duration'] ?? '',
                xl('Aggravating')      => $mark['aggravating'] ?? '',
                xl('Relieving')        => $mark['relieving'] ?? '',
                xl('Notes')            => $mark['notes'] ?? '',
            ];
            foreach ($fields as $label => $value) {
                $value = trim((string)$value);
                if ($value !== '') {
                    $parts[] = $label . ': ' . $value;
                }
            }

            $lines[] = implode('; ', $parts);
        }
    }

    $generalNotes = trim((string)($generalNotes ?? ''));
    if ($generalNotes !== '') {
        $lines[] = xl('General Notes') . ': ' . $generalNotes;
    }

    return implode("\n", $lines);
}

// ---------------------------------------------------------------------------
// Report figure (encounter report / PDF)
// ---------------------------------------------------------------------------

/**
 * Picks the figure + view that holds the most marks, so a single representative
 * panel is shown in the encounter report. Ties break toward adult_male/anterior
 * for deterministic output.
 *
 * @param array<int, array<string, mixed>> $marks
 * @return array{figure: string, view: string}|null Null when there are no marks.
 */
function pz_pickReportPanel(array $marks): ?array
{
    $counts = [];
    foreach ($marks as $mark) {
        $figure = (string)($mark['figure'] ?? '');
        $view   = (string)($mark['body_view'] ?? '');
        if (!isset(PZ_FIGURE_MAP[$figure][$view])) {
            continue;
        }
        $key = $figure . '|' . $view;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }

    if ($counts === []) {
        return null;
    }

    $order = [];
    foreach (array_keys(PZ_FIGURE_MAP) as $figure) {
        foreach (['anterior', 'posterior'] as $view) {
            if (isset(PZ_FIGURE_MAP[$figure][$view])) {
                $order[] = $figure . '|' . $view;
            }
        }
    }

    $best = null;
    $bestCount = -1;
    foreach ($order as $key) {
        $count = $counts[$key] ?? 0;
        if ($count > $bestCount) {
            $bestCount = $count;
            $best = $key;
        }
    }

    if ($best === null || $bestCount <= 0) {
        return null;
    }

    [$figure, $view] = explode('|', $best);

    return ['figure' => $figure, 'view' => $view];
}

/**
 * Builds a ready-to-embed SVG for the encounter report: the representative panel
 * with its zones tinted by intensity and one pin per mark.
 *
 * Returns two renderings of the same figure:
 *   - 'svg'      : inline, sanitised SVG with the pins as small groups; the
 *                  caller can attach hover tooltips in the browser.
 *   - 'dataUri'  : the same SVG base64-encoded as an <img> source, which is the
 *                  only form mPDF actually draws in the PDF export.
 *
 * @param array<int, array<string, mixed>> $marks Active marks of one form.
 * @return array{
 *     svg: string,
 *     dataUri: string,
 *     figure: string,
 *     view: string,
 *     pins: array<int, array{markId: string, zone: string, intensity: int, band: string, color: string, details: array<int, array{label: string, value: string}>, title: string}>
 * }|null
 */
function pz_buildReportFigure(array $marks): ?array
{
    $panel = pz_pickReportPanel($marks);
    if ($panel === null) {
        return null;
    }

    $figure = $panel['figure'];
    $view   = $panel['view'];

    try {
        $svg        = pz_getSvgContent($figure, $view);
        $validZones = pz_getSvgZoneIds($figure, $view);
    } catch (\Throwable) {
        return null;
    }

    $palette  = pz_getLevelColors();
    $validSet = array_flip($validZones);
    $zoneHeat = [];
    $pins     = [];
    $pinData  = [];

    foreach ($marks as $mark) {
        if ((string)($mark['figure'] ?? '') !== $figure) {
            continue;
        }
        if ((string)($mark['body_view'] ?? '') !== $view) {
            continue;
        }

        $zoneId    = (string)($mark['zone_id'] ?? '');
        $intensity = (int)($mark['intensity'] ?? 0);
        $intensity = max(0, min(10, $intensity));

        // One zone tile per zone, keeping the highest intensity in this panel.
        if ($zoneId !== '' && isset($validSet[$zoneId])) {
            if (!isset($zoneHeat[$zoneId]) || $intensity > $zoneHeat[$zoneId]['level']) {
                $color = $palette['level_' . $intensity] ?? ['fill' => '#BDBDBD', 'stroke' => '#848484'];
                $zoneHeat[$zoneId] = [
                    'level'   => $intensity,
                    'color'   => $color,
                    'tooltip' => pz_zoneLabel($zoneId) . ' - ' . $intensity . '/10',
                ];
            }
        }

        $laterality = match ((string)($mark['laterality'] ?? 'midline')) {
            'left'  => xl('Left'),
            'right' => xl('Right'),
            default => xl('Midline'),
        };
        $pins[] = [
            'mark'    => $mark,
            'color'   => $palette['level_' . $intensity]['fill'] ?? '#BDBDBD',
            'tooltip' => pz_zoneLabel($zoneId) . ' (' . $laterality . ') - ' . $intensity . '/10',
        ];

        $pinData[] = [
            'markId'    => (string)($mark['id'] ?? ''),
            'zone'      => $zoneId,
            'intensity' => $intensity,
            'band'      => pz_intensityBandLabel($intensity),
            'color'     => $palette['level_' . $intensity]['fill'] ?? '#455A64',
            'details'   => pz_reportMarkDetails($mark, $laterality),
            'title'     => pz_zoneLabel($zoneId) . ' (' . $laterality . ')',
        ];
    }

    // Drop the helper key before tinting; pz_svgApplyHeat only reads color/tooltip.
    $heatPayload = [];
    foreach ($zoneHeat as $zoneId => $heat) {
        $heatPayload[$zoneId] = ['color' => $heat['color'], 'tooltip' => $heat['tooltip']];
    }

    $svg = pz_svgApplyHeat($svg, $heatPayload, $validZones);
    $svg = pz_svgAddPins($svg, $pins);
    $svg = pz_sanitizeSvgForPdf($svg);

    return [
        'svg'     => $svg,
        'dataUri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
        'figure'  => $figure,
        'view'    => $view,
        'pins'    => $pinData,
    ];
}

/**
 * Collects the clinical detail lines of a mark for the report tooltip.
 * Only non-empty fields are returned, each already translated.
 *
 * @param array<string, mixed> $mark
 * @return array<int, array{label: string, value: string}>
 */
function pz_reportMarkDetails(array $mark, string $laterality): array
{
    $details = [];
    $details[] = ['label' => xl('Laterality'), 'value' => $laterality];

    $quality = trim((string)($mark['pain_quality'] ?? ''));
    if ($quality !== '') {
        $details[] = ['label' => xl('Quality'), 'value' => pz_qualityLabel($quality)];
    }

    foreach ([
        'radiation'   => xl('Radiation'),
        'onset'       => xl('Onset'),
        'duration'    => xl('Duration'),
        'aggravating' => xl('Aggravating'),
        'relieving'   => xl('Relieving'),
        'notes'       => xl('Notes'),
    ] as $field => $label) {
        $value = trim((string)($mark[$field] ?? ''));
        if ($value !== '') {
            $details[] = ['label' => $label, 'value' => $value];
        }
    }

    return $details;
}

// ---------------------------------------------------------------------------
// Patient helpers
// ---------------------------------------------------------------------------

/**
 * Returns the patient's recorded sex at birth (patient_data.sex).
 *
 * OpenEMR stores the value using the 'sex' list_options whose options are
 * Female / Male / UNK. An empty or 'UNK' value means the sex is not defined.
 *
 * @return string Raw value as stored (may be empty).
 */
function pz_patientSex(int $pid): string
{
    if ($pid <= 0) {
        return '';
    }

    $rows = \OpenEMR\Common\Database\QueryUtils::fetchRecords(
        'SELECT sex FROM patient_data WHERE pid = ? LIMIT 1',
        [$pid]
    );

    return trim((string)($rows[0]['sex'] ?? ''));
}

/**
 * Maps the patient's recorded sex to the body figure that should be shown.
 *
 * OpenEMR stores the value in patient_data.sex using the 'sex' list_options
 * (Female / Male / UNK). Anything that is not explicitly female falls back to
 * the male figure, which is the historical default of the module.
 *
 * @return string 'adult_female' or 'adult_male'
 */
function pz_patientFigure(int $pid): string
{
    return (strcasecmp(pz_patientSex($pid), 'female') === 0) ? 'adult_female' : 'adult_male';
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
