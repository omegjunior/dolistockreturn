<?php
/* Copyright (C) 2026 Fred S. Omega Junior <omegajunior.apps@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    htdocs/custom/dolistockreturn/class/stockreturnservice.class.php
 * \ingroup dolistockreturn
 * \brief   Business service for stock returns from customer credit notes.
 */

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';

/**
 * Business service for stock returns from credit notes.
 */
class DoliStockReturnService
{
	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var string[]
	 */
	public $errors = array();

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Check if a credit note already generated a stock return.
	 *
	 * @param int $creditNoteId Credit note id
	 * @return bool
	 */
	public function hasAlreadyReturned($creditNoteId, $objectType = 'customer_credit_note')
	{
		$this->ensureGenericTraceabilitySchema();

		$entityKey = ($objectType === 'supplier_credit_note' ? 'supplier_invoice' : 'invoice');
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " WHERE fk_credit_note = ".((int) $creditNoteId);
		$sql .= " AND object_type = '".$this->db->escape($objectType)."'";
		$sql .= " AND entity IN (".getEntity($entityKey).")";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return false;
		}

		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($objectType === 'supplier_credit_note') {
			return $found || $this->hasNativeSupplierStockOutputForCreditNote($creditNoteId);
		}

		return $found || $this->hasNativeStockInputForCreditNote($creditNoteId);
	}

	/**
	 * Return existing return id for a credit note.
	 *
	 * @param int $creditNoteId Credit note id
	 * @return int
	 */
	public function getReturnIdForCreditNote($creditNoteId, $objectType = 'customer_credit_note')
	{
		$this->ensureGenericTraceabilitySchema();

		$entityKey = ($objectType === 'supplier_credit_note' ? 'supplier_invoice' : 'invoice');
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " WHERE fk_credit_note = ".((int) $creditNoteId);
		$sql .= " AND object_type = '".$this->db->escape($objectType)."'";
		$sql .= " AND entity IN (".getEntity($entityKey).")";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Check if the invoice can be returned to stock.
	 *
	 * @param Facture $creditNote Credit note
	 * @return bool
	 */
	public function isEligibleCreditNote($creditNote)
	{
		global $langs;

		if (empty($creditNote->id) || $creditNote->type != Facture::TYPE_CREDIT_NOTE || $creditNote->status != Facture::STATUS_VALIDATED) {
			$this->setError($langs->trans('DoliStockReturnNotEligible'));
			return false;
		}
		if (empty($creditNote->fk_facture_source)) {
			$this->setError($langs->trans('DoliStockReturnMissingSourceInvoice'));
			return false;
		}
		if ($this->hasAlreadyReturned((int) $creditNote->id)) {
			$this->setError($langs->trans('DoliStockReturnAlreadyDone'));
			return false;
		}

		$source = new Facture($this->db);
		if ($source->fetch((int) $creditNote->fk_facture_source) <= 0) {
			$this->setError($langs->trans('DoliStockReturnSourceInvoiceNotFound'));
			return false;
		}

		$lines = $this->getReturnableLines($creditNote);
		if (empty($lines)) {
			$this->setError($langs->trans('DoliStockReturnNoStockableLine'));
			return false;
		}

		if (getDolGlobalInt('DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES')) {
			if (!$this->linesPartiallyMatchSource($creditNote, $source, 'customer_credit_note')) {
				return false;
			}
		} elseif (!$this->linesMatchSource($creditNote, $source)) {
			return false;
		}

		return true;
	}

	/**
	 * Compare credit note stockable lines with source invoice stockable lines.
	 *
	 * @param Facture $creditNote Credit note
	 * @param Facture $source Source invoice
	 * @return bool
	 */
	public function linesMatchSource($creditNote, $source)
	{
		global $langs;

		$credit = $this->buildStockableProductQtyMap($creditNote);
		$origin = $this->buildStockableProductQtyMap($source);

		if (empty($credit) || count($credit) !== count($origin)) {
			$this->setError($langs->trans('DoliStockReturnLineMismatch'));
			return false;
		}

		foreach ($credit as $productId => $qty) {
			if (!isset($origin[$productId]) || abs((float) $origin[$productId] - (float) $qty) > 0.00001) {
				$this->setError($langs->trans('DoliStockReturnLineMismatch'));
				return false;
			}
		}

		return true;
	}

	/**
	 * Check that credit note stockable quantities are available on the source invoice.
	 *
	 * V1 is product-aggregated: it supports partial quantities without trying to allocate
	 * them across several identical source lines.
	 *
	 * @param Facture|FactureFournisseur $creditNote Credit note
	 * @param Facture|FactureFournisseur $source Source invoice
	 * @param string $objectType Traceability object type
	 * @return bool
	 */
	public function linesPartiallyMatchSource($creditNote, $source, $objectType = 'customer_credit_note')
	{
		global $langs;

		$credit = $this->buildStockableProductQtyMap($creditNote);
		$origin = $this->buildStockableProductQtyMap($source);
		$alreadyReturned = $this->getAlreadyReturnedQuantities((int) $source->id, $objectType);

		if (empty($credit)) {
			$this->setError($langs->trans('DoliStockReturnLineMismatch'));
			return false;
		}

		foreach ($credit as $productId => $qty) {
			$sourceQty = isset($origin[$productId]) ? (float) $origin[$productId] : 0.0;
			$alreadyQty = isset($alreadyReturned[$productId]) ? (float) $alreadyReturned[$productId] : 0.0;
			$availableQty = $sourceQty - $alreadyQty;
			if ($sourceQty <= 0 || (float) $qty - $availableQty > 0.00001) {
				$this->setError($langs->trans('DoliStockReturnPartialQtyUnavailable'));
				return false;
			}
		}

		return true;
	}

	/**
	 * Get stockable credit note lines that must be returned.
	 *
	 * @param Facture $creditNote Credit note
	 * @return array<int,array<string,mixed>>
	 */
	public function getReturnableLines($creditNote)
	{
		global $langs;

		$lines = array();
		$policy = getDolGlobalString('DOLISTOCKRETURN_NON_STOCKABLE_POLICY', 'ignore');

		foreach ($creditNote->lines as $line) {
			if (empty($line->fk_product)) {
				if ($policy === 'block') {
					$this->setError($langs->trans('DoliStockReturnLineMismatch'));
					return array();
				}
				continue;
			}

			$product = new Product($this->db);
			if ($product->fetch((int) $line->fk_product) <= 0) {
				$this->setError($langs->trans('ErrorRecordNotFound'));
				return array();
			}

			if (!$this->isStockableProduct($product)) {
				if ($policy === 'block') {
					$this->setError($langs->trans('DoliStockReturnLineMismatch'));
					return array();
				}
				continue;
			}

			$lines[] = array(
				'credit_line_id' => (int) $line->id,
				'source_line_id' => $this->findSourceLineId((int) $creditNote->fk_facture_source, (int) $line->fk_product, abs((float) $line->qty)),
				'fk_product' => (int) $line->fk_product,
				'product_ref' => (string) $product->ref,
				'requires_batch' => (int) $product->hasbatch(),
				'qty' => abs((float) $line->qty),
				'price' => abs((float) $line->subprice),
				'batch' => !empty($line->batch) ? (string) $line->batch : '',
			);
		}

		return $lines;
	}

	/**
	 * Create stock return and stock movements.
	 *
	 * @param Facture $creditNote Credit note
	 * @param int     $warehouseId Warehouse selected by user, 0 for automatic/default resolution
	 * @param User    $user User
	 * @return int Return id or <0
	 */
	public function createStockReturn($creditNote, $warehouseId, $user)
	{
		global $conf, $langs;

		$this->ensureGenericTraceabilitySchema();

		if (!$this->isEligibleCreditNote($creditNote)) {
			return -1;
		}

		$lines = $this->getReturnableLines($creditNote);
		if (empty($lines)) {
			return -1;
		}

		$warehouseMap = $this->resolveWarehouses($creditNote, $lines, $warehouseId);
		if (empty($warehouseMap)) {
			return -1;
		}

		$warehouseMode = ($warehouseId > 0 ? 'manual' : (getDolGlobalInt('DOLISTOCKRETURN_USE_SOURCE_WAREHOUSE') ? 'source' : 'default'));
		$headerWarehouseId = $warehouseId > 0 ? $warehouseId : $this->getSingleWarehouseFromMap($warehouseMap);

		$this->db->begin();
		$error = 0;

		$sql = "INSERT INTO ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " (entity, object_type, direction, fk_credit_note, fk_source_invoice, fk_entrepot, warehouse_mode, status, date_create, fk_user_create)";
		$sql .= " VALUES (".((int) $conf->entity).", 'customer_credit_note', 'in', ".((int) $creditNote->id).", ".((int) $creditNote->fk_facture_source).", ";
		$sql .= ($headerWarehouseId > 0 ? (int) $headerWarehouseId : "null").", '".$this->db->escape($warehouseMode)."', 1, '".$this->db->idate(dol_now())."', ".((int) $user->id).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->setError($this->db->lasterror());
		}

		$returnId = 0;
		if (!$error) {
			$returnId = (int) $this->db->last_insert_id($this->db->prefix()."dolistockreturn_return");
		}

		foreach ($lines as $line) {
			if ($error) {
				break;
			}

			$productId = (int) $line['fk_product'];
			$targetWarehouseId = !empty($warehouseMap[$productId]) ? (int) $warehouseMap[$productId] : 0;
			if ($targetWarehouseId <= 0) {
				$error++;
				$this->setError($langs->trans('DoliStockReturnWarehouseRequired'));
				break;
			}

			$movement = new MouvementStock($this->db);
			$movement->setOrigin($creditNote->element, (int) $creditNote->id);
			$label = $langs->trans('DoliStockReturn').' '.$creditNote->ref;
			$inventoryCode = 'CREDITNOTE-'.$creditNote->id.'-RETURN';
			$batch = $this->resolveBatchForLine((int) $creditNote->fk_facture_source, $line, $targetWarehouseId, 'customer_credit_note');
			if ($batch === false) {
				$error++;
				break;
			}
			$result = $movement->reception($user, $productId, $targetWarehouseId, (float) $line['qty'], (float) $line['price'], $label, '', '', (string) $batch, dol_now(), 0, $inventoryCode);
			if ($result < 0) {
				$error++;
				$this->errors = array_merge($this->errors, $movement->errors);
				$this->setError($movement->error ? $movement->error : $langs->trans('Error'));
				break;
			}

			$sql = "INSERT INTO ".$this->db->prefix()."dolistockreturn_returndet";
			$sql .= " (fk_return, fk_credit_note_line, fk_source_invoice_line, fk_product, fk_entrepot, qty, fk_stock_mouvement, batch)";
			$sql .= " VALUES (".((int) $returnId).", ".((int) $line['credit_line_id']).", ";
			$sql .= (!empty($line['source_line_id']) ? (int) $line['source_line_id'] : "null").", ";
			$sql .= $productId.", ".$targetWarehouseId.", ".((float) $line['qty']).", ".((int) $result).", ";
			$sql .= ((string) $batch !== '' ? "'".$this->db->escape((string) $batch)."'" : "null").")";

			if (!$this->db->query($sql)) {
				$error++;
				$this->setError($this->db->lasterror());
				break;
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $returnId;
	}

	/**
	 * Check if a supplier credit note can generate a stock output.
	 *
	 * @param FactureFournisseur $creditNote Supplier credit note
	 * @return bool
	 */
	public function isEligibleSupplierCreditNote($creditNote)
	{
		global $langs;

		if (empty($creditNote->id) || $creditNote->type != FactureFournisseur::TYPE_CREDIT_NOTE || $creditNote->status != FactureFournisseur::STATUS_VALIDATED) {
			$this->setError($langs->trans('DoliStockReturnSupplierNotEligible'));
			return false;
		}
		if (empty($creditNote->fk_facture_source)) {
			$this->setError($langs->trans('DoliStockReturnSupplierMissingSourceInvoice'));
			return false;
		}
		if ($this->hasAlreadyReturned((int) $creditNote->id, 'supplier_credit_note')) {
			$this->setError($langs->trans('DoliStockReturnSupplierAlreadyDone'));
			return false;
		}

		$source = new FactureFournisseur($this->db);
		if ($source->fetch((int) $creditNote->fk_facture_source) <= 0) {
			$this->setError($langs->trans('DoliStockReturnSourceInvoiceNotFound'));
			return false;
		}

		$lines = $this->getSupplierReturnableLines($creditNote);
		if (empty($lines)) {
			$this->setError($langs->trans('DoliStockReturnNoStockableLine'));
			return false;
		}

		if (getDolGlobalInt('DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES')) {
			if (!$this->linesPartiallyMatchSource($creditNote, $source, 'supplier_credit_note')) {
				return false;
			}
		} elseif (!$this->supplierLinesMatchSource($creditNote, $source)) {
			return false;
		}

		return true;
	}

	/**
	 * Compare supplier credit note lines with source supplier invoice lines.
	 *
	 * @param FactureFournisseur $creditNote Supplier credit note
	 * @param FactureFournisseur $source Source supplier invoice
	 * @return bool
	 */
	public function supplierLinesMatchSource($creditNote, $source)
	{
		global $langs;

		$credit = $this->buildStockableProductQtyMap($creditNote);
		$origin = $this->buildStockableProductQtyMap($source);

		if (empty($credit) || count($credit) !== count($origin)) {
			$this->setError($langs->trans('DoliStockReturnLineMismatch'));
			return false;
		}

		foreach ($credit as $productId => $qty) {
			if (!isset($origin[$productId]) || abs((float) $origin[$productId] - (float) $qty) > 0.00001) {
				$this->setError($langs->trans('DoliStockReturnLineMismatch'));
				return false;
			}
		}

		return true;
	}

	/**
	 * Get stockable supplier credit note lines.
	 *
	 * @param FactureFournisseur $creditNote Supplier credit note
	 * @return array<int,array<string,mixed>>
	 */
	public function getSupplierReturnableLines($creditNote)
	{
		global $langs;

		$lines = array();
		$policy = getDolGlobalString('DOLISTOCKRETURN_NON_STOCKABLE_POLICY', 'ignore');

		foreach ($creditNote->lines as $line) {
			if (empty($line->fk_product)) {
				if ($policy === 'block') {
					$this->setError($langs->trans('DoliStockReturnLineMismatch'));
					return array();
				}
				continue;
			}

			$product = new Product($this->db);
			if ($product->fetch((int) $line->fk_product) <= 0) {
				$this->setError($langs->trans('ErrorRecordNotFound'));
				return array();
			}

			if (!$this->isStockableProduct($product)) {
				if ($policy === 'block') {
					$this->setError($langs->trans('DoliStockReturnLineMismatch'));
					return array();
				}
				continue;
			}

			$lines[] = array(
				'credit_line_id' => (int) $line->id,
				'source_line_id' => $this->findSupplierSourceLineId((int) $creditNote->fk_facture_source, (int) $line->fk_product, abs((float) $line->qty)),
				'fk_product' => (int) $line->fk_product,
				'product_ref' => (string) $product->ref,
				'requires_batch' => (int) $product->hasbatch(),
				'qty' => abs((float) $line->qty),
				'price' => abs((float) $line->subprice),
				'batch' => !empty($line->batch) ? (string) $line->batch : '',
			);
		}

		return $lines;
	}

	/**
	 * Create supplier stock output and traceability rows.
	 *
	 * @param FactureFournisseur $creditNote Supplier credit note
	 * @param int                $warehouseId Warehouse selected by user, 0 for automatic/default resolution
	 * @param User               $user User
	 * @return int Return id or <0
	 */
	public function createSupplierStockOutput($creditNote, $warehouseId, $user)
	{
		global $conf, $langs;

		$this->ensureGenericTraceabilitySchema();

		if (!$this->isEligibleSupplierCreditNote($creditNote)) {
			return -1;
		}

		$lines = $this->getSupplierReturnableLines($creditNote);
		if (empty($lines)) {
			return -1;
		}

		$warehouseMap = $this->resolveSupplierWarehouses($creditNote, $lines, $warehouseId);
		if (empty($warehouseMap)) {
			return -1;
		}

		$warehouseMode = ($warehouseId > 0 ? 'manual' : (getDolGlobalInt('DOLISTOCKRETURN_SUPPLIER_USE_SOURCE_WAREHOUSE') ? 'source' : 'default'));
		$headerWarehouseId = $warehouseId > 0 ? $warehouseId : $this->getSingleWarehouseFromMap($warehouseMap);

		$this->db->begin();
		$error = 0;

		$sql = "INSERT INTO ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " (entity, object_type, direction, fk_credit_note, fk_source_invoice, fk_entrepot, warehouse_mode, status, date_create, fk_user_create)";
		$sql .= " VALUES (".((int) $conf->entity).", 'supplier_credit_note', 'out', ".((int) $creditNote->id).", ".((int) $creditNote->fk_facture_source).", ";
		$sql .= ($headerWarehouseId > 0 ? (int) $headerWarehouseId : "null").", '".$this->db->escape($warehouseMode)."', 1, '".$this->db->idate(dol_now())."', ".((int) $user->id).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$error++;
			$this->setError($this->db->lasterror());
		}

		$returnId = 0;
		if (!$error) {
			$returnId = (int) $this->db->last_insert_id($this->db->prefix()."dolistockreturn_return");
		}

		foreach ($lines as $line) {
			if ($error) {
				break;
			}

			$productId = (int) $line['fk_product'];
			$targetWarehouseId = !empty($warehouseMap[$productId]) ? (int) $warehouseMap[$productId] : 0;
			if ($targetWarehouseId <= 0) {
				$error++;
				$this->setError($langs->trans('DoliStockReturnWarehouseRequired'));
				break;
			}

			$movement = new MouvementStock($this->db);
			$movement->setOrigin($creditNote->element, (int) $creditNote->id);
			$label = $langs->trans('DoliStockReturnSupplier').' '.$creditNote->ref;
			$inventoryCode = 'SUPPLIERCREDITNOTE-'.$creditNote->id.'-OUTPUT';
			$batch = $this->resolveBatchForLine((int) $creditNote->fk_facture_source, $line, $targetWarehouseId, 'supplier_credit_note');
			if ($batch === false) {
				$error++;
				break;
			}
			$result = $movement->livraison($user, $productId, $targetWarehouseId, (float) $line['qty'], (float) $line['price'], $label, dol_now(), '', '', (string) $batch, 0, $inventoryCode);
			if ($result < 0) {
				$error++;
				$this->errors = array_merge($this->errors, $movement->errors);
				$this->setError($movement->error ? $movement->error : $langs->trans('Error'));
				break;
			}

			$sql = "INSERT INTO ".$this->db->prefix()."dolistockreturn_returndet";
			$sql .= " (fk_return, fk_credit_note_line, fk_source_invoice_line, fk_product, fk_entrepot, qty, fk_stock_mouvement, batch)";
			$sql .= " VALUES (".((int) $returnId).", ".((int) $line['credit_line_id']).", ";
			$sql .= (!empty($line['source_line_id']) ? (int) $line['source_line_id'] : "null").", ";
			$sql .= $productId.", ".$targetWarehouseId.", ".(0 - (float) $line['qty']).", ".((int) $result).", ";
			$sql .= ((string) $batch !== '' ? "'".$this->db->escape((string) $batch)."'" : "null").")";

			if (!$this->db->query($sql)) {
				$error++;
				$this->setError($this->db->lasterror());
				break;
			}
		}

		if ($error) {
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();
		return $returnId;
	}

	/**
	 * Resolve target warehouse per product.
	 *
	 * @param Facture $creditNote Credit note
	 * @param array<int,array<string,mixed>> $lines Lines
	 * @param int $warehouseId Selected warehouse, 0 for automatic/default
	 * @return array<int,int>
	 */
	private function resolveWarehouses($creditNote, $lines, $warehouseId)
	{
		global $langs;

		$map = array();
		if ($warehouseId > 0) {
			foreach ($lines as $line) {
				$map[(int) $line['fk_product']] = (int) $warehouseId;
			}
			return $map;
		}

		if (getDolGlobalInt('DOLISTOCKRETURN_USE_SOURCE_WAREHOUSE')) {
			$sourceResult = $this->getSourceWarehouseMap((int) $creditNote->fk_facture_source);
			$sourceMap = $sourceResult['map'];
			$sourceOk = true;
			foreach ($lines as $line) {
				$productId = (int) $line['fk_product'];
				if (!empty($sourceResult['ambiguous'][$productId])) {
					$this->setError($langs->trans('DoliStockReturnSourceWarehouseAmbiguousManualRequired'));
					return array();
				}
				if (empty($sourceMap[$productId])) {
					$sourceOk = false;
					break;
				}
				$map[$productId] = (int) $sourceMap[$productId];
			}
			if ($sourceOk) {
				return $map;
			}
		}

		$defaultWarehouse = (int) getDolGlobalString('DOLISTOCKRETURN_DEFAULT_WAREHOUSE');
		if ($defaultWarehouse > 0) {
			foreach ($lines as $line) {
				$map[(int) $line['fk_product']] = $defaultWarehouse;
			}
			return $map;
		}

		$this->setError($langs->trans('DoliStockReturnWarehouseRequired'));
		return array();
	}

	/**
	 * Resolve supplier stock output warehouse per product.
	 *
	 * @param FactureFournisseur $creditNote Supplier credit note
	 * @param array<int,array<string,mixed>> $lines Lines
	 * @param int $warehouseId Selected warehouse, 0 for automatic/default
	 * @return array<int,int>
	 */
	private function resolveSupplierWarehouses($creditNote, $lines, $warehouseId)
	{
		global $langs;

		$map = array();
		if ($warehouseId > 0) {
			foreach ($lines as $line) {
				$map[(int) $line['fk_product']] = (int) $warehouseId;
			}
			return $map;
		}

		if (getDolGlobalInt('DOLISTOCKRETURN_SUPPLIER_USE_SOURCE_WAREHOUSE')) {
			$sourceResult = $this->getSupplierSourceWarehouseMap((int) $creditNote->fk_facture_source);
			$sourceMap = $sourceResult['map'];
			$sourceOk = true;
			foreach ($lines as $line) {
				$productId = (int) $line['fk_product'];
				if (!empty($sourceResult['ambiguous'][$productId])) {
					$this->setError($langs->trans('DoliStockReturnSourceWarehouseAmbiguousManualRequired'));
					return array();
				}
				if (empty($sourceMap[$productId])) {
					$sourceOk = false;
					break;
				}
				$map[$productId] = (int) $sourceMap[$productId];
			}
			if ($sourceOk) {
				return $map;
			}
		}

		$defaultWarehouse = (int) getDolGlobalString('DOLISTOCKRETURN_SUPPLIER_DEFAULT_WAREHOUSE');
		if ($defaultWarehouse > 0) {
			foreach ($lines as $line) {
				$map[(int) $line['fk_product']] = $defaultWarehouse;
			}
			return $map;
		}

		$this->setError($langs->trans('DoliStockReturnWarehouseRequired'));
		return array();
	}

	/**
	 * Get warehouse map from original stock output movements.
	 *
	 * @param int $sourceInvoiceId Source invoice id
	 * @return array{map:array<int,int>,ambiguous:array<int,int>}
	 */
	private function getSourceWarehouseMap($sourceInvoiceId)
	{
		$result = array('map' => array(), 'ambiguous' => array());

		$sql = "SELECT fk_product, fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE fk_origin = ".((int) $sourceInvoiceId);
		$sql .= " AND origintype = 'facture'";
		$sql .= " AND value < 0";
		$sql .= " AND fk_product IS NOT NULL";
		$sql .= " GROUP BY fk_product, fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		$sql = "SELECT sm.fk_product, sm.fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."element_element as ei";
		$sql .= " INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'commande'";
		$sql .= " WHERE ei.fk_target = ".((int) $sourceInvoiceId);
		$sql .= " AND ei.sourcetype = 'commande'";
		$sql .= " AND ei.targettype = 'facture'";
		$sql .= " AND sm.value < 0";
		$sql .= " AND sm.fk_product IS NOT NULL";
		$sql .= " GROUP BY sm.fk_product, sm.fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		$sql = "SELECT sm.fk_product, sm.fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."element_element as ei";
		$sql .= " INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'shipping'";
		$sql .= " WHERE ei.fk_target = ".((int) $sourceInvoiceId);
		$sql .= " AND ei.sourcetype = 'shipping'";
		$sql .= " AND ei.targettype = 'facture'";
		$sql .= " AND sm.value < 0";
		$sql .= " AND sm.fk_product IS NOT NULL";
		$sql .= " GROUP BY sm.fk_product, sm.fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		$sql = "SELECT sm.fk_product, sm.fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."element_element as ei";
		$sql .= " INNER JOIN ".$this->db->prefix()."element_element as es ON es.fk_source = ei.fk_source AND es.sourcetype = 'commande' AND es.targettype = 'shipping'";
		$sql .= " INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = es.fk_target AND sm.origintype = 'shipping'";
		$sql .= " WHERE ei.fk_target = ".((int) $sourceInvoiceId);
		$sql .= " AND ei.sourcetype = 'commande'";
		$sql .= " AND ei.targettype = 'facture'";
		$sql .= " AND sm.value < 0";
		$sql .= " AND sm.fk_product IS NOT NULL";
		$sql .= " GROUP BY sm.fk_product, sm.fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		return $result;
	}

	/**
	 * Get supplier source warehouse map from invoice or linked receptions.
	 *
	 * @param int $sourceInvoiceId Source supplier invoice id
	 * @return array{map:array<int,int>,ambiguous:array<int,int>}
	 */
	private function getSupplierSourceWarehouseMap($sourceInvoiceId)
	{
		$result = array('map' => array(), 'ambiguous' => array());

		$sql = "SELECT fk_product, fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE fk_origin = ".((int) $sourceInvoiceId);
		$sql .= " AND origintype = 'invoice_supplier'";
		$sql .= " AND value > 0";
		$sql .= " AND fk_product IS NOT NULL";
		$sql .= " GROUP BY fk_product, fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		$sql = "SELECT sm.fk_product, sm.fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."element_element as ei";
		$sql .= " INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'order_supplier'";
		$sql .= " WHERE ei.fk_target = ".((int) $sourceInvoiceId);
		$sql .= " AND ei.sourcetype = 'order_supplier'";
		$sql .= " AND ei.targettype = 'invoice_supplier'";
		$sql .= " AND sm.value > 0";
		$sql .= " AND sm.fk_product IS NOT NULL";
		$sql .= " GROUP BY sm.fk_product, sm.fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);

		$sql = "SELECT sm.fk_product, sm.fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."element_element as ei";
		$sql .= " INNER JOIN ".$this->db->prefix()."element_element as er ON er.fk_source = ei.fk_source AND er.sourcetype = 'order_supplier' AND er.targettype = 'reception'";
		$sql .= " INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = er.fk_target AND sm.origintype = 'reception'";
		$sql .= " WHERE ei.fk_target = ".((int) $sourceInvoiceId);
		$sql .= " AND ei.sourcetype = 'order_supplier'";
		$sql .= " AND ei.targettype = 'invoice_supplier'";
		$sql .= " AND sm.value > 0";
		$sql .= " AND sm.fk_product IS NOT NULL";
		$sql .= " GROUP BY sm.fk_product, sm.fk_entrepot";

		$this->accumulateWarehouseRows($sql, $result);
		return $result;
	}

	/**
	 * Accumulate product/warehouse rows and mark product ambiguity.
	 *
	 * @param string $sql SQL query returning fk_product, fk_entrepot
	 * @param array{map:array<int,int>,ambiguous:array<int,int>} $result Result reference
	 * @return void
	 */
	private function accumulateWarehouseRows($sql, &$result)
	{
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$productId = (int) $obj->fk_product;
			if (isset($result['map'][$productId]) && (int) $result['map'][$productId] !== (int) $obj->fk_entrepot) {
				$result['ambiguous'][$productId] = 1;
				unset($result['map'][$productId]);
				continue;
			}
			if (empty($result['ambiguous'][$productId])) {
				$result['map'][$productId] = (int) $obj->fk_entrepot;
			}
		}

		$this->db->free($resql);
	}

	/**
	 * Resolve batch to use for a stock movement line.
	 *
	 * V1 intentionally supports only a single non-ambiguous source batch able
	 * to cover the whole credit note line quantity.
	 *
	 * @param int $sourceInvoiceId Source invoice id
	 * @param array<string,mixed> $line Returnable line
	 * @param int $warehouseId Warehouse id used for the movement
	 * @param string $objectType Traceability object type
	 * @return string|false Batch string, empty string for products without batch, false on error
	 */
	private function resolveBatchForLine($sourceInvoiceId, $line, $warehouseId, $objectType)
	{
		global $langs;

		if (empty($line['requires_batch'])) {
			return (string) $line['batch'];
		}

		$batchRows = $this->getSourceBatchRows($sourceInvoiceId, (int) $line['fk_product'], $warehouseId, $objectType);
		if (empty($batchRows)) {
			$this->setError($langs->trans('DoliStockReturnBatchRequired', (string) $line['product_ref']));
			return false;
		}

		if (count($batchRows) > 1) {
			$this->setError($langs->trans('DoliStockReturnBatchAmbiguous', (string) $line['product_ref']));
			return false;
		}

		$batch = '';
		foreach ($batchRows as $batchKey => $qty) {
			$batch = (string) $batchKey;
			break;
		}
		$availableQty = (float) $batchRows[$batch];
		if ((float) $line['qty'] - $availableQty > 0.00001) {
			$this->setError($langs->trans('DoliStockReturnBatchInsufficient', $batch, (string) $line['product_ref']));
			return false;
		}

		return $batch;
	}

	/**
	 * Get source batch quantities for one source invoice/product/warehouse.
	 *
	 * @param int $sourceInvoiceId Source invoice id
	 * @param int $productId Product id
	 * @param int $warehouseId Warehouse id
	 * @param string $objectType Traceability object type
	 * @return array<string,float>
	 */
	private function getSourceBatchRows($sourceInvoiceId, $productId, $warehouseId, $objectType)
	{
		$result = array();
		$operator = ($objectType === 'supplier_credit_note' ? '>' : '<');
		$queries = array();

		if ($objectType === 'supplier_credit_note') {
			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."stock_mouvement as sm
				WHERE sm.fk_origin = ".((int) $sourceInvoiceId)."
				AND sm.origintype = 'invoice_supplier'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";

			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."element_element as ei
				INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'order_supplier'
				WHERE ei.fk_target = ".((int) $sourceInvoiceId)."
				AND ei.sourcetype = 'order_supplier'
				AND ei.targettype = 'invoice_supplier'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";

			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."element_element as ei
				INNER JOIN ".$this->db->prefix()."element_element as er ON er.fk_source = ei.fk_source AND er.sourcetype = 'order_supplier' AND er.targettype = 'reception'
				INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = er.fk_target AND sm.origintype = 'reception'
				WHERE ei.fk_target = ".((int) $sourceInvoiceId)."
				AND ei.sourcetype = 'order_supplier'
				AND ei.targettype = 'invoice_supplier'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";
		} else {
			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."stock_mouvement as sm
				WHERE sm.fk_origin = ".((int) $sourceInvoiceId)."
				AND sm.origintype = 'facture'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";

			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."element_element as ei
				INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'commande'
				WHERE ei.fk_target = ".((int) $sourceInvoiceId)."
				AND ei.sourcetype = 'commande'
				AND ei.targettype = 'facture'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";

			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."element_element as ei
				INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = ei.fk_source AND sm.origintype = 'shipping'
				WHERE ei.fk_target = ".((int) $sourceInvoiceId)."
				AND ei.sourcetype = 'shipping'
				AND ei.targettype = 'facture'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";

			$queries[] = "SELECT sm.batch, SUM(ABS(sm.value)) as qty
				FROM ".$this->db->prefix()."element_element as ei
				INNER JOIN ".$this->db->prefix()."element_element as es ON es.fk_source = ei.fk_source AND es.sourcetype = 'commande' AND es.targettype = 'shipping'
				INNER JOIN ".$this->db->prefix()."stock_mouvement as sm ON sm.fk_origin = es.fk_target AND sm.origintype = 'shipping'
				WHERE ei.fk_target = ".((int) $sourceInvoiceId)."
				AND ei.sourcetype = 'commande'
				AND ei.targettype = 'facture'
				AND sm.value ".$operator." 0
				AND sm.fk_product = ".((int) $productId)."
				AND sm.fk_entrepot = ".((int) $warehouseId)."
				AND sm.batch IS NOT NULL AND sm.batch <> ''
				GROUP BY sm.batch";
		}

		foreach ($queries as $sql) {
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->setError($this->db->lasterror());
				return array();
			}
			while ($obj = $this->db->fetch_object($resql)) {
				$batch = (string) $obj->batch;
				if ($batch === '') {
					continue;
				}
				if (!isset($result[$batch])) {
					$result[$batch] = 0.0;
				}
				$result[$batch] += (float) $obj->qty;
			}
			$this->db->free($resql);
		}

		return $result;
	}

	/**
	 * Get quantities already processed for one source invoice, aggregated by product.
	 *
	 * @param int $sourceInvoiceId Source invoice id
	 * @param string $objectType Traceability object type
	 * @return array<int,float>
	 */
	private function getAlreadyReturnedQuantities($sourceInvoiceId, $objectType)
	{
		$map = array();
		$entityKey = ($objectType === 'supplier_credit_note' ? 'supplier_invoice' : 'invoice');

		$sql = "SELECT d.fk_product, SUM(ABS(d.qty)) as qty";
		$sql .= " FROM ".$this->db->prefix()."dolistockreturn_returndet as d";
		$sql .= " INNER JOIN ".$this->db->prefix()."dolistockreturn_return as r ON r.rowid = d.fk_return";
		$sql .= " WHERE r.fk_source_invoice = ".((int) $sourceInvoiceId);
		$sql .= " AND r.object_type = '".$this->db->escape($objectType)."'";
		$sql .= " AND r.status = 1";
		$sql .= " AND r.entity IN (".getEntity($entityKey).")";
		$sql .= " GROUP BY d.fk_product";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return $map;
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$map[(int) $obj->fk_product] = (float) $obj->qty;
		}
		$this->db->free($resql);

		return $map;
	}

	/**
	 * Check if Dolibarr already created positive stock movements for the credit note.
	 *
	 * @param int $creditNoteId Credit note id
	 * @return bool
	 */
	private function hasNativeStockInputForCreditNote($creditNoteId)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE fk_origin = ".((int) $creditNoteId);
		$sql .= " AND origintype = 'facture'";
		$sql .= " AND value > 0";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $found;
	}

	/**
	 * Check if Dolibarr already created negative stock movements for the supplier credit note.
	 *
	 * @param int $creditNoteId Supplier credit note id
	 * @return bool
	 */
	private function hasNativeSupplierStockOutputForCreditNote($creditNoteId)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE fk_origin = ".((int) $creditNoteId);
		$sql .= " AND origintype = 'invoice_supplier'";
		$sql .= " AND value < 0";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $found;
	}

	/**
	 * Build product => qty map for stockable products.
	 *
	 * @param Facture $invoice Invoice
	 * @return array<int,float>
	 */
	private function buildStockableProductQtyMap($invoice)
	{
		$map = array();

		foreach ($invoice->lines as $line) {
			if (empty($line->fk_product)) {
				continue;
			}
			$product = new Product($this->db);
			if ($product->fetch((int) $line->fk_product) <= 0 || !$this->isStockableProduct($product)) {
				continue;
			}

			$productId = (int) $line->fk_product;
			if (!isset($map[$productId])) {
				$map[$productId] = 0.0;
			}
			$map[$productId] += abs((float) $line->qty);
		}

		ksort($map);
		return $map;
	}

	/**
	 * Check if product should affect stock.
	 *
	 * @param Product $product Product
	 * @return bool
	 */
	private function isStockableProduct($product)
	{
		return ((int) $product->type !== Product::TYPE_SERVICE || getDolGlobalString('STOCK_SUPPORTS_SERVICES'))
			&& (int) $product->stockable_product === Product::ENABLED_STOCK;
	}

	/**
	 * Find a source line id for traceability.
	 *
	 * @param int   $sourceInvoiceId Source invoice
	 * @param int   $productId Product
	 * @param float $qty Quantity
	 * @return int
	 */
	private function findSourceLineId($sourceInvoiceId, $productId, $qty)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."facturedet";
		$sql .= " WHERE fk_facture = ".((int) $sourceInvoiceId);
		$sql .= " AND fk_product = ".((int) $productId);
		$sql .= " AND ABS(qty) >= ".((float) $qty);
		$sql .= " ORDER BY ABS(ABS(qty) - ".((float) $qty).") ASC, rowid ASC LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Find a supplier source line id for traceability.
	 *
	 * @param int   $sourceInvoiceId Source supplier invoice
	 * @param int   $productId Product
	 * @param float $qty Quantity
	 * @return int
	 */
	private function findSupplierSourceLineId($sourceInvoiceId, $productId, $qty)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."facture_fourn_det";
		$sql .= " WHERE fk_facture_fourn = ".((int) $sourceInvoiceId);
		$sql .= " AND fk_product = ".((int) $productId);
		$sql .= " AND ABS(qty) >= ".((float) $qty);
		$sql .= " ORDER BY ABS(ABS(qty) - ".((float) $qty).") ASC, rowid ASC LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $obj ? (int) $obj->rowid : 0;
	}

	/**
	 * Return common warehouse if all lines use the same one.
	 *
	 * @param array<int,int> $warehouseMap Warehouse map
	 * @return int
	 */
	private function getSingleWarehouseFromMap($warehouseMap)
	{
		$warehouseId = 0;
		foreach ($warehouseMap as $current) {
			if ($warehouseId === 0) {
				$warehouseId = (int) $current;
			} elseif ($warehouseId !== (int) $current) {
				return 0;
			}
		}
		return $warehouseId;
	}

	/**
	 * Ensure traceability tables have the generic columns and unique key.
	 *
	 * Existing deployments of the first customer-only version did not have
	 * object_type/direction, so hooks may run before the module is reactivated.
	 *
	 * @return void
	 */
	private function ensureGenericTraceabilitySchema()
	{
		static $done = false;
		if ($done) {
			return;
		}

		$table = $this->db->prefix().'dolistockreturn_return';
		$resql = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'object_type'");
		if ($resql && !$this->db->num_rows($resql)) {
			$this->db->query("ALTER TABLE ".$table." ADD COLUMN object_type varchar(32) NOT NULL DEFAULT 'customer_credit_note' AFTER entity");
		}
		if ($resql) {
			$this->db->free($resql);
		}

		$resql = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'direction'");
		if ($resql && !$this->db->num_rows($resql)) {
			$this->db->query("ALTER TABLE ".$table." ADD COLUMN direction varchar(8) NOT NULL DEFAULT 'in' AFTER object_type");
		}
		if ($resql) {
			$this->db->free($resql);
		}

		$needsIndexRebuild = true;
		$resql = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = 'uk_dolistockreturn_credit_note'");
		if ($resql) {
			$columns = array();
			while ($obj = $this->db->fetch_object($resql)) {
				$columns[(int) $obj->Seq_in_index] = $obj->Column_name;
			}
			ksort($columns);
			$needsIndexRebuild = (implode(',', $columns) !== 'object_type,fk_credit_note,entity');
			$this->db->free($resql);
		}

		if ($needsIndexRebuild) {
			$this->db->query("ALTER TABLE ".$table." DROP INDEX uk_dolistockreturn_credit_note");
			$this->db->query("ALTER TABLE ".$table." ADD UNIQUE KEY uk_dolistockreturn_credit_note (object_type, fk_credit_note, entity)");
		}

		$done = true;
	}

	/**
	 * Set error.
	 *
	 * @param string $message Error message
	 * @return void
	 */
	private function setError($message)
	{
		$this->error = $message;
		if ($message !== '') {
			$this->errors[] = $message;
		}
	}
}
