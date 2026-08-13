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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

/**
 * \file    auvoshopware/class/ShopwareOrderSync.class.php
 * \ingroup auvoshopware
 * \brief   Pull in-scope Shopware orders into Dolibarr as sales orders (Commande) - the reverse
 *          direction of ShopwareProductSync, via the Admin API's Search route (unlike
 *          PrestaShop, Shopware has a single API that covers orders too, no separate legacy
 *          client needed).
 */
class ShopwareOrderSync
{
	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var DoliDB
	 */
	protected $db;

	/**
	 * @var User
	 */
	protected $user;

	/**
	 * @var ShopwareClient
	 */
	protected $client;

	/**
	 * @param DoliDB        $db
	 * @param User          $user
	 * @param ShopwareClient $client
	 */
	public function __construct($db, User $user, ShopwareClient $client)
	{
		$this->db = $db;
		$this->user = $user;
		$this->client = $client;
	}

	/**
	 * Import every Shopware order that is in the configured import scope (setup page,
	 * AUVOSHOPWARE_ORDER_STATE_SCOPE - falls back to "every order except cancelled ones" if left
	 * unconfigured) and hasn't already been successfully imported.
	 *
	 * @return array{ok:int, skipped:int, error:int, details:array<string,array>}
	 */
	public function fetchNewOrders()
	{
		$summary = array('ok' => 0, 'skipped' => 0, 'error' => 0, 'details' => array());

		$orders = $this->fetchOrdersFromShopware();
		if ($orders === false) {
			$this->error = 'Could not fetch orders from Shopware: '.$this->client->error;
			return $summary;
		}

		foreach ($orders as $swOrder) {
			$swOrderId = (string) ($swOrder['id'] ?? '');
			if ($swOrderId === '' || !$this->isOrderInScope($swOrder)) {
				continue;
			}

			$map = $this->getOrderMap($swOrderId);
			if ($map !== null && $map['last_sync_status'] === 'ok') {
				$summary['skipped']++;
				continue;
			}

			$orderNumber = (string) ($swOrder['orderNumber'] ?? $swOrderId);
			$result = $this->importOrder($swOrder);

			if ($result['status'] === 'ok') {
				$summary['ok']++;
				$this->saveOrderMap($swOrderId, $result['fk_commande'], $orderNumber, 'ok', null);
			} else {
				$summary['error']++;
				$this->saveOrderMap($swOrderId, 0, $orderNumber, 'error', $result['error']);
			}

			$summary['details'][$swOrderId] = $result;
		}

		return $summary;
	}

