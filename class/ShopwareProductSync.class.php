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
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttribute.class.php';
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductAttributeValue.class.php';
require_once DOL_DOCUMENT_ROOT.'/variants/class/ProductCombination2ValuePair.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * \file    auvoshopware/class/ShopwareProductSync.class.php
 * \ingroup auvoshopware
 * \brief   Push Dolibarr products (scoped by category) to Shopware via the Sync API.
 *
 * First cut (PLAN.md phase 4, "start small"): plain products + images + variants, no category
 * mirroring yet (a follow-up step once this shape is confirmed working live).
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
	 * dolCategoryId => dolParentCategoryId for the configured root category
	 * (AUVOSHOPWARE_CATEGORY_ID) and all its descendants, in parent-before-child order (root's
	 * own "parent" is 0). Shared by getSyncableProductCategoryMap() (which products are in
	 * scope) and buildCategoryOperations() (which Shopware categories to mirror).
	 *
	 * @return array<int, int>
	 */
	protected function getInScopeCategoryParents()
	{
		$rootId = getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');
		if (empty($rootId)) {
			return array();
		}

		$tmpcateg = new Categorie($this->db);
		$fullArbo = $tmpcateg->get_full_arbo('product');

		// Walk the full category tree to collect the root category and all its descendants.
		$categParents = array($rootId => 0);
		if (is_array($fullArbo)) {
			$changed = true;
			while ($changed) {
				$changed = false;
				foreach ($fullArbo as $categ) {
					$id = is_array($categ) ? ($categ['id'] ?? null) : ($categ->id ?? null);
					$parent = is_array($categ) ? ($categ['id_mere'] ?? $categ['fk_parent'] ?? 0) : ($categ->id_mere ?? $categ->fk_parent ?? 0);
					if ($id !== null && isset($categParents[$parent]) && !isset($categParents[$id])) {
						$categParents[$id] = (int) $parent;
						$changed = true;
					}
				}
			}
		}

		return $categParents;
	}

	/**
	 * Map Dolibarr products that belong to the configured root category
	 * (AUVOSHOPWARE_CATEGORY_ID) or one of its subcategories, to the specific in-scope category
	 * id(s) each one is directly assigned to (a product can be assigned to more than one).
	 * Excludes variant children.
	 *
	 * @return array<int,int[]> Dolibarr product id => list of Dolibarr category ids
	 */
	public function getSyncableProductCategoryMap()
	{
		$productCategories = array();
		foreach (array_keys($this->getInScopeCategoryParents()) as $categId) {
			$categ = new Categorie($this->db);
			if ($categ->fetch($categId) > 0) {
				$objs = $categ->getObjectsInCateg('product', 1);
				if (is_array($objs)) {
					foreach ($objs as $id) {
						$id = (int) $id;
						if (!$this->isVariantChild($id)) {
							$productCategories[$id][] = (int) $categId;
						}
					}
				}
			}
		}

		return $productCategories;
	}

	/**
	 * List ids of Dolibarr products that belong to the configured root category
	 * (AUVOSHOPWARE_CATEGORY_ID) or one of its subcategories, excluding variant children.
	 *
	 * @return int[]
	 */
	public function getSyncableProductIds()
	{
		return array_keys($this->getSyncableProductCategoryMap());
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
	 * Deterministic id for a Shopware category mirroring a Dolibarr category, keyed by the
	 * Dolibarr category id. Same reasoning as productUuid() - no mapping table needed, unlike
	 * auvopresta's PrestaShop category mirroring which had to look up/store serial ids.
	 *
	 * @param int $dolCategoryId
	 * @return string
	 */
	protected function categoryUuid($dolCategoryId)
	{
		return md5('auvoshopware-category-'.$dolCategoryId);
	}

	/**
	 * Shopware category id a Dolibarr category maps to. The configured root category
	 * (AUVOSHOPWARE_CATEGORY_ID) itself is not mirrored - it maps directly to the configured
	 * Shopware parent category (AUVOSHOPWARE_SW_PARENT_CATEGORY_ID); only its descendants get
	 * mirrored underneath that parent. Same design as auvopresta's getOrCreatePsCategoryId().
	 *
	 * @param int $dolCategoryId
	 * @return string
	 */
	protected function shopwareCategoryId($dolCategoryId)
	{
		$rootId = getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');
		if ((int) $dolCategoryId === (int) $rootId) {
			return getDolGlobalString('AUVOSHOPWARE_SW_PARENT_CATEGORY_ID');
		}

		return $this->categoryUuid($dolCategoryId);
	}

	/**
	 * Build upsert payloads for every in-scope Dolibarr category that needs mirroring into
	 * Shopware (everything except the root itself, see shopwareCategoryId()), in
	 * parent-before-child order so one Sync API request can create the whole chain in one go -
	 * same reasoning as pushVariants()'s property_group-before-property_group_option ordering.
	 * Empty if AUVOSHOPWARE_SW_PARENT_CATEGORY_ID isn't configured (nothing to attach the
	 * mirrored tree to).
	 *
	 * @return array<int, array{id: string, name: string, parentId: string}>
	 */
	protected function buildCategoryOperations()
	{
		if (empty(getDolGlobalString('AUVOSHOPWARE_SW_PARENT_CATEGORY_ID'))) {
			return array();
		}

		$rootId = getDolGlobalInt('AUVOSHOPWARE_CATEGORY_ID');
		$operations = array();

		foreach ($this->getInScopeCategoryParents() as $categId => $parentId) {
			if ((int) $categId === (int) $rootId) {
				continue;
			}

			$categ = new Categorie($this->db);
			if ($categ->fetch($categId) <= 0) {
				continue;
			}

			$operations[] = array(
				'id' => $this->categoryUuid($categId),
				'name' => $categ->label,
				'parentId' => $this->shopwareCategoryId($parentId),
			);
		}

		return $operations;
	}

	/**
	 * Deterministic id for the product's product_visibility row (product+sales channel). Nested
	 * to-many associations in a Sync API upsert need their own stable id too, same as the
	 * product itself - otherwise Shopware inserts a new row every sync instead of updating the
	 * existing one, and the second sync onwards hits product_visibility's
	 * (product_id, sales_channel_id) unique constraint (found live: HTTP 500, error code 1062).
	 *
	 * @param string $ref
	 * @param string $salesChannelId
	 * @return string
	 */
	protected function visibilityUuid($ref, $salesChannelId)
	{
		return md5('auvoshopware-visibility-'.$ref.'-'.$salesChannelId);
	}

	/**
	 * Deterministic id for a product image's underlying media entity (the file itself).
	 *
	 * @param string $ref
	 * @param string $filename
	 * @return string
	 */
	protected function mediaUuid($ref, $filename)
	{
		return md5('auvoshopware-media-'.$ref.'-'.$filename);
	}

	/**
	 * Deterministic id for a product image's product_media join row (product+media+position).
	 * Same "nested to-many association needs its own stable id too" rule as visibilityUuid().
	 *
	 * @param string $ref
	 * @param string $filename
	 * @return string
	 */
	protected function productMediaUuid($ref, $filename)
	{
		return md5('auvoshopware-product-media-'.$ref.'-'.$filename);
	}

	/**
	 * Deterministic id for a Shopware product_manufacturer entity, keyed by name (manufacturers
	 * aren't Dolibarr objects with their own id here, just a free-text extrafield value - see
	 * getManufacturerName()).
	 *
	 * @param string $name
	 * @return string
	 */
	protected function manufacturerUuid($name)
	{
		return md5('auvoshopware-manufacturer-'.$name);
	}

	/**
	 * Manufacturer name from the product's "hersteller" extrafield, or null if empty/not set.
	 *
	 * @param Product $product
	 * @return string|null
	 */
	protected function getManufacturerName(Product $product)
	{
		if (!is_array($product->array_options) || $product->array_options === array()) {
			$product->fetch_optionals();
		}

		$name = trim((string) ($product->array_options['options_hersteller'] ?? ''));

		return $name !== '' ? $name : null;
	}

	/**
	 * Ensure a Shopware product_manufacturer entity exists for the given name, and return its id.
	 *
	 * @param string $name
	 * @return string|false Manufacturer id, or false on error (see $this->error)
	 */
	protected function ensureManufacturer($name)
	{
		$manufacturerId = $this->manufacturerUuid($name);

		$result = $this->client->sync(array(
			'upsert-manufacturer' => array(
				'entity' => 'product_manufacturer',
				'action' => 'upsert',
				'payload' => array(array('id' => $manufacturerId, 'name' => $name)),
			),
		));

		if ($result === false) {
			$this->error = $this->client->error;
			return false;
		}

		return $manufacturerId;
	}

	/**
	 * Deterministic id for a Shopware property_group (e.g. "Farbe"), keyed by the Dolibarr
	 * attribute id. Unlike PrestaShop's serial attribute-group ids (which auvopresta had to look
	 * up and cache in a mapping table), Shopware lets us pick our own id, so no mapping table is
	 * needed here - same reasoning as productUuid().
	 *
	 * @param int $fkAttribute
	 * @return string
	 */
	protected function propertyGroupUuid($fkAttribute)
	{
		return md5('auvoshopware-property-group-'.$fkAttribute);
	}

	/**
	 * Deterministic id for a Shopware property_group_option (e.g. "Rot"), keyed by the Dolibarr
	 * attribute value id.
	 *
	 * @param int $fkAttributeValue
	 * @return string
	 */
	protected function propertyGroupOptionUuid($fkAttributeValue)
	{
		return md5('auvoshopware-property-group-option-'.$fkAttributeValue);
	}

	/**
	 * Deterministic id for one entry of a parent product's product_configurator_setting
	 * association (which options define this product's variant axes). Same "nested to-many
	 * association needs its own stable id too" rule as visibilityUuid()/productMediaUuid().
	 *
	 * @param string $parentRef
	 * @param int    $fkAttributeValue
	 * @return string
	 */
	protected function configuratorSettingUuid($parentRef, $fkAttributeValue)
	{
		return md5('auvoshopware-configurator-setting-'.$parentRef.'-'.$fkAttributeValue);
	}

	/**
	 * Dolibarr combinations (color/size/... variants) of a product, if it's a variant parent.
	 *
	 * @param Product $product
	 * @return ProductCombination[]
	 */
	protected function getParentCombinations(Product $product)
	{
		$combination = new ProductCombination($this->db);
		$rows = $combination->fetchAllByFkProductParent($product->id);

		return is_array($rows) ? $rows : array();
	}

	/**
	 * Mirror a variant parent's Dolibarr combinations (color/size/...) into Shopware: a
	 * property_group + property_group_option for each used attribute/value, a
	 * product_configurator_setting entry per used option on the parent, and each combination's
	 * child product as its own Shopware "product" entity (parentId pointing at the parent,
	 * "options" pointing at its specific value combination). No-op if the product has no
	 * combinations.
	 *
	 * The child's price is derived from the combination's price impact (variation_price /
	 * variation_price_percentage) applied to the parent's price, not read from the child
	 * product's own ->price - mirrors auvopresta's PrestaShopProductSync::pushVariants(), which
	 * found Dolibarr doesn't reliably keep the child's own price field in sync with the impact.
	 * The gross/net ratio of the parent's own price is reused to derive the child's gross price
	 * from its net price (assumes a flat VAT rate, same assumption already made for the parent).
	 *
	 * @param Product $parent
	 * @return array{propertyGroups: array<int, array<string, mixed>>, propertyGroupOptions: array<int, array<string, mixed>>, variantProducts: array<int, array<string, mixed>>, configuratorSettings: array<int, array<string, mixed>>, error: ?string}
	 */
	protected function pushVariants(Product $parent)
	{
		$empty = array('propertyGroups' => array(), 'propertyGroupOptions' => array(), 'variantProducts' => array(), 'configuratorSettings' => array(), 'error' => null);

		$combinations = $this->getParentCombinations($parent);
		if (empty($combinations)) {
			return $empty;
		}

		$propertyGroups = array();
		$propertyGroupOptions = array();
		$configuratorSettings = array();
		$variantProducts = array();
		$errors = array();
		// fkAttribute => (fkAttributeValue => display position), filled in lazily below.
		$attributeValuePositions = array();

		foreach ($combinations as $combination) {
			$pairs = new ProductCombination2ValuePair($this->db);
			$valuePairs = $pairs->fetchByFkCombination($combination->id);
			if (!is_array($valuePairs)) {
				$valuePairs = array();
			}

			$child = new Product($this->db);
			if ($child->fetch($combination->fk_product_child) <= 0) {
				$errors[] = 'Combination '.$combination->id.': child product '.$combination->fk_product_child.' not found';
				continue;
			}

			$optionIds = array();
			$pairError = null;
			foreach ($valuePairs as $pair) {
				$attribute = new ProductAttribute($this->db);
				if ($attribute->fetch($pair->fk_prod_attr) <= 0) {
					$pairError = 'Dolibarr attribute not found: '.$pair->fk_prod_attr;
					break;
				}
				$attributeValue = new ProductAttributeValue($this->db);
				if ($attributeValue->fetch($pair->fk_prod_attr_val) <= 0) {
					$pairError = 'Dolibarr attribute value not found: '.$pair->fk_prod_attr_val;
					break;
				}

				// Without an explicit position, Shopware falls back to alphabetical display order
				// (found live: sizes came out as "3XL, 4XL, 128, 140, ..., L, M, S, XL, XXL"
				// instead of a size progression). Assign each value's position from Dolibarr's
				// own attribute-value order instead of the order combinations happen to
				// reference them in - same fix auvopresta needed for PrestaShop option values.
				if (!isset($attributeValuePositions[$pair->fk_prod_attr])) {
					$positions = array();
					$attributeValueObj = new ProductAttributeValue($this->db);
					$orderedValues = $attributeValueObj->fetchAllByProductAttribute($pair->fk_prod_attr, true);
					if (is_array($orderedValues)) {
						$position = 0;
						foreach ($orderedValues as $orderedValue) {
							$positions[$orderedValue->id] = $position;
							$position++;
						}
					}
					$attributeValuePositions[$pair->fk_prod_attr] = $positions;
				}

				$groupId = $this->propertyGroupUuid($pair->fk_prod_attr);
				$optionId = $this->propertyGroupOptionUuid($pair->fk_prod_attr_val);

				// sortingType defaults to alphabetical display regardless of each option's own
				// "position" field (found live: setting position alone had no visible effect) -
				// must be set explicitly to make position the thing that actually orders options.
				$propertyGroups[$groupId] = array('id' => $groupId, 'name' => $attribute->label, 'sortingType' => 'position');
				$propertyGroupOptions[$optionId] = array(
					'id' => $optionId,
					'groupId' => $groupId,
					'name' => $attributeValue->value,
					'position' => $attributeValuePositions[$pair->fk_prod_attr][$pair->fk_prod_attr_val] ?? 0,
				);
				$configuratorSettings[$optionId] = array(
					'id' => $this->configuratorSettingUuid($parent->ref, $pair->fk_prod_attr_val),
					'optionId' => $optionId,
				);

				$optionIds[] = $optionId;
			}
			if ($pairError !== null) {
				$errors[] = 'Combination '.$combination->id.': '.$pairError;
				continue;
			}

			$priceImpact = (float) $combination->variation_price;
			if (!empty($combination->variation_price_percentage)) {
				$priceImpact = ((float) $parent->price) * ($priceImpact / 100);
			}
			$vatRatio = ((float) $parent->price) > 0 ? ((float) $parent->price_ttc / (float) $parent->price) : 1.0;
			// round(): plain float arithmetic here produces binary-rounding artifacts like
			// 17.990000000000002 (found live) - Shopware stores/displays whatever we send.
			$childNet = round((float) $parent->price + $priceImpact, 2);
			$childGross = round($childNet * $vatRatio, 2);

			// Each variant child is its own Dolibarr Product with its own ref-named image
			// directory, so it gets the exact same upload treatment as the main product.
			$childMediaResult = $this->pushImages($child);
			$this->saveMapImages($child->id, $childMediaResult['images']);
			if (!empty($childMediaResult['error'])) {
				$errors[] = 'Combination '.$combination->id.' images: '.$childMediaResult['error'];
			}

			$variantPayload = array(
				'id' => $this->productUuid($child->ref),
				'productNumber' => $child->ref,
				'parentId' => $this->productUuid($parent->ref),
				'stock' => (int) $child->stock_reel,
				'taxId' => getDolGlobalString('AUVOSHOPWARE_SW_TAX_ID'),
				'price' => array(
					array(
						'currencyId' => self::DEFAULT_CURRENCY_ID,
						'gross' => $childGross,
						'net' => $childNet,
						'linked' => false,
					),
				),
				'options' => array_map(function ($id) {
					return array('id' => $id);
				}, $optionIds),
			);

			if (!empty($childMediaResult['associations'])) {
				$variantPayload['media'] = $childMediaResult['associations'];
				if (!empty($childMediaResult['coverId'])) {
					$variantPayload['coverId'] = $childMediaResult['coverId'];
				}
			}

			$variantProducts[] = $variantPayload;
		}

		return array(
			'propertyGroups' => array_values($propertyGroups),
			'propertyGroupOptions' => array_values($propertyGroupOptions),
			'variantProducts' => $variantProducts,
			'configuratorSettings' => array_values($configuratorSettings),
			'error' => empty($errors) ? null : implode('; ', $errors),
		);
	}

	/**
	 * Cheapest currently known supplier purchase price for this product (net unit price, after
	 * any line discount), same value Dolibarr itself calls "Bester Einkaufspreis" on
	 * product/price_suppliers.php. Uses Dolibarr core ProductFournisseur::find_min_price_product_fournisseur(),
	 * verified against this shop's actual source (fourn/class/fournisseur.product.class.php):
	 * returns the matching product_fourn_price_id (>0) on success, 0 if no supplier price is on
	 * file, -1 on a SQL error; the price itself ends up in $this->fourn_unitprice_with_discount,
	 * not a "fourn_pu" property (that name doesn't exist on this class).
	 *
	 * @param Product $product
	 * @return float|null
	 */
	protected function getSupplierBuyPrice(Product $product)
	{
		$file = DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
		if (!file_exists($file)) {
			return null;
		}
		require_once $file;

		if (!class_exists('ProductFournisseur')) {
			return null;
		}

		$productFourn = new ProductFournisseur($this->db);
		if (!method_exists($productFourn, 'find_min_price_product_fournisseur')) {
			return null;
		}

		$result = $productFourn->find_min_price_product_fournisseur($product->id);
		if ($result <= 0) {
			return null;
		}

		return (float) $productFourn->fourn_unitprice_with_discount;
	}

	/**
	 * Absolute path to a Dolibarr product's document directory (same for the main product and
	 * any variant child - each has its own ref-named directory).
	 *
	 * @param Product $product
	 * @return string
	 */
	protected function getProductDir(Product $product)
	{
		global $conf;

		$entity = $product->entity ?: $conf->entity;

		return $conf->product->multidir_output[$entity].'/'.dol_sanitizeFileName($product->ref);
	}

	/**
	 * Image files (jpg/jpeg/png/gif/webp/bmp) in a product's document directory, or an empty
	 * array if there are none. Shared by pushImages() (parent and, since variant images, each
	 * variant child too) and computeContentHash().
	 *
	 * @param Product $product
	 * @return array<int, array<string, mixed>>
	 */
	protected function getProductImageFiles(Product $product)
	{
		$files = dol_dir_list($this->getProductDir($product), 'files', 0, '\.(jpg|jpeg|png|gif|webp|bmp)$');

		return is_array($files) ? $files : array();
	}

	/**
	 * Upload any product image files not yet known to Shopware (per the local sw_images map),
	 * and return the "media" association list + cover id to merge into the product payload.
	 * Works the same for the main product and for a variant child - each is just a Dolibarr
	 * Product with its own ref-named image directory and its own row in the mapping table.
	 *
	 * Two Admin API calls per new image, both outside the main product Sync API call: (1)
	 * sync()-upsert an empty 'media' entity with a deterministic id, (2) upload the raw file
	 * bytes into it (ShopwareClient::uploadMediaFile()). Both must happen before the product
	 * upsert that references the media id as a foreign key.
	 *
	 * @param Product $product
	 * @return array{associations: array<int, array{id: string, mediaId: string, position: int}>, coverId: ?string, images: array<string, string>, error: ?string}
	 */
	protected function pushImages(Product $product)
	{
		$productDir = $this->getProductDir($product);
		$files = $this->getProductImageFiles($product);
		if (empty($files)) {
			return array('associations' => array(), 'coverId' => null, 'images' => array(), 'error' => null);
		}
		sort($files);

		$map = $this->getMapRow($product->id);
		$existingImages = array();
		if (!empty($map['sw_images'])) {
			$decoded = json_decode($map['sw_images'], true);
			if (is_array($decoded)) {
				$existingImages = $decoded;
			}
		}

		$errors = array();
		foreach ($files as $file) {
			$filename = $file['name'] ?? null;
			if (empty($filename) || isset($existingImages[$filename])) {
				continue;
			}

			$mediaId = $this->mediaUuid($product->ref, $filename);

			$createResult = $this->client->sync(array(
				'upsert-media' => array(
					'entity' => 'media',
					'action' => 'upsert',
					'payload' => array(array('id' => $mediaId)),
				),
			));
			if ($createResult === false) {
				$errors[] = $filename.': '.$this->client->error;
				continue;
			}

			$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
			// Shopware's media file name is unique across the whole media library, not just per
			// product - prefixing with the ref avoids CONTENT__MEDIA_DUPLICATED_FILE_NAME when
			// the same image file name is reused across different products/variants (found live).
			$swFileName = dol_sanitizeFileName($product->ref).'_'.pathinfo($filename, PATHINFO_FILENAME);
			if ($this->client->uploadMediaFile($mediaId, $productDir.'/'.$filename, $swFileName, $extension)) {
				$existingImages[$filename] = $mediaId;
			} else {
				$errors[] = $filename.': '.$this->client->error;
			}
		}

		$associations = array();
		$coverId = null;
		$position = 0;
		foreach ($existingImages as $filename => $mediaId) {
			$productMediaId = $this->productMediaUuid($product->ref, $filename);
			$associations[] = array(
				'id' => $productMediaId,
				'mediaId' => $mediaId,
				'position' => $position,
			);
			if ($position === 0) {
				$coverId = $productMediaId;
			}
			$position++;
		}

		return array(
			'associations' => $associations,
			'coverId' => $coverId,
			'images' => $existingImages,
			'error' => empty($errors) ? null : implode('; ', $errors),
		);
	}

	/**
	 * Build the Sync API payload for one product. Minimal required fields for
	 * Product.upsert: id, productNumber, name, stock, taxId, price. "visibilities" makes the
	 * product visible/purchasable in the configured sales channel (AUVOSHOPWARE_SW_SALES_CHANNEL_ID) -
	 * without it, a product exists in Shopware but never shows up in the shop. "media"/"coverId"
	 * come from pushImages() (already-uploaded images only).
	 *
	 * @param Product     $product
	 * @param array{associations: array<int, array{id: string, mediaId: string, position: int}>, coverId: ?string} $mediaResult
	 * @param string|null $manufacturerId Already-resolved Shopware product_manufacturer id (see ensureManufacturer())
	 * @param int[]       $dolCategoryIds In-scope Dolibarr category ids this product is directly assigned to
	 * @return array<string, mixed>
	 */
	public function buildProductPayload(Product $product, array $mediaResult = array(), $manufacturerId = null, array $dolCategoryIds = array())
	{
		$payload = array(
			'id' => $this->productUuid($product->ref),
			'productNumber' => $product->ref,
			'name' => $product->label,
			'description' => (string) $product->description,
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

		if (!empty($manufacturerId)) {
			$payload['manufacturerId'] = $manufacturerId;
		}

		$buyPrice = $this->getSupplierBuyPrice($product);
		if ($buyPrice !== null) {
			$payload['purchasePrices'] = array(
				array(
					'currencyId' => self::DEFAULT_CURRENCY_ID,
					'gross' => $buyPrice,
					'net' => $buyPrice,
					'linked' => false,
				),
			);
		}

		$salesChannelId = getDolGlobalString('AUVOSHOPWARE_SW_SALES_CHANNEL_ID');
		if (!empty($salesChannelId)) {
			$payload['visibilities'] = array(
				array(
					'id' => $this->visibilityUuid($product->ref, $salesChannelId),
					'salesChannelId' => $salesChannelId,
					'visibility' => 30, // Shopware ProductVisibilityDefinition::VISIBILITY_ALL
				),
			);
		}

		if (!empty($mediaResult['associations'])) {
			$payload['media'] = $mediaResult['associations'];
			if (!empty($mediaResult['coverId'])) {
				$payload['coverId'] = $mediaResult['coverId'];
			}
		}

		if (!empty($dolCategoryIds) && !empty(getDolGlobalString('AUVOSHOPWARE_SW_PARENT_CATEGORY_ID'))) {
			$payload['categories'] = array_map(function ($dolCategoryId) {
				return array('id' => $this->shopwareCategoryId($dolCategoryId));
			}, $dolCategoryIds);
		}

		return $payload;
	}

	/**
	 * Build a hash of everything that would change what gets pushed to Shopware for a product,
	 * so unchanged products can be skipped on repeat syncAll() runs.
	 *
	 * @param Product $product
	 * @param int[]   $dolCategoryIds
	 * @return string
	 */
	protected function computeContentHash(Product $product, array $dolCategoryIds = array())
	{
		$imageSignature = array();
		foreach ($this->getProductImageFiles($product) as $file) {
			$imageSignature[] = ($file['name'] ?? '').':'.($file['size'] ?? 0);
		}
		sort($imageSignature);

		$variantSignature = array();
		foreach ($this->getParentCombinations($product) as $combination) {
			$child = new Product($this->db);
			if ($child->fetch($combination->fk_product_child) > 0) {
				$childImageSignature = array();
				foreach ($this->getProductImageFiles($child) as $file) {
					$childImageSignature[] = ($file['name'] ?? '').':'.($file['size'] ?? 0);
				}
				sort($childImageSignature);

				$variantSignature[] = $child->ref.':'.$combination->variation_price.':'
					.($combination->variation_price_percentage ? '1' : '0').':'
					.$combination->variation_weight.':'.$child->stock_reel.':'
					.implode(',', $childImageSignature);
			}
		}
		sort($variantSignature);

		sort($dolCategoryIds);

		$data = implode('|', array(
			$product->ref,
			$product->label,
			(string) $product->description,
			(string) $product->price,
			(string) $product->price_ttc,
			(string) getDolGlobalString('AUVOSHOPWARE_SW_TAX_ID'),
			(string) getDolGlobalString('AUVOSHOPWARE_SW_SALES_CHANNEL_ID'),
			$product->status ? '1' : '0',
			(string) $product->stock_reel,
			implode(',', $imageSignature),
			(string) $this->getManufacturerName($product),
			(string) $this->getSupplierBuyPrice($product),
			implode(',', $variantSignature),
			implode(',', $dolCategoryIds),
		));

		return md5($data);
	}

	/**
	 * Sync a single product to Shopware.
	 *
	 * @param int        $productId
	 * @param int[]|null $dolCategoryIds In-scope Dolibarr category ids this product is directly
	 *                                   assigned to; computed automatically if not given (pass
	 *                                   it when calling this in a loop over many products to
	 *                                   avoid recomputing the whole category map every time)
	 * @param bool       $force          Skip the content-hash unchanged check
	 * @return array{status: string, sw_product_id?: string, error?: string}
	 */
	public function syncOne($productId, ?array $dolCategoryIds = null, $force = false)
	{
		$product = new Product($this->db);
		if ($product->fetch($productId) <= 0) {
			return array('status' => 'error', 'error' => 'Product not found: '.$productId);
		}

		if ($dolCategoryIds === null) {
			$categoryMap = $this->getSyncableProductCategoryMap();
			$dolCategoryIds = $categoryMap[$productId] ?? array();
		}

		$swProductId = $this->productUuid($product->ref);
		$contentHash = $this->computeContentHash($product, $dolCategoryIds);

		if (!$force) {
			$map = $this->getMapRow($product->id);
			if ($map !== null && $map['last_sync_status'] === 'ok' && $map['content_hash'] === $contentHash) {
				return array('status' => 'skipped', 'sw_product_id' => $swProductId);
			}
		}

		$mediaResult = $this->pushImages($product);
		// Persist whatever uploaded successfully even if the product upsert below fails, so a
		// retry doesn't re-upload files that are already in Shopware.
		$this->saveMapImages($product->id, $mediaResult['images']);

		$manufacturerId = null;
		$manufacturerName = $this->getManufacturerName($product);
		if ($manufacturerName !== null) {
			$manufacturerId = $this->ensureManufacturer($manufacturerName);
			if ($manufacturerId === false) {
				$this->saveMapRow($product->id, $swProductId, 'error', 'Manufacturer: '.$this->error);
				return array('status' => 'error', 'sw_product_id' => $swProductId, 'error' => 'Manufacturer: '.$this->error);
			}
		}

		$variantResult = $this->pushVariants($product);
		$categoryOperations = $this->buildCategoryOperations();

		$parentPayload = $this->buildProductPayload($product, $mediaResult, $manufacturerId, $dolCategoryIds);
		if (!empty($variantResult['configuratorSettings'])) {
			$parentPayload['configuratorSettings'] = $variantResult['configuratorSettings'];
		}

		// Categories and property groups/options must be upserted before the product(s) that
		// reference them as foreign keys - the Sync API processes operations of one request in
		// the given order, so everything goes out together as a single request rather than one
		// call per step.
		$operations = array();
		if (!empty($categoryOperations)) {
			$operations['upsert-categories'] = array(
				'entity' => 'category',
				'action' => 'upsert',
				'payload' => $categoryOperations,
			);
		}
		if (!empty($variantResult['propertyGroups'])) {
			$operations['upsert-property-groups'] = array(
				'entity' => 'property_group',
				'action' => 'upsert',
				'payload' => $variantResult['propertyGroups'],
			);
		}
		if (!empty($variantResult['propertyGroupOptions'])) {
			$operations['upsert-property-group-options'] = array(
				'entity' => 'property_group_option',
				'action' => 'upsert',
				'payload' => $variantResult['propertyGroupOptions'],
			);
		}
		$operations['upsert-product'] = array(
			'entity' => 'product',
			'action' => 'upsert',
			'payload' => array_merge(array($parentPayload), $variantResult['variantProducts']),
		);

		$result = $this->client->sync($operations);

		if ($result === false) {
			$this->saveMapRow($product->id, $swProductId, 'error', $this->client->error);
			return array('status' => 'error', 'sw_product_id' => $swProductId, 'error' => $this->client->error);
		}

		if (!empty($mediaResult['error'])) {
			$this->saveMapRow($product->id, $swProductId, 'error', 'Images: '.$mediaResult['error'], $contentHash);
			return array('status' => 'error', 'sw_product_id' => $swProductId, 'error' => 'Images: '.$mediaResult['error']);
		}

		if (!empty($variantResult['error'])) {
			$this->saveMapRow($product->id, $swProductId, 'error', 'Variants: '.$variantResult['error'], $contentHash);
			return array('status' => 'error', 'sw_product_id' => $swProductId, 'error' => 'Variants: '.$variantResult['error']);
		}

		$this->saveMapRow($product->id, $swProductId, 'ok', null, $contentHash);

		return array('status' => 'ok', 'sw_product_id' => $swProductId);
	}

	/**
	 * Sync every product currently in scope (configured category + subcategories). Products
	 * unchanged since their last successful sync are skipped (see syncOne()/computeContentHash()).
	 *
	 * One Sync API call per product (properties/categories/variants/media all bundled into that
	 * same request, see syncOne()). Batching multiple *products* together into one request too
	 * is a possible follow-up, not needed so far - even a 154-variant product syncs fast today.
	 *
	 * @return array{ok:int, error:int, skipped:int, details:array<int,array>}
	 */
	public function syncAll()
	{
		$summary = array('ok' => 0, 'error' => 0, 'skipped' => 0, 'details' => array());

		foreach ($this->getSyncableProductCategoryMap() as $productId => $dolCategoryIds) {
			$result = $this->syncOne($productId, $dolCategoryIds);
			$bucket = in_array($result['status'], array('ok', 'skipped'), true) ? $result['status'] : 'error';
			$summary[$bucket]++;
			$summary['details'][$productId] = $result;
		}

		return $summary;
	}

	/**
	 * @param int $productId
	 * @return array{sw_product_id: ?string, content_hash: ?string, sw_images: ?string, last_sync_date: ?string, last_sync_status: ?string, last_sync_error: ?string}|null
	 */
	public function getMapRow($productId)
	{
		global $conf;

		$sql = 'SELECT sw_product_id, content_hash, sw_images, last_sync_date, last_sync_status, last_sync_error';
		$sql .= ' FROM '.$this->db->prefix().'auvoshopware_product_map';
		$sql .= ' WHERE fk_product = '.((int) $productId);
		$sql .= ' AND entity = '.((int) $conf->entity);

		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array(
				'sw_product_id' => $obj->sw_product_id,
				'content_hash' => $obj->content_hash,
				'sw_images' => $obj->sw_images,
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
	 * @return array<int, array{sw_product_id: ?string, content_hash: ?string, sw_images: ?string, last_sync_date: ?string, last_sync_status: ?string, last_sync_error: ?string}>
	 */
	public function getMapRows(array $productIds)
	{
		global $conf;

		if (empty($productIds)) {
			return array();
		}

		$sql = 'SELECT fk_product, sw_product_id, content_hash, sw_images, last_sync_date, last_sync_status, last_sync_error';
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
					'sw_images' => $obj->sw_images,
					'last_sync_date' => $obj->last_sync_date,
					'last_sync_status' => $obj->last_sync_status,
					'last_sync_error' => $obj->last_sync_error,
				);
			}
		}

		return $rows;
	}

	/**
	 * Persist the filename => Shopware media id map for a product, so already-uploaded images
	 * are recognized and skipped on the next sync (see pushImages()).
	 *
	 * @param int                  $productId
	 * @param array<string,string> $images
	 * @return void
	 */
	protected function saveMapImages($productId, array $images)
	{
		global $conf;

		if ($this->getMapRow($productId) === null) {
			// Ensure a row exists before UPDATE-ing it (mirrors the insert branch of saveMapRow()).
			$this->saveMapRow($productId, null, 'pending', null);
		}

		$sql = 'UPDATE '.$this->db->prefix().'auvoshopware_product_map';
		$sql .= " SET sw_images = '".$this->db->escape(json_encode($images))."'";
		$sql .= ' WHERE fk_product = '.((int) $productId);
		$sql .= ' AND entity = '.((int) $conf->entity);

		$this->db->query($sql);
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
