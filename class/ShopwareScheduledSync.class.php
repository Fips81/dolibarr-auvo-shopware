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

require_once __DIR__.'/ShopwareClient.class.php';
require_once __DIR__.'/ShopwareProductSync.class.php';

/**
 * \file    auvoshopware/class/ShopwareScheduledSync.class.php
 * \ingroup auvoshopware
 * \brief   Entry point for the "Sync with Shopware" Dolibarr scheduled job (cron).
 *
 * Dolibarr's cron runner instantiates this with just $db and calls a method on it, so it can't
 * take the same constructor as ShopwareProductSync (which needs a configured API client) - this
 * builds one from the module's saved settings instead. Order fetch (PLAN.md phase 6) will be
 * added to syncAll() here once it exists, same as auvopresta's PrestaShopScheduledSync does for
 * PrestaShop orders.
 */
class ShopwareScheduledSync
{
	/**
	 * @var string Error message, read by the Dolibarr cron runner after a failed run
	 */
	public $error = '';

	/**
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * @var string Output message, read by the Dolibarr cron runner and shown in the job history
	 */
	public $output = '';

	/**
	 * @var DoliDB
	 */
	protected $db;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Sync every product currently in scope. Called by Dolibarr's scheduled jobs runner.
	 *
	 * @return int 0 if OK, <>0 if error
	 */
	public function syncAll()
	{
		if (function_exists('set_time_limit') && stripos((string) ini_get('disable_functions'), 'set_time_limit') === false) {
			set_time_limit(0);
		}

		if (!getDolGlobalString('AUVOSHOPWARE_SW_URL') || !getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID')
			|| !getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET') || !getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID')) {
			$this->error = 'AuVoShopware is not fully configured, skipping scheduled sync';
			return -1;
		}

		$client = new ShopwareClient(
			getDolGlobalString('AUVOSHOPWARE_SW_URL'),
			getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_ID'),
			getDolGlobalString('AUVOSHOPWARE_SW_CLIENT_SECRET')
		);

		$sync = new ShopwareProductSync($this->db, $client);
		$summary = $sync->syncAll();

		$this->output = sprintf(
			'AuVoShopware sync: %d ok, %d skipped (unchanged), %d error(s)',
			$summary['ok'],
			$summary['skipped'],
			$summary['error']
		);

		if ($summary['error'] > 0) {
			foreach ($summary['details'] as $productId => $detail) {
				if ($detail['status'] === 'error') {
					$this->errors[] = 'Product '.$productId.': '.($detail['error'] ?? 'unknown error');
				}
			}
			$this->error = implode('; ', $this->errors);
		}

		return $summary['error'] > 0 && $summary['ok'] === 0 ? -1 : 0;
	}
}
