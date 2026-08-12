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

// Load translation files required by the page
$langs->loadLangs(array("auvoshopware@auvoshopware"));

// Access control
if (!$user->hasRight('auvoshopware', 'sync', 'read')) {
	accessforbidden();
}

$isConfigured = getDolGlobalString('AUVOSHOPWARE_SW_URL')
	&& getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID')
	&& getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET')
	&& getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');


/*
 * Actions
 */

// None yet: product/order sync (class/ShopwareClient.class.php and friends) lands in a later phase.


/*
 * View
 */

llxHeader("", $langs->trans("AuVoShopwareArea"), '', '', 0, 0, '', '', '', 'mod-auvoshopware page-index');

print load_fiche_titre($langs->trans("AuVoShopwareArea"), '', 'auvoshopware.png@auvoshopware');

if (!$isConfigured) {
	print info_admin($langs->trans('AuVoShopwareNotConfigured', DOL_URL_ROOT.'/custom/auvoshopware/admin/setup.php'), 0, 0, 'warning');
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans("AuVoShopwareConnectionSummary").'</th></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AUVOSHOPWARE_SW_URL").'</td><td>'.dol_escape_htmltag(getDolGlobalString('AUVOSHOPWARE_SW_URL')).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AUVOSHOPWARE_CATEGORY_ID").'</td><td>'.getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID').'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AUVOSHOPWARE_SW_PARENT_CATEGORY_ID").'</td><td>'.dol_escape_htmltag(getDolGlobalString('AUVOSHOPWARE_SW_PARENT_CATEGORY_ID')).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AUVOSHOPWARE_SW_TAX_ID").'</td><td>'.dol_escape_htmltag(getDolGlobalString('AUVOSHOPWARE_SW_TAX_ID')).'</td></tr>';
	print '<tr class="oddeven"><td>'.$langs->trans("AUVOSHOPWARE_SW_SALES_CHANNEL_ID").'</td><td>'.dol_escape_htmltag(getDolGlobalString('AUVOSHOPWARE_SW_SALES_CHANNEL_ID')).'</td></tr>';
	print '</table>';
	print '<br>';

	print info_admin($langs->trans('AuVoShopwareSyncNotImplementedYet'), 0, 0, 'info');
}

// End of page
llxFooter();
$db->close();
