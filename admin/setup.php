<?php
/* Copyright (C) 2026 AuVo Design
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    auvoshopware/admin/setup.php
 * \ingroup auvoshopware
 * \brief   AuVoShopware setup page: Shopware connection and sync scope.
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/auvoshopware.lib.php';
require_once '../class/ShopwareClient.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "auvoshopware@auvoshopware"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('auvoshopwaresetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;

// Access control
if (!$user->admin) {
	accessforbidden();
}

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);

// Connection to the Shopware shop
$item = $formSetup->newItem('AUVOSHOPWARE_SW_URL');
$item->fieldParams['isMandatory'] = 1;
$item->fieldAttr['placeholder'] = (empty($_SERVER['HTTPS']) ? 'http://' : 'https://').'my-shop.example.com';
$item->cssClass = 'minwidth500';

$item = $formSetup->newItem('AUVOSHOPWARE_SW_CLIENT_ID');
$item->fieldParams['isMandatory'] = 1;
$item->helpText = $langs->trans('AUVOSHOPWARE_SW_CLIENT_IDTooltip');
$item->cssClass = 'minwidth300';

$item = $formSetup->newItem('AUVOSHOPWARE_SW_CLIENT_SECRET');
$item->fieldParams['isMandatory'] = 1;
$item->setAsSecureKey();
$item->helpText = $langs->trans('AUVOSHOPWARE_SW_CLIENT_SECRETTooltip');

// Scope of the synchronization
$formSetup->newItem('AuVoShopwareSyncScope')->setAsTitle();

$item = $formSetup->newItem('AUVOSHOPWARE_CATEGORY_ID');
$item->setAsCategory('product');
$item->helpText = $langs->trans('AUVOSHOPWARE_CATEGORY_IDTooltip');

$item = $formSetup->newItem('AUVOSHOPWARE_SW_PARENT_CATEGORY_ID');
$item->helpText = $langs->trans('AUVOSHOPWARE_SW_PARENT_CATEGORY_IDTooltip');
$item->cssClass = 'minwidth300';

$item = $formSetup->newItem('AUVOSHOPWARE_SW_TAX_ID');
$item->helpText = $langs->trans('AUVOSHOPWARE_SW_TAX_IDTooltip');
$item->cssClass = 'minwidth300';

$item = $formSetup->newItem('AUVOSHOPWARE_SW_SALES_CHANNEL_ID');
$item->helpText = $langs->trans('AUVOSHOPWARE_SW_SALES_CHANNEL_IDTooltip');
$item->cssClass = 'minwidth300';

// AUVOSHOPWARE_ORDER_STATE_SCOPE is added once ShopwareClient exists (phase 3+): its options
// are loaded live from the shop's order state machine, same pattern as auvopresta's order
// state multiselect (see PLAN.md phase 8).

$setupnotempty = count($formSetup->items);


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'testconnection') {
	$swClient = new ShopwareClient(
		getDolGlobalString('AUVOSHOPWARE_SW_URL'),
		getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID'),
		getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET')
	);
	if ($swClient->testConnection()) {
		setEventMessages($langs->trans('AuVoShopwareConnectionOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('AuVoShopwareConnectionError', $swClient->error), null, 'errors');
	}
}

$action = 'edit';


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$title = "AuVoShopwareSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-auvoshopware page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
$head = auvoshopwareAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, "auvoshopware@auvoshopware");

print '<span class="opacitymedium">'.$langs->trans("AuVoShopwareSetupPage").'</span><br><br>';

if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
	print '<br>';
}

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=testconnection&token='.newToken().'">'.$langs->trans("AuVoShopwareTestConnection").'</a>';
print '</div>';

if (empty($setupnotempty)) {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