	/**
	 * Import a single Shopware order as a validated Dolibarr sales order.
	 *
	 * @param array $swOrder Full order detail from fetchOrdersFromShopware(), with lineItems,
	 *                        orderCustomer and addresses associations loaded
	 * @return array{status:string, fk_commande?:int, error?:string}
	 */
	public function importOrder(array $swOrder)
	{
		$orderNumber = (string) ($swOrder['orderNumber'] ?? ($swOrder['id'] ?? ''));

		$customerData = $swOrder['orderCustomer'] ?? array();
		$email = trim((string) ($customerData['email'] ?? ''));
		if ($email === '') {
			return array('status' => 'error', 'error' => 'Shopware order '.$orderNumber.' has no customer email');
		}

		$societe = new Societe($this->db);
		if ($societe->fetch(0, '', '', '', '', '', '', '', '', '', $email) <= 0) {
			$societe = new Societe($this->db);
			$firstname = trim((string) ($customerData['firstName'] ?? ''));
			$lastname = trim((string) ($customerData['lastName'] ?? ''));
			$company = trim((string) ($customerData['company'] ?? ''));

			$societe->name = $company !== '' ? $company : trim($firstname.' '.$lastname);
			if ($societe->name === '') {
				$societe->name = $email;
			}
			$societe->client = 1;
			// Auto-numbering (-1) requires a customer-code numbering module to be configured,
			// which isn't guaranteed - assign our own code instead. Customer codes here are
			// manually assigned in the 000001-range, so shop customers start at 100001 and count
			// up, keeping the two ranges visibly distinct and non-colliding (same convention as
			// auvopresta's PrestaShopOrderSync).
			$societe->code_client = $this->getNextCustomerCode();
			$societe->email = $email;

			$billingAddress = $this->findAddress($swOrder, (string) ($swOrder['billingAddressId'] ?? ''));
			if ($billingAddress !== null) {
				$societe->address = trim((string) ($billingAddress['street'] ?? ''));
				$societe->zip = (string) ($billingAddress['zipcode'] ?? '');
				$societe->town = (string) ($billingAddress['city'] ?? '');
				$societe->phone = (string) ($billingAddress['phoneNumber'] ?? '');
			}

			if ($societe->create($this->user) <= 0) {
				return array('status' => 'error', 'error' => 'Failed to create customer: '.$this->objectErrorMessage($societe));
			}
		}

		$commande = new Commande($this->db);
		$commande->socid = $societe->id;
		$commande->date = !empty($swOrder['orderDateTime']) ? strtotime($swOrder['orderDateTime']) : dol_now();
		$commande->ref_ext = $orderNumber;
		$commande->note_public = 'Shopware-Bestellung '.$orderNumber;

		if ($commande->create($this->user) <= 0) {
			return array('status' => 'error', 'error' => 'Failed to create order: '.$this->objectErrorMessage($commande));
		}

		foreach ($swOrder['lineItems'] ?? array() as $lineItem) {
			if (($lineItem['type'] ?? '') !== 'product') {
				// Skip credits, custom/discount lines, shipping costs, etc. - only real product
				// lines can be resolved back to a Dolibarr product and belong on the order.
				continue;
			}

			$productNumber = (string) ($lineItem['payload']['productNumber'] ?? '');
			$fkProduct = $productNumber !== '' ? $this->findDolProductByRef($productNumber) : null;

			$qty = (float) ($lineItem['quantity'] ?? 1);
			$label = (string) ($lineItem['label'] ?? 'Artikel');
			$unitPriceGross = (float) ($lineItem['unitPrice'] ?? 0);

			$taxRate = 0.0;
			$taxRules = $lineItem['price']['taxRules'] ?? array();
			if (!empty($taxRules[0]['taxRate'])) {
				$taxRate = (float) $taxRules[0]['taxRate'];
			}
			$unitPriceNet = $taxRate > 0 ? round($unitPriceGross / (1 + $taxRate / 100), 2) : $unitPriceGross;

			$commande->addline($label, $unitPriceNet, $qty, $taxRate, 0, 0, (int) $fkProduct);
		}

		$commande->valid($this->user);

		return array('status' => 'ok', 'fk_commande' => $commande->id);
	}

	/**
	 * Push a Dolibarr order's status to its linked Shopware order via a state-machine
	 * transition, if the current Dolibarr status has a defined Shopware counterpart (draft/
	 * validated have none - "open" is already what a freshly imported order sits at in
	 * Shopware, so there's nothing to push for those). Returns 'skipped' if there's no mapped
	 * target, the Shopware order is already there, or it has no outgoing transition at all
	 * (terminal state).
	 *
	 * @param int $fkCommande
	 * @return array{status:string, error?:string}
	 */
	public function pushOrderStatus($fkCommande)
	{
		$commande = new Commande($this->db);
		if ($commande->fetch($fkCommande) <= 0) {
			return array('status' => 'error', 'error' => 'Dolibarr order not found: '.$fkCommande);
		}

		$map = $this->getOrderMapByCommande($fkCommande);
		if ($map === null) {
			return array('status' => 'error', 'error' => 'No Shopware order linked to this Dolibarr order');
		}

		$target = $this->targetShopwareStateForStatus((int) $commande->status);
		if ($target === null) {
			return array('status' => 'skipped');
		}

		return $this->transitionOrderTo($map['sw_order_id'], $target);
	}

