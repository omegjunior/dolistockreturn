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
	public function hasAlreadyReturned($creditNoteId)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " WHERE fk_credit_note = ".((int) $creditNoteId);
		$sql .= " AND entity IN (".getEntity('invoice').")";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return false;
		}

		$found = (bool) $this->db->fetch_object($resql);
		$this->db->free($resql);

		return $found || $this->hasNativeStockInputForCreditNote($creditNoteId);
	}

	/**
	 * Return existing return id for a credit note.
	 *
	 * @param int $creditNoteId Credit note id
	 * @return int
	 */
	public function getReturnIdForCreditNote($creditNoteId)
	{
		$sql = "SELECT rowid FROM ".$this->db->prefix()."dolistockreturn_return";
		$sql .= " WHERE fk_credit_note = ".((int) $creditNoteId);
		$sql .= " AND entity IN (".getEntity('invoice').")";
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

		if (!$this->linesMatchSource($creditNote, $source)) {
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
		$sql .= " (entity, fk_credit_note, fk_source_invoice, fk_entrepot, warehouse_mode, status, date_create, fk_user_create)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $creditNote->id).", ".((int) $creditNote->fk_facture_source).", ";
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
			$result = $movement->reception($user, $productId, $targetWarehouseId, (float) $line['qty'], (float) $line['price'], $label, '', '', (string) $line['batch'], dol_now(), 0, $inventoryCode);
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
			$sql .= ((string) $line['batch'] !== '' ? "'".$this->db->escape((string) $line['batch'])."'" : "null").")";

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
			$sourceMap = $this->getSourceWarehouseMap((int) $creditNote->fk_facture_source);
			$sourceOk = true;
			foreach ($lines as $line) {
				$productId = (int) $line['fk_product'];
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
	 * Get warehouse map from original stock output movements.
	 *
	 * @param int $sourceInvoiceId Source invoice id
	 * @return array<int,int>
	 */
	private function getSourceWarehouseMap($sourceInvoiceId)
	{
		$map = array();
		$ambiguous = array();

		$sql = "SELECT fk_product, fk_entrepot";
		$sql .= " FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE fk_origin = ".((int) $sourceInvoiceId);
		$sql .= " AND origintype = 'facture'";
		$sql .= " AND value < 0";
		$sql .= " AND fk_product IS NOT NULL";
		$sql .= " GROUP BY fk_product, fk_entrepot";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->setError($this->db->lasterror());
			return array();
		}

		while ($obj = $this->db->fetch_object($resql)) {
			$productId = (int) $obj->fk_product;
			if (isset($map[$productId]) && (int) $map[$productId] !== (int) $obj->fk_entrepot) {
				$ambiguous[$productId] = 1;
				unset($map[$productId]);
				continue;
			}
			if (empty($ambiguous[$productId])) {
				$map[$productId] = (int) $obj->fk_entrepot;
			}
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
		$sql .= " AND ABS(qty) = ".((float) $qty);
		$sql .= " ORDER BY rowid ASC LIMIT 1";

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
