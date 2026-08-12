<?php
/* Copyright (C) 2026 AuVo Design
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       auvoshopware/auvoshopwareindex.php
 *	\ingroup    auvoshopware
 *	\brief      Dashboard to review and trigger the Shopware synchronization
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once __DIR__.'/class/ShopwareClient.class.php';
require_once __DIR__.'/class/ShopwareProductSync.class.php';

// Load translation files required by the page
$langs->loadLangs(array("auvoshopware@auvoshopware"));

$action = GETPOST('action', 'aZ09');
$productId = GETPOSTINT('id');

// Access control
if (!$user->hasRight('auvoshopware', 'sync', 'read')) {
	accessforbidden();
}
$canSync = $user->hasRight('auvoshopware', 'sync', 'write');

$swClient = new ShopwareClient(
	getDolGlobalString('AUVOSHOPWARE_SW_URL'),
	getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID'),
	getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET')
);

$sync = new ShopwareProductSync($db, $swClient);

$isConfigured = getDolGlobalString('AUVOSHOPWARE_SW_URL')
	&& getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID')
	&& getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET')
	&& getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');


/*
 * Actions
 */

if (in_array($action, array('syncall', 'syncone'), true) && function_exists('set_time_limit')
	&& stripos((string) ini_get('disable_functions'), 'set_time_limit') === false) {
	// A large catalog can need minutes of sequential Sync API round trips - well past a typical
	// web request's default execution time limit (see PLAN.md: this is exactly what crashed
	// auvopresta mid-sync before batching was in place).
	set_time_limit(0);
}

if ($action == 'syncall' && $isConfigured) {
	if (!$canSync) {
		accessforbidden();
	}
	$summary = $sync->syncAll();
	$msg = $langs->trans('AuVoShopwareSyncAllResult', $summary['ok'], $summary['skipped'], $summary['error']);
	if ($summary['error'] > 0) {
		setEventMessages($msg, null, 'warnings');
	} else {
		setEventMessages($msg, null, 'mesgs');
	}
} elseif ($action == 'syncone' && $productId > 0 && $isConfigured) {
	if (!$canSync) {
		accessforbidden();
	}
	$result = $sync->syncOne($productId, true);
	if ($result['status'] == 'ok') {
		setEventMessages($langs->trans('AuVoShopwareSyncOneOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('AuVoShopwareSyncOneError', $result['error']), null, 'errors');
	}
}


/*
 * View
 */

llxHeader("", $langs->trans("AuVoShopwareArea"), '', '', 0, 0, '', '', '', 'mod-auvoshopware page-index');

print load_fiche_titre($langs->trans("AuVoShopwareArea"), '', 'auvoshopware.png@auvoshopware');

if (!$isConfigured) {
	print info_admin($langs->trans('AuVoShopwareNotConfigured', DOL_URL_ROOT.'/custom/auvoshopware/admin/setup.php'), 0, 0, 'warning');
} else {
	$productIds = $sync->getSyncableProductIds();
	$mapRows = $sync->getMapRows($productIds);

	if ($canSync) {
		print '<div class="tabsAction">';
		print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=syncall&token='.newToken().'">'.$langs->trans("AuVoShopwareSyncAll").'</a>';
		print '</div>';
	}

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("Ref").'</th>';
	print '<th>'.$langs->trans("Label").'</th>';
	print '<th class="right">'.$langs->trans("Stock").'</th>';
	print '<th>'.$langs->trans("AuVoShopwareSwProductId").'</th>';
	print '<th>'.$langs->trans("AuVoShopwareLastSync").'</th>';
	print '<th>'.$langs->trans("Status").'</th>';
	print '<th></th>';
	print '</tr>';

	if (empty($productIds)) {
		print '<tr class="oddeven"><td colspan="7" class="opacitymedium">'.$langs->trans("AuVoShopwareNoProductInScope").'</td></tr>';
	}

	$product = new Product($db);
	foreach ($productIds as $id) {
		if ($product->fetch($id) <= 0) {
			continue;
		}

		$map = $mapRows[$id] ?? null;

		print '<tr class="oddeven">';
		print '<td>'.$product->getNomUrl(1).'</td>';
		print '<td>'.dol_escape_htmltag($product->label).'</td>';
		print '<td class="right">'.$product->stock_reel.'</td>';
		print '<td>'.($map['sw_product_id'] ?? $langs->trans("AuVoShopwareNeverSynced")).'</td>';
		print '<td>'.(!empty($map['last_sync_date']) ? dol_print_date($db->jdate($map['last_sync_date']), 'dayhour') : '').'</td>';
		print '<td>';
		if (empty($map['last_sync_status'])) {
			print '<span class="opacitymedium">'.$langs->trans("AuVoShopwareNeverSynced").'</span>';
		} elseif ($map['last_sync_status'] == 'ok') {
			print img_picto($langs->trans("Ok"), 'tick');
		} else {
			print img_warning(dol_escape_htmltag($map['last_sync_error']));
		}
		print '</td>';
		print '<td class="right">';
		if ($canSync) {
			print '<a class="button_small" href="'.$_SERVER["PHP_SELF"].'?action=syncone&id='.$product->id.'&token='.newToken().'">'.$langs->trans("AuVoShopwareSyncOne").'</a>';
		}
		print '</td>';
		print '</tr>';
	}

	print '</table>';
}

// End of page
llxFooter();
$db->close();
