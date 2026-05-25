<?php
/* Copyright (C) 2026 DoliStockReturn Module Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

global $conf, $user, $langs, $db;
require_once __DIR__.'/../../../../../master.inc.php';
require_once __DIR__.'/CommonClassTest.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/dolistockreturn/class/stockreturnservice.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$langs->load('dolistockreturn@dolistockreturn');

/**
 * Functional tests for stock return/output service.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
class StockReturnServiceTest extends CommonClassTest
{
	/** @var DoliDB */
	private $db;

	/** @var User */
	private $user;

	/** @var DoliStockReturnService */
	private $service;

	protected function setUp(): void
	{
		parent::setUp();

		global $conf, $db, $user, $langs, $mysoc;

		$this->db = $db;
		$this->user = $user;
		$this->service = new DoliStockReturnService($this->db);

		$langs->load('dolistockreturn@dolistockreturn');
		$conf->global->DOLISTOCKRETURN_USE_SOURCE_WAREHOUSE = 1;
		$conf->global->DOLISTOCKRETURN_SUPPLIER_USE_SOURCE_WAREHOUSE = 1;
		$conf->global->DOLISTOCKRETURN_DEFAULT_WAREHOUSE = 0;
		$conf->global->DOLISTOCKRETURN_SUPPLIER_DEFAULT_WAREHOUSE = 0;
		$conf->global->DOLISTOCKRETURN_NON_STOCKABLE_POLICY = 'ignore';
		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 0;
		$conf->global->STOCK_DISALLOW_NEGATIVE_TRANSFER = 0;
		$conf->global->MAIN_MODULE_PRODUCTBATCH = 1;
		if (empty($conf->productbatch)) {
			$conf->productbatch = new stdClass();
		}
		$conf->productbatch->enabled = 1;
		if (!is_object($mysoc)) {
			$mysoc = (object) array(
				'id' => 0,
				'country_id' => 1,
				'country' => 'France',
				'country_code' => 'FR',
				'localtax1_assuj' => 0,
				'localtax2_assuj' => 0,
				'localtax1_value' => 0,
				'localtax2_value' => 0,
			);
		}

		$this->ensureTraceabilityTables();
	}

	public function testCustomerCreditNoteUsesSourceInvoiceWarehouse()
	{
		$productId = $this->createProduct('CUSTINV');
		$warehouseId = $this->createWarehouse('WH-CUSTINV');
		$sourceId = $this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 2);
		$this->insertStockMovement($sourceId, 'facture', $productId, $warehouseId, -2);

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $sourceId, $productId, -2));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnHeader($returnId, 'customer_credit_note', 'in', $creditNote->id, $sourceId, $warehouseId, 'source');
		$this->assertReturnDetail($returnId, $productId, $warehouseId, 2.0);
		$this->assertTrue($this->service->hasAlreadyReturned($creditNote->id));
	}

	public function testCustomerTraceabilityStateBeforeAndAfterStockReturn()
	{
		$productId = $this->createProduct('CUSTTRACE');
		$warehouseId = $this->createWarehouse('WH-CUSTTRACE');
		$source = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 2));
		$this->insertStockMovement($source->id, 'facture', $productId, $warehouseId, -2);
		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -2));

		$this->assertFalse($this->service->hasAlreadyReturned($creditNote->id));
		$this->assertEquals(0, $this->service->getReturnIdForCreditNote($creditNote->id));
		$this->assertTrue($this->service->isEligibleCreditNote($creditNote), $this->service->error);
		$this->assertTrue($this->service->linesMatchSource($creditNote, $source), $this->service->error);
		$returnableLines = $this->service->getReturnableLines($creditNote);
		$this->assertCount(1, $returnableLines);
		$this->assertEquals($productId, (int) $returnableLines[0]['fk_product']);
		$this->assertEquals(2.0, (float) $returnableLines[0]['qty']);

		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertTrue($this->service->hasAlreadyReturned($creditNote->id));
		$this->assertEquals($returnId, $this->service->getReturnIdForCreditNote($creditNote->id));
	}

	public function testCustomerLinesMismatchRejectsCreditNote()
	{
		$productId = $this->createProduct('CUSTMISMATCH');
		$source = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 2));
		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -1));

		$this->assertFalse($this->service->linesMatchSource($creditNote, $source));
		$this->assertFalse($this->service->isEligibleCreditNote($creditNote));
		$this->assertNotEmpty($this->service->error);
	}

	public function testCustomerPartialCreditNotesAreAcceptedWhenOptionIsEnabled()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('CUSTPARTIAL');
		$warehouseId = $this->createWarehouse('WH-CUSTPARTIAL');
		$source = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 10));
		$this->insertStockMovement($source->id, 'facture', $productId, $warehouseId, -10);

		$creditNoteA = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -4));
		$this->assertTrue($this->service->isEligibleCreditNote($creditNoteA), $this->service->error);
		$returnIdA = $this->service->createStockReturn($creditNoteA, 0, $this->user);
		$this->assertGreaterThan(0, $returnIdA, $this->service->error);
		$this->assertReturnDetail($returnIdA, $productId, $warehouseId, 4.0);

		$creditNoteB = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -6));
		$this->assertTrue($this->service->isEligibleCreditNote($creditNoteB), $this->service->error);
		$returnIdB = $this->service->createStockReturn($creditNoteB, 0, $this->user);
		$this->assertGreaterThan(0, $returnIdB, $this->service->error);
		$this->assertReturnDetail($returnIdB, $productId, $warehouseId, 6.0);

		$creditNoteC = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -1));
		$this->assertFalse($this->service->isEligibleCreditNote($creditNoteC));
		$this->assertStringContainsString('disponibles', strtolower($this->service->error));
	}

	public function testCustomerBatchProductUsesUniqueSourceBatch()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('CUSTBATCH', Product::TYPE_PRODUCT, 1, 1);
		$warehouseId = $this->createWarehouse('WH-CUSTBATCH');
		$source = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 5));
		$this->insertProductLot($productId, 'LOT-CUST-1');
		$this->insertStockMovement($source->id, 'facture', $productId, $warehouseId, -5, 'LOT-CUST-1');

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -2));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, 2.0, 'LOT-CUST-1');
	}

	public function testCustomerBatchProductBlocksAmbiguousSourceBatches()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('CUSTBATCHAMB', Product::TYPE_PRODUCT, 1, 1);
		$warehouseId = $this->createWarehouse('WH-CUSTBATCHAMB');
		$source = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 5));
		$this->insertProductLot($productId, 'LOT-CUST-A');
		$this->insertProductLot($productId, 'LOT-CUST-B');
		$this->insertStockMovement($source->id, 'facture', $productId, $warehouseId, -3, 'LOT-CUST-A');
		$this->insertStockMovement($source->id, 'facture', $productId, $warehouseId, -2, 'LOT-CUST-B');

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $source->id, $productId, -1));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertLessThan(0, $returnId);
		$this->assertStringContainsString('plusieurs lots', strtolower($this->service->error));
	}

	public function testCustomerCreditNoteUsesLinkedOrderWarehouse()
	{
		$productId = $this->createProduct('CUSTORD');
		$warehouseId = $this->createWarehouse('WH-CUSTORD');
		$orderId = $this->createObjectRow('commande');
		$sourceId = $this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 1);
		$this->insertElementLink($orderId, 'commande', $sourceId, 'facture');
		$this->insertStockMovement($orderId, 'commande', $productId, $warehouseId, -1);

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $sourceId, $productId, -1));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, 1.0);
	}

	public function testCustomerCreditNoteUsesLinkedShipmentWarehouse()
	{
		$productId = $this->createProduct('CUSTSHIP');
		$warehouseId = $this->createWarehouse('WH-CUSTSHIP');
		$orderId = $this->createObjectRow('commande');
		$shippingId = $this->createObjectRow('expedition');
		$sourceId = $this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 3);
		$this->insertElementLink($orderId, 'commande', $sourceId, 'facture');
		$this->insertElementLink($orderId, 'commande', $shippingId, 'shipping');
		$this->insertStockMovement($shippingId, 'shipping', $productId, $warehouseId, -3);

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $sourceId, $productId, -3));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, 3.0);
	}

	public function testCustomerAmbiguousSourceWarehousesRequireManualChoice()
	{
		$productId = $this->createProduct('CUSTAMB');
		$warehouseA = $this->createWarehouse('WH-CUSTAMBA');
		$warehouseB = $this->createWarehouse('WH-CUSTAMBB');
		$sourceId = $this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 2);
		$this->insertStockMovement($sourceId, 'facture', $productId, $warehouseA, -1);
		$this->insertStockMovement($sourceId, 'facture', $productId, $warehouseB, -1);

		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $sourceId, $productId, -2));
		$returnId = $this->service->createStockReturn($creditNote, 0, $this->user);

		$this->assertLessThan(0, $returnId);
		$this->assertStringContainsString('manuel', strtolower($this->service->error));
	}

	public function testSupplierCreditNoteUsesSourceInvoiceWarehouseAndStoresNegativeDetailQty()
	{
		$productId = $this->createProduct('SUPINV');
		$warehouseId = $this->createWarehouse('WH-SUPINV');
		$sourceId = $this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 2);
		$this->insertStockMovement($sourceId, 'invoice_supplier', $productId, $warehouseId, 2);
		$this->insertStockMovement(900001, 'test_seed', $productId, $warehouseId, 5);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $sourceId, $productId, -2));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnHeader($returnId, 'supplier_credit_note', 'out', $creditNote->id, $sourceId, $warehouseId, 'source');
		$this->assertReturnDetail($returnId, $productId, $warehouseId, -2.0);
		$this->assertTrue($this->service->hasAlreadyReturned($creditNote->id, 'supplier_credit_note'));
	}

	public function testSupplierTraceabilityStateBeforeAndAfterStockOutput()
	{
		$productId = $this->createProduct('SUPTRACE');
		$warehouseId = $this->createWarehouse('WH-SUPTRACE');
		$source = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 3));
		$this->insertStockMovement($source->id, 'invoice_supplier', $productId, $warehouseId, 3);
		$this->insertStockMovement(910001, 'test_seed', $productId, $warehouseId, 5);
		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -3));

		$this->assertFalse($this->service->hasAlreadyReturned($creditNote->id, 'supplier_credit_note'));
		$this->assertEquals(0, $this->service->getReturnIdForCreditNote($creditNote->id, 'supplier_credit_note'));
		$this->assertTrue($this->service->isEligibleSupplierCreditNote($creditNote), $this->service->error);
		$this->assertTrue($this->service->supplierLinesMatchSource($creditNote, $source), $this->service->error);
		$returnableLines = $this->service->getSupplierReturnableLines($creditNote);
		$this->assertCount(1, $returnableLines);
		$this->assertEquals($productId, (int) $returnableLines[0]['fk_product']);
		$this->assertEquals(3.0, (float) $returnableLines[0]['qty']);

		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertTrue($this->service->hasAlreadyReturned($creditNote->id, 'supplier_credit_note'));
		$this->assertEquals($returnId, $this->service->getReturnIdForCreditNote($creditNote->id, 'supplier_credit_note'));
	}

	public function testSupplierLinesMismatchRejectsCreditNote()
	{
		$productId = $this->createProduct('SUPMISMATCH');
		$source = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 2));
		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -1));

		$this->assertFalse($this->service->supplierLinesMatchSource($creditNote, $source));
		$this->assertFalse($this->service->isEligibleSupplierCreditNote($creditNote));
		$this->assertNotEmpty($this->service->error);
	}

	public function testSupplierPartialCreditNotesAreAcceptedWhenOptionIsEnabled()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('SUPPARTIAL');
		$warehouseId = $this->createWarehouse('WH-SUPPARTIAL');
		$source = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 10));
		$this->insertStockMovement($source->id, 'invoice_supplier', $productId, $warehouseId, 10);
		$this->insertStockMovement(920001, 'test_seed', $productId, $warehouseId, 12);

		$creditNoteA = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -4));
		$this->assertTrue($this->service->isEligibleSupplierCreditNote($creditNoteA), $this->service->error);
		$returnIdA = $this->service->createSupplierStockOutput($creditNoteA, 0, $this->user);
		$this->assertGreaterThan(0, $returnIdA, $this->service->error);
		$this->assertReturnDetail($returnIdA, $productId, $warehouseId, -4.0);

		$creditNoteB = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -6));
		$this->assertTrue($this->service->isEligibleSupplierCreditNote($creditNoteB), $this->service->error);
		$returnIdB = $this->service->createSupplierStockOutput($creditNoteB, 0, $this->user);
		$this->assertGreaterThan(0, $returnIdB, $this->service->error);
		$this->assertReturnDetail($returnIdB, $productId, $warehouseId, -6.0);

		$creditNoteC = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -1));
		$this->assertFalse($this->service->isEligibleSupplierCreditNote($creditNoteC));
		$this->assertStringContainsString('disponibles', strtolower($this->service->error));
	}

	public function testSupplierBatchProductUsesUniqueSourceBatch()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('SUPBATCH', Product::TYPE_PRODUCT, 1, 1);
		$warehouseId = $this->createWarehouse('WH-SUPBATCH');
		$source = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 5));
		$this->insertProductLot($productId, 'LOT-SUP-1');
		$this->insertStockMovement($source->id, 'invoice_supplier', $productId, $warehouseId, 5, 'LOT-SUP-1');
		$this->insertProductBatchStock($productId, $warehouseId, 'LOT-SUP-1', 8);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -2));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, -2.0, 'LOT-SUP-1');
	}

	public function testSupplierBatchProductBlocksAmbiguousSourceBatches()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_ALLOW_PARTIAL_CREDIT_NOTES = 1;
		$productId = $this->createProduct('SUPBATCHAMB', Product::TYPE_PRODUCT, 1, 1);
		$warehouseId = $this->createWarehouse('WH-SUPBATCHAMB');
		$source = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 5));
		$this->insertProductLot($productId, 'LOT-SUP-A');
		$this->insertProductLot($productId, 'LOT-SUP-B');
		$this->insertStockMovement($source->id, 'invoice_supplier', $productId, $warehouseId, 3, 'LOT-SUP-A');
		$this->insertStockMovement($source->id, 'invoice_supplier', $productId, $warehouseId, 2, 'LOT-SUP-B');
		$this->insertProductBatchStock($productId, $warehouseId, 'LOT-SUP-A', 3);
		$this->insertProductBatchStock($productId, $warehouseId, 'LOT-SUP-B', 2);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $source->id, $productId, -1));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertLessThan(0, $returnId);
		$this->assertStringContainsString('plusieurs lots', strtolower($this->service->error));
	}

	public function testSupplierCreditNoteUsesLinkedSupplierOrderWarehouse()
	{
		$productId = $this->createProduct('SUPORD');
		$warehouseId = $this->createWarehouse('WH-SUPORD');
		$orderId = $this->createObjectRow('commande_fournisseur');
		$sourceId = $this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 1);
		$this->insertElementLink($orderId, 'order_supplier', $sourceId, 'invoice_supplier');
		$this->insertStockMovement($orderId, 'order_supplier', $productId, $warehouseId, 1);
		$this->insertStockMovement(900002, 'test_seed', $productId, $warehouseId, 5);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $sourceId, $productId, -1));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, -1.0);
	}

	public function testSupplierCreditNoteUsesLinkedReceptionWarehouse()
	{
		$productId = $this->createProduct('SUPREC');
		$warehouseId = $this->createWarehouse('WH-SUPREC');
		$orderId = $this->createObjectRow('commande_fournisseur');
		$receptionId = $this->createObjectRow('reception');
		$sourceId = $this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 4);
		$this->insertElementLink($orderId, 'order_supplier', $sourceId, 'invoice_supplier');
		$this->insertElementLink($orderId, 'order_supplier', $receptionId, 'reception');
		$this->insertStockMovement($receptionId, 'reception', $productId, $warehouseId, 4);
		$this->insertStockMovement(900003, 'test_seed', $productId, $warehouseId, 6);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $sourceId, $productId, -4));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertGreaterThan(0, $returnId, $this->service->error);
		$this->assertReturnDetail($returnId, $productId, $warehouseId, -4.0);
	}

	public function testSupplierAmbiguousSourceWarehousesRequireManualChoice()
	{
		$productId = $this->createProduct('SUPAMB');
		$warehouseA = $this->createWarehouse('WH-SUPAMBA');
		$warehouseB = $this->createWarehouse('WH-SUPAMBB');
		$sourceId = $this->createSupplierInvoice(FactureFournisseur::TYPE_STANDARD, 0, $productId, 2);
		$this->insertStockMovement($sourceId, 'invoice_supplier', $productId, $warehouseA, 1);
		$this->insertStockMovement($sourceId, 'invoice_supplier', $productId, $warehouseB, 1);

		$creditNote = $this->fetchSupplierInvoice($this->createSupplierInvoice(FactureFournisseur::TYPE_CREDIT_NOTE, $sourceId, $productId, -2));
		$returnId = $this->service->createSupplierStockOutput($creditNote, 0, $this->user);

		$this->assertLessThan(0, $returnId);
		$this->assertStringContainsString('manuel', strtolower($this->service->error));
	}

	public function testNonStockableBlockPolicyRejectsCreditNote()
	{
		global $conf;

		$conf->global->DOLISTOCKRETURN_NON_STOCKABLE_POLICY = 'block';
		$productId = $this->createProduct('SERVICE', Product::TYPE_SERVICE, 0);
		$sourceId = $this->createCustomerInvoice(Facture::TYPE_STANDARD, 0, $productId, 1);
		$creditNote = $this->fetchCustomerInvoice($this->createCustomerInvoice(Facture::TYPE_CREDIT_NOTE, $sourceId, $productId, -1));

		$this->assertFalse($this->service->isEligibleCreditNote($creditNote));
		$this->assertNotEmpty($this->service->error);
	}

	private function ensureTraceabilityTables()
	{
		$this->assertDbQuery("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."dolistockreturn_return (
			rowid int(11) NOT NULL AUTO_INCREMENT,
			entity int(11) NOT NULL DEFAULT 1,
			object_type varchar(32) NOT NULL DEFAULT 'customer_credit_note',
			direction varchar(8) NOT NULL DEFAULT 'in',
			fk_credit_note int(11) NOT NULL,
			fk_source_invoice int(11) NOT NULL,
			fk_entrepot int(11) DEFAULT NULL,
			warehouse_mode varchar(20) NOT NULL DEFAULT 'manual',
			status smallint(6) NOT NULL DEFAULT 1,
			date_create datetime NOT NULL,
			fk_user_create int(11) NOT NULL,
			note_private text,
			PRIMARY KEY (rowid),
			UNIQUE KEY uk_dolistockreturn_credit_note (object_type, fk_credit_note, entity),
			KEY idx_dolistockreturn_source_invoice (fk_source_invoice),
			KEY idx_dolistockreturn_entrepot (fk_entrepot)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->assertDbQuery("CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."dolistockreturn_returndet (
			rowid int(11) NOT NULL AUTO_INCREMENT,
			fk_return int(11) NOT NULL,
			fk_credit_note_line int(11) NOT NULL,
			fk_source_invoice_line int(11) DEFAULT NULL,
			fk_product int(11) NOT NULL,
			fk_entrepot int(11) NOT NULL,
			qty double(24,8) NOT NULL,
			fk_stock_mouvement int(11) DEFAULT NULL,
			batch varchar(128) DEFAULT NULL,
			PRIMARY KEY (rowid),
			KEY idx_dolistockreturn_returndet_return (fk_return),
			KEY idx_dolistockreturn_returndet_product (fk_product),
			KEY idx_dolistockreturn_returndet_movement (fk_stock_mouvement)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	}

	private function createProduct($suffix, $type = Product::TYPE_PRODUCT, $stockable = 1, $statusBatch = 0)
	{
		global $conf;

		$ref = 'DSR-'.$suffix.'-'.mt_rand(10000, 99999);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."product";
		$sql .= " (ref, label, fk_product_type, entity, tosell, tobuy, stockable_product, tobatch, duration, datec, tms)";
		$sql .= " VALUES ('".$this->db->escape($ref)."', '".$this->db->escape($ref)."', ".((int) $type).", ".((int) $conf->entity).", 1, 1, ".((int) $stockable).", ".((int) $statusBatch).", '', '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
		$this->assertDbQuery($sql);
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'product');
	}

	private function createWarehouse($suffix)
	{
		global $conf;

		$ref = 'DSR-'.$suffix.'-'.mt_rand(10000, 99999);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."entrepot";
		$sql .= " (ref, description, lieu, entity, statut, datec, tms)";
		$sql .= " VALUES ('".$this->db->escape($ref)."', '".$this->db->escape($ref)."', '".$this->db->escape($ref)."', ".((int) $conf->entity).", 1, '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
		$this->assertDbQuery($sql);
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'entrepot');
	}

	private function createThirdparty($supplier = false)
	{
		global $conf;

		$name = 'DSR thirdparty '.mt_rand(10000, 99999);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."societe";
		$sql .= " (entity, nom, client, fournisseur, status, tms)";
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($name)."', ".($supplier ? 0 : 1).", ".($supplier ? 1 : 0).", 1, '".$this->db->idate(dol_now())."')";
		$this->assertDbQuery($sql);
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'societe');
	}

	private function createCustomerInvoice($type, $sourceId, $productId, $qty)
	{
		$invoice = new Facture($this->db);
		$invoice->socid = $this->createThirdparty(false);
		$invoice->type = (int) $type;
		$invoice->date = dol_now();
		$invoice->fk_facture_source = (int) $sourceId;
		$invoice->ref_client = '';
		$invoice->ref_customer = '';
		$invoice->note_public = '';
		$invoice->note_private = '';

		$res = $invoice->create($this->user);
		$this->assertGreaterThan(0, $res, (string) $invoice->error);
		$res = $invoice->addline('DoliStockReturn test line', 10, (float) $qty, 0, 0, 0, (int) $productId);
		$this->assertGreaterThan(0, $res, (string) $invoice->error);

		$this->assertDbQuery("UPDATE ".MAIN_DB_PREFIX."facture SET fk_statut = ".Facture::STATUS_VALIDATED." WHERE rowid = ".((int) $invoice->id));
		return (int) $invoice->id;
	}

	private function createSupplierInvoice($type, $sourceId, $productId, $qty)
	{
		$invoice = new FactureFournisseur($this->db);
		$invoice->socid = $this->createThirdparty(true);
		$invoice->type = (int) $type;
		$invoice->date = dol_now();
		$invoice->ref_supplier = 'DSR-SUP-'.mt_rand(10000, 99999);
		$invoice->fk_facture_source = (int) $sourceId;

		$res = $invoice->create($this->user);
		$this->assertGreaterThan(0, $res, (string) $invoice->error);
		$res = $invoice->addline('DoliStockReturn supplier test line', 10, 0, 0, 0, (float) $qty, (int) $productId);
		$this->assertGreaterThan(0, $res, (string) $invoice->error);

		$this->assertDbQuery("UPDATE ".MAIN_DB_PREFIX."facture_fourn SET fk_statut = ".FactureFournisseur::STATUS_VALIDATED." WHERE rowid = ".((int) $invoice->id));
		return (int) $invoice->id;
	}

	private function fetchCustomerInvoice($invoiceId)
	{
		$invoice = new Facture($this->db);
		$this->assertGreaterThan(0, $invoice->fetch((int) $invoiceId));
		$invoice->fetch_lines();
		return $invoice;
	}

	private function fetchSupplierInvoice($invoiceId)
	{
		$invoice = new FactureFournisseur($this->db);
		$this->assertGreaterThan(0, $invoice->fetch((int) $invoiceId));
		$invoice->fetch_lines();
		return $invoice;
	}

	private function createObjectRow($table)
	{
		static $objectId = null;
		if ($objectId === null) {
			$objectId = mt_rand(2000000, 9000000);
		}
		$objectId++;

		return $objectId;
	}

	private function insertElementLink($sourceId, $sourceType, $targetId, $targetType)
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."element_element";
		$sql .= " (fk_source, sourcetype, fk_target, targettype)";
		$sql .= " VALUES (".((int) $sourceId).", '".$this->db->escape($sourceType)."', ".((int) $targetId).", '".$this->db->escape($targetType)."')";
		$this->assertDbQuery($sql);
	}

	private function insertProductLot($productId, $batch)
	{
		global $conf;

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_lot";
		$sql .= " (entity, fk_product, batch, datec)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $productId).", '".$this->db->escape($batch)."', '".$this->db->idate(dol_now())."')";
		$this->assertDbQuery($sql);
	}

	private function insertProductBatchStock($productId, $warehouseId, $batch, $qty)
	{
		$this->assertDbQuery("INSERT INTO ".MAIN_DB_PREFIX."product_stock (fk_product, fk_entrepot, reel) VALUES (".((int) $productId).", ".((int) $warehouseId).", ".((float) $qty).") ON DUPLICATE KEY UPDATE reel = reel + ".((float) $qty));

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_stock WHERE fk_product = ".((int) $productId)." AND fk_entrepot = ".((int) $warehouseId);
		$res = $this->db->query($sql);
		$this->assertTrue($res !== false, (string) $this->db->lasterror());
		$obj = $this->db->fetch_object($res);
		$this->assertNotEmpty($obj);
		$this->db->free($res);

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_batch";
		$sql .= " (fk_product_stock, batch, qty)";
		$sql .= " VALUES (".((int) $obj->rowid).", '".$this->db->escape($batch)."', ".((float) $qty).")";
		$this->assertDbQuery($sql);
	}

	private function insertStockMovement($originId, $originType, $productId, $warehouseId, $value, $batch = '')
	{
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."stock_mouvement";
		$sql .= " (datem, fk_product, batch, fk_entrepot, value, price, type_mouvement, fk_user_author, label, inventorycode, origintype, fk_origin)";
		$sql .= " VALUES ('".$this->db->idate(dol_now())."', ".((int) $productId).", ".($batch !== '' ? "'".$this->db->escape($batch)."'" : "null").", ".((int) $warehouseId).", ".((float) $value).", 10, ";
		$sql .= (((float) $value) >= 0 ? 0 : 1).", ".((int) $this->user->id).", 'DoliStockReturn test movement', 'DSR-TEST', '".$this->db->escape($originType)."', ".((int) $originId).")";
		$this->assertDbQuery($sql);

		if ((float) $value > 0) {
			$this->assertDbQuery("INSERT INTO ".MAIN_DB_PREFIX."product_stock (fk_product, fk_entrepot, reel) VALUES (".((int) $productId).", ".((int) $warehouseId).", ".((float) $value).") ON DUPLICATE KEY UPDATE reel = reel + ".((float) $value));
		}
	}

	private function assertReturnHeader($returnId, $objectType, $direction, $creditNoteId, $sourceId, $warehouseId, $mode)
	{
		$sql = "SELECT * FROM ".MAIN_DB_PREFIX."dolistockreturn_return WHERE rowid = ".((int) $returnId);
		$res = $this->db->query($sql);
		$this->assertTrue($res !== false, (string) $this->db->lasterror());
		$obj = $this->db->fetch_object($res);
		$this->assertNotEmpty($obj);
		$this->assertEquals($objectType, $obj->object_type);
		$this->assertEquals($direction, $obj->direction);
		$this->assertEquals((int) $creditNoteId, (int) $obj->fk_credit_note);
		$this->assertEquals((int) $sourceId, (int) $obj->fk_source_invoice);
		$this->assertEquals((int) $warehouseId, (int) $obj->fk_entrepot);
		$this->assertEquals($mode, $obj->warehouse_mode);
		$this->db->free($res);
	}

	private function assertReturnDetail($returnId, $productId, $warehouseId, $qty, $batch = null)
	{
		$sql = "SELECT d.*, sm.value as movement_value, sm.fk_entrepot as movement_warehouse";
		$sql .= " FROM ".MAIN_DB_PREFIX."dolistockreturn_returndet as d";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."stock_mouvement as sm ON sm.rowid = d.fk_stock_mouvement";
		$sql .= " WHERE d.fk_return = ".((int) $returnId);
		$sql .= " AND d.fk_product = ".((int) $productId);
		$res = $this->db->query($sql);
		$this->assertTrue($res !== false, (string) $this->db->lasterror());
		$obj = $this->db->fetch_object($res);
		$this->assertNotEmpty($obj);
		$this->assertEquals((int) $warehouseId, (int) $obj->fk_entrepot);
		$this->assertEquals((float) $qty, (float) $obj->qty);
		$this->assertEquals((int) $warehouseId, (int) $obj->movement_warehouse);
		$this->assertEquals((float) $qty, (float) $obj->movement_value);
		if ($batch !== null) {
			$this->assertEquals((string) $batch, (string) $obj->batch);
		}
		$this->db->free($res);
	}

	private function assertDbQuery($sql)
	{
		$res = $this->db->query($sql);
		$this->assertTrue($res !== false, (string) $this->db->lasterror().' SQL='.$sql);
	}
}