	/**
	 * Which Shopware order.state technical name a Dolibarr order status maps to, if any.
	 *
	 * @param int $status One of Commande::STATUS_*
	 * @return string|null
	 */
	protected function targetShopwareStateForStatus($status)
	{
		if ($status === Commande::STATUS_SHIPMENTONPROCESS) {
			return 'in_progress';
		}
		if ($status === Commande::STATUS_CLOSED) {
			return 'completed';
		}
		if ($status === Commande::STATUS_CANCELED) {
			return 'cancelled';
		}

		return null;
	}

	/**
	 * Drive a Shopware order's state machine towards $targetTechnicalName, one transition at a
	 * time, always picking from the API's own "available transitions from the current state"
	 * list (see ShopwareClient::getAvailableOrderTransitions()) rather than guessing an action
	 * name. Shopware's default order.state machine is a linear chain (open -> in_progress ->
	 * completed, plus a cancel branch from either), so a direct match is usually reachable
	 * within a couple of hops; if a state ever offers more than one way forward with no direct
	 * match to the target, that's ambiguous and reported as an error instead of guessed at.
	 *
	 * @param string $swOrderId
	 * @param string $targetTechnicalName
	 * @param int    $maxHops
	 * @return array{status:string, error?:string}
	 */
	protected function transitionOrderTo($swOrderId, $targetTechnicalName, $maxHops = 3)
	{
		for ($hop = 0; $hop < $maxHops; $hop++) {
			$transitions = $this->client->getAvailableOrderTransitions($swOrderId);
			if ($transitions === false) {
				return array('status' => 'error', 'error' => $this->client->error);
			}

			if (empty($transitions)) {
				return array('status' => 'skipped');
			}

			$currentStateName = $transitions[0]['fromStateName'] ?? null;
			if ($currentStateName === $targetTechnicalName) {
				return array('status' => 'skipped');
			}

			$directMatch = null;
			foreach ($transitions as $transition) {
				if (($transition['toStateName'] ?? null) === $targetTechnicalName) {
					$directMatch = $transition;
					break;
				}
			}

			if ($directMatch !== null) {
				if ($this->client->transitionOrderState($swOrderId, $directMatch['actionName']) === false) {
					return array('status' => 'error', 'error' => $this->client->error);
				}
				return array('status' => 'ok');
			}

			if (count($transitions) !== 1) {
				return array('status' => 'error', 'error' => 'Ambiguous transition from Shopware order state "'.$currentStateName.'": none of the available actions lead to "'.$targetTechnicalName.'"');
			}

			// Exactly one way forward from here - take it and re-check from the new state.
			if ($this->client->transitionOrderState($swOrderId, $transitions[0]['actionName']) === false) {
				return array('status' => 'error', 'error' => $this->client->error);
			}
		}

		return array('status' => 'error', 'error' => 'Could not reach Shopware order state "'.$targetTechnicalName.'" within '.$maxHops.' transition(s)');
	}

	/**
	 * Reverse lookup: find the order mapping row for a Dolibarr order id.
	 *
	 * @param int $fkCommande
	 * @return array{sw_order_id:string}|null
	 */
	protected function getOrderMapByCommande($fkCommande)
	{
		global $conf;

		$sql = 'SELECT sw_order_id FROM '.$this->db->prefix().'auvoshopware_order_map';
		$sql .= ' WHERE fk_commande = '.((int) $fkCommande);
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('sw_order_id' => $obj->sw_order_id);
		}

