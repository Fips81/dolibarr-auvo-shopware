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

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination.class.php';

/**
 * \file    auvoshopware/class/ShopwareProductSync.class.php
 * \ingroup auvoshopware
 * \brief   Push Dolibarr products (scoped by category) to Shopware via the Sync API.
 *
 * First cut (PLAN.md phase 4, "start small"): plain products only - no category mirroring, no
 * variants, no images yet (those are follow-up steps once this shape is confirmed working
 * live). Category/tax/sales-channel assignment already happens here where it's essentially
 * free (part of the same product payload), variants and images do not.
 */
class ShopwareProductSync
{
	/**
	 * Fixed seed id of Shopware's default currency (Shopware\Core\Defaults::CURRENCY), the same
	 * on every fresh Shopware 6 install - not shop-specific, so no setup field needed. Flagged
	 * here because it's a docs/training-knowledge assumption, not yet confirmed against this
	 * shop: if a sync fails with a currency-related foreign key error, this is the first thing
	 * to check against the shop's actual /api/currency list.
	 *
	 * @var string
	 */
	const DEFAULT_CURRENCY_ID = 'b7d2554b0ce847cd82f3ac9bd1c0dfca';

	/**
	 * @var string Last error message
	 */
	public $error = '';

	/**
	 * @var DoliDB
	 */
	protected $db;

	/**
	 * @var ShopwareClient
	 */
	protected $client;

	/**
	 * @param DoliDB         $db     Database handler
	 * @param ShopwareClient $client Configured Shopware Admin API client
	 */
	public function __construct($db, ShopwareClient $client)
	{
		$this->db = $db;
		$this->client = $client;
	}

	/**
	 * Whether a Dolibarr product is a variant child (one specific color/size/... combination of
	 * some parent product). Variant children are excluded from the flat product list - they'll
	 * be synced as part of their parent's combinations once variant sync is built.
	 *
	 * @param int $productId
	 * @return bool
	 */
	protected function isVariantChild($productId)
	{
		$combination = new ProductCombination($this->db);

		return $combination->fetchByFkProductChild($productId) > 0;
	}

	/**
	 * List ids of Dolibarr products that belong to the configured root category
	 * (AUVOSHOPWARE_CATEGORY_ID) or one of its subcategories, excluding variant children.
	 *
	 * @return int[]
	 */
	public function getSyncableProductIds()
	{
		$rootId = getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');
		if (empty($rootId)) {
			return array();
		}

		$tmpcateg = new Categorie($this->db);
		$fullArbo = $tmpcateg->get_full_arbo('product');

		// Walk the full category tree to collect the root category and all its descendants.
		$categIds = array($rootId => true);
		if (is_array($fullArbo)) {
			$changed = true;
			while ($changed) {
				$changed = false;
				foreach ($fullArbo as $categ) {
					$id = is_array($categ) ? ($categ['id'] ?? null) : ($categ->id ?? null);
					$parent = is_array($categ) ? ($categ['id_mere'] ?? $categ['fk_parent'] ?? 0) : ($categ->id_mere ?? $categ->fk_parent ?? 0);
					if ($id !== null && isset($categIds[$parent]) && !isset($categIds[$id])) {
						$categIds[$id] = true;
						$changed = true;
					}
				}
			}
		}

		$productIds = array();
		foreach (array_keys($categIds) as $categId) {
			$categ = new Categorie($this->db);
			if ($categ->fetch($categId) > 0) {
				$objs = $categ->getObjectsInCateg('product', 1);
				if (is_array($objs)) {
					foreach ($objs as $id) {
						$id = (int) $id;
						if (!$this->isVariantChild($id)) {
							$productIds[$id] = true;
						}
					}
				}
			}
		}

		return array_keys($productIds);
	}

	/**
	 * Deterministic Shopware entity id (32-char hex, no dashes) derived from the Dolibarr
	 * product reference. Same ref always maps to the same Shopware id, so if the local mapping
	 * table is ever reset (see the auvopresta-inherited maintenance pattern), a re-sync updates
	 * the same Shopware product instead of creating a duplicate - no need for a
	 * find-by-reference lookup call like PrestaShop needed.
	 *
	 * @param string $ref
	 * @return string
	 */
	public function productUuid($ref)
	{
		return md5('auvoshopware-product-'.$ref);
	}

