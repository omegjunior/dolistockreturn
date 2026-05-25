<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Fred S. Omega Junior
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
 * \file    dolistockreturn/class/actions_dolistockreturn.class.php
 * \ingroup dolistockreturn
 * \brief   Hook handlers for credit note stock return and supplier stock output actions.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/dolistockreturn/class/stockreturnservice.class.php';
/**
 * Class ActionsDolistockreturn
 */
class ActionsDolistockreturn extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var string[] Errors
	 */
	public $errors = array();


	/**
	 * @var mixed[] Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var ?string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param	DoliDB	$db      Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/**
	 * Execute action
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonObject		$object		The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 *                           				=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overload the doActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('invoicecard')) && $action == 'confirm_dolistockreturn') {	    // do something only for the context 'somecontext1' or 'somecontext2'
			// Do what you want here...
			// You can for example load and use call global vars like $fieldstosearchall to overwrite them, or update the database depending on $action and GETPOST values.
			$langs->load('dolistockreturn@dolistockreturn');
		
			if (!$user->hasRight('dolistockreturn', 'return', 'write')) {
				accessforbidden();
			}

			if (!GETPOST('confirm', 'alpha') || GETPOST('confirm', 'alpha') !== 'yes') {
				return 0;
			}

			$warehouseId = GETPOSTINT('manual_fk_entrepot');
			if ($warehouseId <= 0) {
				$warehouseId = GETPOSTINT('fk_entrepot');
			}
			$service = new DoliStockReturnService($db);
			$result = $service->createStockReturn($object, $warehouseId, $user);
			if ($result > 0) {
				setEventMessages($langs->trans('DoliStockReturnDone'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.(int) $object->id);
				exit;
			}

			setEventMessages($service->error, $service->errors, 'errors');
			return -1;

		}

		if (in_array($parameters['currentcontext'], array('invoicesuppliercard')) && $action == 'confirm_dolistockreturn_supplier') {
			$langs->load('dolistockreturn@dolistockreturn');

			if (!$user->hasRight('dolistockreturn', 'return', 'write')) {
				accessforbidden();
			}

			if (!GETPOST('confirm', 'alpha') || GETPOST('confirm', 'alpha') !== 'yes') {
				return 0;
			}

			$warehouseId = GETPOSTINT('manual_fk_entrepot');
			if ($warehouseId <= 0) {
				$warehouseId = GETPOSTINT('fk_entrepot');
			}
			$service = new DoliStockReturnService($db);
			$result = $service->createSupplierStockOutput($object, $warehouseId, $user);
			if ($result > 0) {
				setEventMessages($langs->trans('DoliStockReturnSupplierDone'), null, 'mesgs');
				header('Location: '.$_SERVER['PHP_SELF'].'?facid='.(int) $object->id);
				exit;
			}

			setEventMessages($service->error, $service->errors, 'errors');
			return -1;
		}

		return 0;
	}


	/**
	 * Overload the doMassActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			// @phan-suppress-next-line PhanPluginEmptyStatementForeachLoop
			foreach ($parameters['toselect'] as $objectid) {
				// Do action on each object id
			}

			if (!$error) {
				$this->results = array('myreturn' => 999);
				$this->resprints = 'A text to show';
				return 0; // or return 1 to replace standard code
			} else {
				$this->errors[] = 'Error message';
				return -1;
			}
		}

		return 0;
	}


	/**
	 * Overload the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters     Hook metadata (context, etc...)
	 * @param	CommonObject		$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string	$action						Current action (if set). Generally create or edit or null
	 * @param	HookManager	$hookmanager			Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter
		$disabled = 1;

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			$this->resprints = '<option value="0"'.($disabled ? ' disabled="disabled"' : '').'>'.$langs->trans("DolistockreturnMassAction").'</option>';
		}

		if (!$error) {
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}



	/**
	 * Execute action before PDF (document) creation
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonObject		$object		Object output on PDF
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 *											=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function beforePDFCreation($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this).'::executeHooks action='.$action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		// @phan-suppress-next-line PhanPluginEmptyStatementIf
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
			// do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}

	/**
	 * Execute action after PDF (document) creation
	 *
	 * @param	array<string,mixed>	$parameters	Array of parameters
	 * @param	CommonDocGenerator	$pdfhandler	PDF builder handler
	 * @param	string				$action		'add', 'update', 'view'
	 * @return	int								Return integer <0 if KO,
	 * 											=0 if OK but we want to process standard actions too,
	 *											>0 if OK and we want to replace standard actions.
	 */
	public function afterPDFCreation($parameters, &$pdfhandler, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this).'::executeHooks action='.$action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		// @phan-suppress-next-line PhanPluginEmptyStatementIf
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
			// do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}



	/**
	 * Overload the loadDataForCustomReports function : returns data to complete the customreport tool
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	?string				$action 		Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager    Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function loadDataForCustomReports($parameters, &$action, $hookmanager)
	{
		global $langs;

		$langs->load("dolistockreturn@dolistockreturn");

		$this->results = array();

		$head = array();
		$h = 0;

		if ($parameters['tabfamily'] == 'dolistockreturn') {
			$head[$h][0] = dol_buildpath('/module/index.php', 1);
			$head[$h][1] = $langs->trans("Home");
			$head[$h][2] = 'home';
			$h++;

			$this->results['title'] = $langs->trans("Dolistockreturn");
			$this->results['picto'] = 'dolistockreturn@dolistockreturn';
		}

		$head[$h][0] = 'customreports.php?objecttype='.$parameters['objecttype'].(empty($parameters['tabfamily']) ? '' : '&tabfamily='.$parameters['tabfamily']);
		$head[$h][1] = $langs->trans("CustomReports");
		$head[$h][2] = 'customreports';

		$this->results['head'] = $head;

		$arrayoftypes = array();
		//$arrayoftypes['dolistockreturn_myobject'] = array('label' => 'MyObject', 'picto'=>'myobject@dolistockreturn', 'ObjectClassName' => 'MyObject', 'enabled' => isModEnabled('dolistockreturn'), 'ClassPath' => "/dolistockreturn/class/myobject.class.php", 'langs'=>'dolistockreturn@dolistockreturn')

		$this->results['arrayoftype'] = $arrayoftypes;

		return 0;
	}



	/**
	 * Overload the restrictedArea function : check permission on an object
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param   CommonObject    	$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer <0 if KO,
	 *												=0 if OK but we want to process standard actions too,
	 *												>0 if OK and we want to replace standard actions.
	 */
	public function restrictedArea($parameters, $object, &$action, $hookmanager)
	{
		global $user;

		if ($parameters['features'] == 'myobject') {
			if ($user->hasRight('dolistockreturn', 'myobject', 'read')) {
				$this->results['result'] = 1;
				return 1;
			} else {
				$this->results['result'] = 0;
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Execute action completeTabsHead
	 *
	 * @param	array<string,mixed>	$parameters		Array of parameters
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string				$action			'add', 'update', 'view'
	 * @param	Hookmanager			$hookmanager	Hookmanager
	 * @return	int									Return integer <0 if KO,
	 *												=0 if OK but we want to process standard actions too,
	 *												>0 if OK and we want to replace standard actions.
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf, $user;

		if (!isset($parameters['object']->element)) {
			return 0;
		}
		if ($parameters['mode'] == 'remove') {
			// used to make some tabs removed
			return 0;
		} elseif ($parameters['mode'] == 'add') {
			$langs->load('dolistockreturn@dolistockreturn');
			// used when we want to add some tabs
			$counter = count($parameters['head']);
			$element = $parameters['object']->element;
			$id = $parameters['object']->id;
			// verifier le type d'onglet comme member_stats où ça ne doit pas apparaitre
			// if (in_array($element, ['societe', 'member', 'contrat', 'fichinter', 'project', 'propal', 'commande', 'facture', 'order_supplier', 'invoice_supplier'])) {
			if (in_array($element, ['context1', 'context2'])) {
				$datacount = 0;

				$parameters['head'][$counter][0] = dol_buildpath('/dolistockreturn/dolistockreturn_tab.php', 1) . '?id=' . $id . '&amp;module='.$element;
				$parameters['head'][$counter][1] = $langs->trans('DolistockreturnTab');
				if ($datacount > 0) {
					$parameters['head'][$counter][1] .= '<span class="badge marginleftonlyshort">' . $datacount . '</span>';
				}
				$parameters['head'][$counter][2] = 'dolistockreturnemails';
				$counter++;
			}
			if ($counter > 0 && (int) DOL_VERSION < 14) {  // @phpstan-ignore-line
				$this->results = $parameters['head'];
				// return 1 to replace standard code
				return 1;
			} else {
				// From V14 onwards, $parameters['head'] is modifiable by reference
				return 0;
			}
		} else {
			// Bad value for $parameters['mode']
			return -1;
		}
	}


	/**
	 * Overload the showLinkToObjectBlock function : add or replace array of object linkable
	 *
	 * @param	array<string,mixed>	$parameters		Hook metadata (context, etc...)
	 * @param	CommonObject		$object			The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function showLinkToObjectBlock($parameters, &$object, &$action, $hookmanager)
	{
		if (!class_exists('MyObject')) {
			return 0;
		}
		$myobject = new MyObject($object->db);
		$this->results = array('myobject@dolistockreturn' => array(
			'enabled' => isModEnabled('dolistockreturn'),
			'perms' => 1,
			'label' => 'LinkToMyObject',
			'sql' => "SELECT t.rowid, t.ref, t.ref as 'name' FROM " . $this->db->prefix() . $myobject->table_element. " as t "),);

		return 1;
	}
	/* Add other hook methods here... */
	/**
	 * Add confirmation form.
	 *
	 * @param array<string,mixed> $parameters Parameters
	 * @param CommonObject       $object Object
	 * @param string             $action Action
	 * @param HookManager        $hookmanager Hook manager
	 * @return int
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		if (!in_array($parameters['currentcontext'], array('invoicecard', 'invoicesuppliercard')) || !in_array($action, array('dolistockreturn', 'dolistockreturn_supplier'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			return 0;
		}

		$langs->load('dolistockreturn@dolistockreturn');

		if (!$user->hasRight('dolistockreturn', 'return', 'write')) {
			return 0;
		}

		$isSupplier = ($parameters['currentcontext'] == 'invoicesuppliercard');
		$service = new DoliStockReturnService($db);
		if ($isSupplier) {
			if (!$service->isEligibleSupplierCreditNote($object)) {
				setEventMessages($service->error, $service->errors, 'errors');
				return 0;
			}
		} else {
			if (!$service->isEligibleCreditNote($object)) {
				setEventMessages($service->error, $service->errors, 'errors');
				return 0;
			}
		}

		require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
		require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
		$form = new Form($db);
		$formproduct = new FormProduct($db);

		$defaultWarehouse = (int) getDolGlobalString($isSupplier ? 'DOLISTOCKRETURN_SUPPLIER_DEFAULT_WAREHOUSE' : 'DOLISTOCKRETURN_DEFAULT_WAREHOUSE');
		$selectedWarehouse = $defaultWarehouse > 0 ? $defaultWarehouse : '';

		$formquestion = array();
		$useSourceWarehouse = getDolGlobalInt($isSupplier ? 'DOLISTOCKRETURN_SUPPLIER_USE_SOURCE_WAREHOUSE' : 'DOLISTOCKRETURN_USE_SOURCE_WAREHOUSE');
		if ($useSourceWarehouse) {
			$formquestion[] = array(
				'type' => 'hidden',
				'name' => 'fk_entrepot',
				'value' => '0',
			);
			$formquestion[] = array(
				'type' => 'other',
				'name' => 'automatic_fk_entrepot_label',
				'label' => $langs->trans('DoliStockReturnWarehouse'),
				'value' => '<span class="opacitymedium">'.$langs->trans($isSupplier ? 'DoliStockReturnSupplierWarehouseAuto' : 'DoliStockReturnWarehouseAuto').'</span>',
			);
		} else {
			$formquestion[] = array(
				'type' => 'other',
				'name' => 'fk_entrepot',
				'label' => $langs->trans('DoliStockReturnWarehouse'),
				'value' => $formproduct->selectWarehouses($selectedWarehouse, 'fk_entrepot', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth300'),
			);
		}

		if ($useSourceWarehouse) {
			$formquestion[] = array(
				'type' => 'other',
				'name' => 'manual_fk_entrepot',
				'label' => $langs->trans('DoliStockReturnManualWarehouse'),
				'value' => $formproduct->selectWarehouses($selectedWarehouse, 'manual_fk_entrepot', 'warehouseopen,warehouseinternal', 1, 0, 0, '', 0, 0, array(), 'minwidth300'),
			);
		}

		$text = $langs->trans($isSupplier ? 'DoliStockReturnSupplierConfirmText' : 'DoliStockReturnConfirmText');
		$url = $_SERVER['PHP_SELF'].'?facid='.(int) $object->id;
		$this->resprints = $form->formconfirm($url, $langs->trans($isSupplier ? 'DoliStockReturnSupplierConfirmTitle' : 'DoliStockReturnConfirmTitle'), $text, $isSupplier ? 'confirm_dolistockreturn_supplier' : 'confirm_dolistockreturn', $formquestion, 'yes', 1);
		return 1;
	}

	/**
	 * Add action buttons.
	 *
	 * @param array<string,mixed> $parameters Parameters
	 * @param CommonObject       $object Object
	 * @param string             $action Action
	 * @param HookManager        $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;
		
		//Si on est en dehors des contextes facture ou facture fournisseur, ou que l'action n'est pas celle de retour de stock, on ne fait rien
		// il faut aussi que le statut de la facture avoir (2) soit celui de facture avoir validée et non brouillon pour que le bouton puisse s'afficher
		if (!in_array($parameters['currentcontext'], array('invoicecard', 'invoicesuppliercard'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			return 0;
		}

		if ($object->type != 2 || $object->statut == 0) { // si ce n'est pas une facture d'avoir ou si elle est en brouillon, on ne fait rien
			return 0;
		}

		$langs->load('dolistockreturn@dolistockreturn');

		$isSupplier = ($parameters['currentcontext'] == 'invoicesuppliercard');

		$enableConst = $isSupplier ? 'DOLISTOCKRETURN_ENABLE_SUPPLIER_BUTTON' : 'DOLISTOCKRETURN_ENABLE_BUTTON';
		$returnedType = $isSupplier ? 'supplier_credit_note' : 'customer_credit_note';
		$returnedLabel = $isSupplier ? 'DoliStockReturnSupplierReturnedBadge' : 'DoliStockReturnReturnedBadge';
		$buttonLabel = $isSupplier ? 'DoliStockReturnSupplier' : 'DoliStockReturn';
		$buttonAction = $isSupplier ? 'dolistockreturn_supplier' : 'dolistockreturn';

		if (!getDolGlobalInt($enableConst) || !$user->hasRight('dolistockreturn', 'return', 'write')) {
			return 0;
		}

		$service = new DoliStockReturnService($db);
		
		if ($service->hasAlreadyReturned((int) $object->id, $returnedType)) {
			print '<span class="badge badge-status4 marginleftonly">'.$langs->trans($returnedLabel).'</span>';
			return 0;
		}

		$isEligible = $isSupplier ? $service->isEligibleSupplierCreditNote($object) : $service->isEligibleCreditNote($object);
		
		if (!$isEligible) {
			setEventMessages($service->error, $service->errors, 'errors');
			return 0;
		}

		$url = $_SERVER['PHP_SELF'].'?facid='.(int) $object->id.'&action='.$buttonAction.'&token='.newToken();

		print dolGetButtonAction($langs->trans($buttonLabel), $langs->trans($buttonLabel), 'default', $url, '', true);

		return 0;
	}
}
