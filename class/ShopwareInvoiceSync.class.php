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

require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * \file    auvoshopware/class/ShopwareInvoiceSync.class.php
 * \ingroup auvoshopware
 * \brief   Upload the real Dolibarr invoice PDF as an official, customer-visible Shopware order
 *          document (PLAN.md phase 7) - the module's original goal. PrestaShop had no clean way
 *          to attach an externally generated PDF as the shop's own invoice document, only a
 *          free-text note workaround; Shopware's "document" entity is just a plain, API-writable
 *          DAL entity (see DocumentDefinition, ApiAware on documentMediaFileId), so an uploaded
 *          media file can be linked in directly.
 */
class ShopwareInvoiceSync
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
	 * @var ShopwareClient
	 */
	protected $client;

	/**
	 * @param DoliDB        $db
	 * @param ShopwareClient $client
	 */
	public function __construct($db, ShopwareClient $client)
	{
		$this->db = $db;
		$this->client = $client;
	}

	/**
	 * Push every Dolibarr invoice linked to a Dolibarr sales order to its linked Shopware order,
	 * each as its own Shopware document (a Commande can have more than one Facture - e.g. split
	 * invoicing - so each becomes a separate document rather than picking just one).
	 *
	 * @param int $fkCommande
	 * @return array{status:string, count?:int, error?:string}
	 */
	public function pushInvoices($fkCommande)
	{
		$commande = new Commande($this->db);
		if ($commande->fetch($fkCommande) <= 0) {
			return array('status' => 'error', 'error' => 'Dolibarr order not found: '.$fkCommande);
		}

		$map = $this->getOrderMapByCommande($fkCommande);
		if ($map === null) {
			return array('status' => 'error', 'error' => 'No Shopware order linked to this Dolibarr order');
		}

		$commande->fetchObjectLinked();
		$factures = $commande->linkedObjects['facture'] ?? array();
		if (empty($factures)) {
			return array('status' => 'skipped');
		}

		$documentTypeId = $this->client->findDocumentTypeId('invoice');
		if ($documentTypeId === null) {
			return array('status' => 'error', 'error' => 'Shopware has no document type with technicalName "invoice": '.$this->client->error);
		}

		$count = 0;
		foreach ($factures as $facture) {
			$result = $this->pushOneInvoice($map['sw_order_id'], $facture, $documentTypeId);
			if ($result['status'] === 'error') {
				return $result;
			}
			$count++;
		}

		return array('status' => 'ok', 'count' => $count);
	}

	/**
	 * Generate the invoice's PDF, upload it as a Shopware media file, and link it in as a
	 * 'document' entity on the order - plus a best-effort write of the invoice reference into a
	 * custom field on the order itself (ensureOrderRefCustomField()), for staff browsing the
	 * order in Shopware admin. The custom field write is not allowed to fail the whole push - the
	 * uploaded document (the actual customer-visible deliverable) is what matters most.
	 *
	 * @param string  $swOrderId
	 * @param Facture $facture   Already-fetched via Commande::fetchObjectLinked()
	 * @param string  $documentTypeId
	 * @return array{status:string, error?:string}
	 */
	protected function pushOneInvoice($swOrderId, Facture $facture, $documentTypeId)
	{
		global $langs;

		if ($facture->generateDocument($facture->model_pdf, $langs) < 0) {
			return array('status' => 'error', 'error' => 'Could not generate PDF for invoice '.$facture->ref.': '.$facture->error);
		}

		if (empty($facture->last_main_doc)) {
			return array('status' => 'error', 'error' => 'Invoice '.$facture->ref.' has no generated document after generateDocument()');
		}

		$pdfPath = DOL_DATA_ROOT.'/'.$facture->last_main_doc;
		if (!is_file($pdfPath)) {
			return array('status' => 'error', 'error' => 'Generated invoice PDF not found on disk: '.$pdfPath);
		}

		$mediaId = $this->mediaUuid($facture->ref);
		if ($this->client->sync(array(
			'upsert-invoice-media' => array(
				'entity' => 'media',
				'action' => 'upsert',
				'payload' => array(array('id' => $mediaId)),
			),
		)) === false) {
			return array('status' => 'error', 'error' => $this->client->error);
		}

		// Shopware's media file name is unique across the whole media library, not just per
		// document - prefix with the invoice ref (same reasoning/fix as
		// ShopwareProductSync::pushImages()'s CONTENT__MEDIA_DUPLICATED_FILE_NAME workaround).
		$swFileName = dol_sanitizeFileName('Rechnung_'.$facture->ref);
		if (!$this->client->uploadMediaFile($mediaId, $pdfPath, $swFileName, 'pdf')) {
			return array('status' => 'error', 'error' => $this->client->error);
		}

		$documentId = $this->documentUuid($facture->ref);
		if ($this->client->sync(array(
			'upsert-invoice-document' => array(
				'entity' => 'document',
				'action' => 'upsert',
				'payload' => array(array(
					'id' => $documentId,
					'documentTypeId' => $documentTypeId,
					'orderId' => $swOrderId,
					'documentMediaFileId' => $mediaId,
					'config' => array(
						'documentNumber' => $facture->ref,
						'title' => 'Rechnung '.$facture->ref,
					),
					'static' => true,
					'deepLinkCode' => $this->deepLinkCode($facture->ref),
				)),
			),
		)) === false) {
			return array('status' => 'error', 'error' => $this->client->error);
		}

		$this->writeOrderRefCustomField($swOrderId, $facture->ref);

		return array('status' => 'ok');
	}

	/**
	 * Best-effort: ensure a custom field set exposing "custom_dolibarr_order_ref" on the order
	 * entity exists (PLAN.md's original design - a Dolibarr reference visible on the order in
	 * Shopware admin, not just buried in a document), then write this invoice's ref into it.
	 * Not live-verified against a real shop yet (see ShopwareClient's own disclaimer on
	 * unverified API assumptions) - failures here are logged into $this->error but deliberately
	 * don't fail pushOneInvoice(), since the uploaded document is the deliverable that matters.
	 *
	 * @param string $swOrderId
	 * @param string $ref
	 * @return void
	 */
	protected function writeOrderRefCustomField($swOrderId, $ref)
	{
		$setId = $this->customFieldSetUuid();
		$relationId = md5('auvoshopware-custom-field-set-relation-order-'.$setId);
		$fieldId = md5('auvoshopware-custom-field-order-ref-'.$setId);

		$result = $this->client->sync(array(
			'upsert-order-ref-field-set' => array(
				'entity' => 'custom_field_set',
				'action' => 'upsert',
				'payload' => array(array(
					'id' => $setId,
					'name' => 'auvoshopware_order',
					'config' => array(
						'label' => array(
							'de-DE' => 'AuVoShopware',
							'en-GB' => 'AuVoShopware',
						),
					),
					'active' => true,
					'relations' => array(
						array('id' => $relationId, 'entityName' => 'order'),
					),
					'customFields' => array(
						array(
							'id' => $fieldId,
							'name' => 'custom_dolibarr_order_ref',
							'type' => 'text',
							'active' => true,
							'config' => array(
								'label' => array(
									'de-DE' => 'Dolibarr-Rechnungsreferenz',
									'en-GB' => 'Dolibarr invoice reference',
								),
								'customFieldType' => 'text',
								'componentName' => 'sw-field',
								'type' => 'text',
							),
						),
					),
				)),
			),
			'upsert-order-ref-value' => array(
				'entity' => 'order',
				'action' => 'upsert',
				'payload' => array(array(
					'id' => $swOrderId,
					'customFields' => array('custom_dolibarr_order_ref' => $ref),
				)),
			),
		));

		if ($result === false) {
			$this->error = $this->client->error;
		}
	}

	/**
	 * Deterministic id for the "AuVoShopware" custom field set (only one - not per-invoice).
	 *
	 * @return string
	 */
	protected function customFieldSetUuid()
	{
		return md5('auvoshopware-custom-field-set-order');
	}

	/**
	 * Deterministic id for an invoice's underlying media entity (the PDF file itself).
	 *
	 * @param string $ref
	 * @return string
	 */
	protected function mediaUuid($ref)
	{
		return md5('auvoshopware-invoice-media-'.$ref);
	}

	/**
	 * Deterministic id for an invoice's Shopware 'document' entity.
	 *
	 * @param string $ref
	 * @return string
	 */
	protected function documentUuid($ref)
	{
		return md5('auvoshopware-invoice-document-'.$ref);
	}

	/**
	 * A document's deepLinkCode (required field) lets a guest/customer open it without being
	 * logged in - doesn't need to be secret-random like a real Shopware-generated one here since
	 * this document is only ever reached via the customer's own authenticated order history, not
	 * shared as a standalone guest link.
	 *
	 * @param string $ref
	 * @return string
	 */
	protected function deepLinkCode($ref)
	{
		return substr(md5('auvoshopware-invoice-deeplink-'.$ref), 0, 32);
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
}