	/**
	 * Build the Sync API payload for one product. Minimal required fields for
	 * Product.upsert: id, productNumber, name, stock, taxId, price. "visibilities" makes the
	 * product visible/purchasable in the configured sales channel (AUVOSHOPWARE_SW_SALES_CHANNEL_ID) -
	 * without it, a product exists in Shopware but never shows up in the shop.
	 *
	 * @param Product $product
	 * @return array<string, mixed>
	 */
	public function buildProductPayload(Product $product)
	{
		$payload = array(
			'id' => $this->productUuid($product->ref),
			'productNumber' => $product->ref,
			'name' => $product->label,
			'stock' => (int) $product->stock_reel,
			'taxId' => getDolGlobalString('AUVOSHOPWARE_SW_TAX_ID'),
			'price' => array(
				array(
					'currencyId' => self::DEFAULT_CURRENCY_ID,
					'gross' => (float) $product->price_ttc,
					'net' => (float) $product->price,
					'linked' => false,
				),
			),
			'active' => $product->status ? true : false,
		);

		$salesChannelId = getDolGlobalString('AUVOSHOPWARE_SW_SALES_CHANNEL_ID');
		if (!empty($salesChannelId)) {
			$payload['visibilities'] = array(
				array(
					'salesChannelId' => $salesChannelId,
					'visibility' => 30, // Shopware ProductVisibilityDefinition::VISIBILITY_ALL
				),
			);
		}

		return $payload;
	}

	/**
	 * Build a hash of everything that would change what gets pushed to Shopware for a product,
	 * so unchanged products can be skipped on repeat syncAll() runs.
	 *
	 * @param Product $product
	 * @return string
	 */
	protected function computeContentHash(Product $product)
	{
		$data = implode('|', array(
			$product->ref,
			$product->label,
			(string) $product->price,
			(string) $product->price_ttc,
			(string) getDolGlobalString('AUVOSHOPWARE_SW_TAX_ID'),
			(string) getDolGlobalString('AUVOSHOPWARE_SW_SALES_CHANNEL_ID'),
			$product->status ? '1' : '0',
			(string) $product->stock_reel,
		));

		return md5($data);
	}

	/**
	 * Sync a single product to Shopware.
	 *
	 * @param int  $productId
	 * @param bool $force Skip the content-hash unchanged check
	 * @return array{status: string, sw_product_id?: string, error?: string}
	 */
	public function syncOne($productId, $force = false)
	{
		$product = new Product($this->db);
		if ($product->fetch($productId) <= 0) {
			return array('status' => 'error', 'error' => 'Product not found: '.$productId);
		}

		$swProductId = $this->productUuid($product->ref);
		$contentHash = $this->computeContentHash($product);

		if (!$force) {
			$map = $this->getMapRow($product->id);
			if ($map !== null && $map['last_sync_status'] === 'ok' && $map['content_hash'] === $contentHash) {
				return array('status' => 'skipped', 'sw_product_id' => $swProductId);
			}
		}

		$result = $this->client->sync(array(
			'upsert-product' => array(
				'entity' => 'product',
				'action' => 'upsert',
				'payload' => array($this->buildProductPayload($product)),
			),
		));

		if ($result === false) {
			$this->saveMapRow($product->id, $swProductId, 'error', $this->client->error);
			return array('status' => 'error', 'sw_product_id' => $swProductId, 'error' => $this->client->error);
		}

		$this->saveMapRow($product->id, $swProductId, 'ok', null, $contentHash);

		return array('status' => 'ok', 'sw_product_id' => $swProductId);
	}

	/**
	 * Sync every product currently in scope (configured category + subcategories). Products
	 * unchanged since their last successful sync are skipped (see syncOne()/computeContentHash()).
	 *
	 * First cut: one Sync API call per product, same as syncOne(). True batching (multiple
	 * products in a single Sync API request, per PLAN.md's "bulk-first" principle) is a
	 * follow-up once this per-product shape is confirmed working live.
	 *
	 * @return array{ok:int, error:int, skipped:int, details:array<int,array>}
	 */
	public function syncAll()
	{
		$summary = array('ok' => 0, 'error' => 0, 'skipped' => 0, 'details' => array());

		foreach ($this->getSyncableProductIds() as $productId) {
			$result = $this->syncOne($productId);
			$bucket = in_array($result['status'], array('ok', 'skipped'), true) ? $result['status'] : 'error';
			$summary[$bucket]++;
			$summary['details'][$productId] = $result;
		}

		return $summary;
	}

