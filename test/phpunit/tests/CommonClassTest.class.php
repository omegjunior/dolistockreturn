<?php
/* Copyright (C) 2026 DoliStockReturn Module Contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	$_SERVER['PHP_SELF'] = 'phpunit';
}

global $conf, $user, $langs, $db;
require_once __DIR__.'/../../../../../master.inc.php';

@unlink(DOL_DATA_ROOT.'/dolibarr.log');

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

use PHPUnit\Framework\TestCase;

/**
 * Shared Dolibarr PHPUnit base for module functional tests.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 */
abstract class CommonClassTest extends TestCase
{
	protected $savconf;
	protected $savuser;
	protected $savlangs;
	protected $savdb;

	public function __construct($name = null, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);

		global $conf, $user, $langs, $db;
		$this->savconf = $conf;
		$this->savuser = $user;
		$this->savlangs = $langs;
		$this->savdb = $db;
	}

	public static function setUpBeforeClass(): void
	{
		global $db;
		$db->begin();
	}

	protected function setUp(): void
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;
	}

	public static function tearDownAfterClass(): void
	{
		global $db;
		$db->rollback();
	}
}