		return null;
	}

	/**
	 * List every Shopware order imported so far (for the dashboard).
	 *
	 * @return array<int,array>
	 */
	public function getImportedOrders()
	{
		global $conf;

		$sql = 'SELECT sw_order_id, fk_commande, sw_order_number, last_sync_date, last_sync_status, last_sync_error';
		$sql .= ' FROM '.$this->db->prefix().'auvoshopware_order_map';
		$sql .= ' WHERE entity = '.((int) $conf->entity);
		$sql .= ' ORDER BY last_sync_date DESC';

		$resql = $this->db->query($sql);
		$rows = array();
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					'sw_order_id' => $obj->sw_order_id,
					'fk_commande' => (int) $obj->fk_commande,
					'sw_order_number' => $obj->sw_order_number,
					'last_sync_date' => $obj->last_sync_date,
					'last_sync_status' => $obj->last_sync_status,
					'last_sync_error' => $obj->last_sync_error,
				);
			}
		}

		return $rows;
	}

	/**
	 * Pull every order from Shopware, with the associations importOrder()/isOrderInScope() need
	 * (line items, customer, billing address, overall order status) loaded in the same round trip.
	 * Paginates through the Search API's page-based results (Shopware caps a single page at a
	 * few hundred rows) rather than assuming everything fits in one response.
	 *
	 * @return array<int,array>|false Array of raw Shopware order entities, false on error (see $this->error)
	 */
	protected function fetchOrdersFromShopware()
	{
		$limit = 100;
		$page = 1;
		$all = array();

		do {
			$criteria = array(
				'associations' => array(
					'lineItems' => new stdClass(),
					'orderCustomer' => new stdClass(),
					'addresses' => new stdClass(),
					'stateMachineState' => new stdClass(),
				),
				'sort' => array(array('field' => 'orderDateTime', 'order' => 'ASC')),
				'limit' => $limit,
				'page' => $page,
			);

			$result = $this->client->search('order', $criteria);
			if ($result === false) {
				return false;
			}

			$data = is_array($result['data'] ?? null) ? $result['data'] : array();
			$all = array_merge($all, $data);

			$page++;
		} while (count($data) === $limit);

		return $all;
	}

	/**
	 * Whether a Shopware order is in the configured import scope. Deliberately filters on the
	 * overall order status (order.state: open/in_progress/completed/cancelled), not payment
	 * status - "Rechnung"/"Nachnahme" orders sit in an unpaid transaction state until well after
	 * they need to be fulfilled, so gating import on "paid" would silently skip them.
	 *
	 * @param array $swOrder
	 * @return bool
	 */
	protected function isOrderInScope(array $swOrder)
	{
		$allowedIds = $this->getConfiguredOrderStateIds();
		if (!empty($allowedIds)) {
			return in_array((string) ($swOrder['stateId'] ?? ''), $allowedIds, true);
		}

		// Unconfigured: import everything except cancelled orders.
		$technicalName = $swOrder['stateMachineState']['technicalName'] ?? null;
		return $technicalName !== 'cancelled';
	}

	/**
	 * Order state ids allowed to be imported, per the setup page's multi-select
	 * (AUVOSHOPWARE_ORDER_STATE_SCOPE, stored comma-separated Shopware state-machine-state
	 * UUIDs). Empty means "not configured" - isOrderInScope() applies its own default then.
	 *
	 * @return string[]
	 */
	protected function getConfiguredOrderStateIds()
	{
		$raw = getDolGlobalString('AUVOSHOPWARE_ORDER_STATE_SCOPE');
		if ($raw === '') {
			return array();
		}

		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}

	/**
	 * Find the order_address entry matching a given address id within an already-fetched order's
	 * "addresses" association (avoids a separate API call per order).
	 *
	 * @param array  $swOrder
	 * @param string $addressId
	 * @return array|null
	 */
	protected function findAddress(array $swOrder, $addressId)
	{
		if ($addressId === '') {
			return null;
		}

		foreach ($swOrder['addresses'] ?? array() as $address) {
			if (($address['id'] ?? '') === $addressId) {
				return $address;
			}
		}

		return null;
	}

	/**
	 * Find the Dolibarr product whose reference matches a Shopware order line's productNumber.
	 * No mapping table lookup needed here - unlike auvopresta's PrestaShop integer product ids,
	 * ShopwareProductSync::productUuid() derives the Shopware id from the Dolibarr ref
	 * deterministically, and productNumber is set to that same ref (see buildProductPayload()),
	 * so the ref itself is the shared key between both systems.
	 *
	 * @param string $ref
	 * @return int|null
	 */
	protected function findDolProductByRef($ref)
	{
		global $conf;

		$sql = 'SELECT rowid FROM '.$this->db->prefix().'product';
		$sql .= " WHERE ref = '".$this->db->escape($ref)."'";
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}

		return null;
	}

	/**
	 * Next customer code for a newly created shop customer, starting at 100001 and counting up
	 * (customer codes here are otherwise manually assigned starting at 000001).
	 *
	 * @return string
	 */
	protected function getNextCustomerCode()
	{
		global $conf;

		$next = getDolGlobalInt('AUVOSHOPWARE_NEXT_SHOP_CUSTOMER_CODE', 100001);
		dolibarr_set_const($this->db, 'AUVOSHOPWARE_NEXT_SHOP_CUSTOMER_CODE', (string) ($next + 1), 'chaine', 0, '', $conf->entity);

		return sprintf('%06d', $next);
	}

	/**
	 * Build a readable error message from a Dolibarr CommonObject after a failed create().
	 *
	 * @param CommonObject $object
	 * @return string
	 */
	protected function objectErrorMessage($object)
	{
		if (!empty($object->errors)) {
			return implode('; ', $object->errors);
		}

		return (string) $object->error;
	}

	/**
	 * @param string $swOrderId
	 * @return array{fk_commande:int, last_sync_status:?string}|null
	 */
	protected function getOrderMap($swOrderId)
	{
		global $conf;

		$sql = 'SELECT fk_commande, last_sync_status FROM '.$this->db->prefix().'auvoshopware_order_map';
		$sql .= " WHERE sw_order_id = '".$this->db->escape($swOrderId)."'";
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('fk_commande' => (int) $obj->fk_commande, 'last_sync_status' => $obj->last_sync_status);
		}

		return null;
	}

	/**
	 * Insert or update the mapping row for a Shopware order after an import attempt.
	 *
	 * @param string      $swOrderId
	 * @param int         $fkCommande
	 * @param string      $orderNumber
	 * @param string      $status 'ok' or 'error'
	 * @param string|null $error
	 * @return void
	 */
	protected function saveOrderMap($swOrderId, $fkCommande, $orderNumber, $status, $error)
	{
		global $conf;

		$now = $this->db->idate(dol_now());
		$exists = $this->getOrderMap($swOrderId) !== null;

		if ($exists) {
			$sql = 'UPDATE '.$this->db->prefix().'auvoshopware_order_map SET';
			$sql .= ' fk_commande = '.((int) $fkCommande).',';
			$sql .= " sw_order_number = '".$this->db->escape($orderNumber)."',";
			$sql .= " last_sync_date = '".$this->db->escape($now)."',";
			$sql .= " last_sync_status = '".$this->db->escape($status)."',";
			$sql .= ' last_sync_error = '.($error !== null ? "'".$this->db->escape($error)."'" : 'NULL');
			$sql .= " WHERE sw_order_id = '".$this->db->escape($swOrderId)."' AND entity = ".((int) $conf->entity);
		} else {
			$sql = 'INSERT INTO '.$this->db->prefix().'auvoshopware_order_map';
			$sql .= ' (sw_order_id, fk_commande, sw_order_number, last_sync_date, last_sync_status, last_sync_error, entity)';
			$sql .= ' VALUES (';
			$sql .= "'".$this->db->escape($swOrderId)."',";
			$sql .= ((int) $fkCommande).',';
			$sql .= "'".$this->db->escape($orderNumber)."',";
			$sql .= "'".$this->db->escape($now)."',";
			$sql .= "'".$this->db->escape($status)."',";
			$sql .= ($error !== null ? "'".$this->db->escape($error)."'" : 'NULL').',';
			$sql .= ((int) $conf->entity).')';
		}

		$this->db->query($sql);
	}
}