	/**
	 * @param int $productId
	 * @return array{sw_product_id: ?string, content_hash: ?string, last_sync_date: ?string, last_sync_status: ?string, last_sync_error: ?string}|null
	 */
	public function getMapRow($productId)
	{
		global $conf;

		$sql = 'SELECT sw_product_id, content_hash, last_sync_date, last_sync_status, last_sync_error';
		$sql .= ' FROM '.$this->db->prefix().'auvoshopware_product_map';
		$sql .= ' WHERE fk_product = '.((int) $productId);
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array(
				'sw_product_id' => $obj->sw_product_id,
				'content_hash' => $obj->content_hash,
				'last_sync_date' => $obj->last_sync_date,
				'last_sync_status' => $obj->last_sync_status,
				'last_sync_error' => $obj->last_sync_error,
			);
		}

		return null;
	}

	/**
	 * Bulk variant of getMapRow(), for the dashboard's product list.
	 *
	 * @param int[] $productIds
	 * @return array<int, array{sw_product_id: ?string, content_hash: ?string, last_sync_date: ?string, last_sync_status: ?string, last_sync_error: ?string}>
	 */
	public function getMapRows(array $productIds)
	{
		global $conf;

		if (empty($productIds)) {
			return array();
		}

		$sql = 'SELECT fk_product, sw_product_id, content_hash, last_sync_date, last_sync_status, last_sync_error';
		$sql .= ' FROM '.$this->db->prefix().'auvoshopware_product_map';
		$sql .= ' WHERE fk_product IN ('.implode(',', array_map('intval', $productIds)).')';
		$sql .= ' AND entity = '.((int) $conf->entity);

		$rows = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[(int) $obj->fk_product] = array(
					'sw_product_id' => $obj->sw_product_id,
					'content_hash' => $obj->content_hash,
					'last_sync_date' => $obj->last_sync_date,
					'last_sync_status' => $obj->last_sync_status,
					'last_sync_error' => $obj->last_sync_error,
				);
			}
		}

		return $rows;
	}

	/**
	 * @param int         $productId
	 * @param string|null $swProductId
	 * @param string      $status      'ok' or 'error'
	 * @param string|null $error
	 * @param string|null $contentHash Only updated when provided (kept as-is on error, so the
	 *                                 next syncAll() run retries instead of thinking it's unchanged)
	 * @return void
	 */
	protected function saveMapRow($productId, $swProductId, $status, $error, $contentHash = null)
	{
		global $conf;

		$existing = $this->getMapRow($productId);

		$now = dol_now();
		$db = $this->db;

		if ($existing === null) {
			$sql = 'INSERT INTO '.$db->prefix().'auvoshopware_product_map';
			$sql .= ' (fk_product, sw_product_id, content_hash, last_sync_date, last_sync_status, last_sync_error, entity)';
			$sql .= ' VALUES (';
			$sql .= ((int) $productId).',';
			$sql .= ($swProductId !== null ? "'".$db->escape($swProductId)."'" : 'NULL').',';
			$sql .= ($contentHash !== null ? "'".$db->escape($contentHash)."'" : 'NULL').',';
			$sql .= "'".$db->idate($now)."',";
			$sql .= "'".$db->escape($status)."',";
			$sql .= ($error !== null ? "'".$db->escape($error)."'" : 'NULL').',';
			$sql .= (int) $conf->entity;
			$sql .= ')';
		} else {
			$sql = 'UPDATE '.$db->prefix().'auvoshopware_product_map SET';
			$sql .= ' sw_product_id = '.($swProductId !== null ? "'".$db->escape($swProductId)."'" : 'NULL').',';
			if ($contentHash !== null) {
				$sql .= ' content_hash = \''.$db->escape($contentHash).'\',';
			}
			$sql .= ' last_sync_date = \''.$db->idate($now).'\',';
			$sql .= ' last_sync_status = \''.$db->escape($status).'\',';
			$sql .= ' last_sync_error = '.($error !== null ? "'".$db->escape($error)."'" : 'NULL');
			$sql .= ' WHERE fk_product = '.((int) $productId);
			$sql .= ' AND entity = '.((int) $conf->entity);
		}

		$db->query($sql);
	}
}
