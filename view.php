<?php

/**
 * view.php  -  Pain Zones encounter form view redirect.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once __DIR__ . '/pain_zones.inc.php';

use OpenEMR\Common\Acl\AccessDeniedHelper;

// 1. ACL check: require read permission to view this form.
pz_requireRead();

// 2. Validate form ID (> 0).
$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($formId <= 0) {
    AccessDeniedHelper::deny('Invalid form ID for pain_zones view');
}

// 3. Ownership check: assert form belongs to active session patient.
pz_assertFormBelongsToSessionPatient($formId, 'pain_zones');

// 4. Redirect to new.php; new.php manages view/read-only mode.
$rootdir = pz_rootdir();
header('Location: ' . $rootdir . '/forms/pain_zones/new.php?id=' . (int)$formId);
exit;
