CREATE TABLE IF NOT EXISTS `llx_dolistockreturn_return` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `entity` int(11) NOT NULL DEFAULT 1,
  `fk_credit_note` int(11) NOT NULL,
  `fk_source_invoice` int(11) NOT NULL,
  `fk_entrepot` int(11) DEFAULT NULL,
  `warehouse_mode` varchar(20) NOT NULL DEFAULT 'manual',
  `status` smallint(6) NOT NULL DEFAULT 1,
  `date_create` datetime NOT NULL,
  `fk_user_create` int(11) NOT NULL,
  `note_private` text,
  PRIMARY KEY (`rowid`),
  UNIQUE KEY `uk_dolistockreturn_credit_note` (`fk_credit_note`, `entity`),
  KEY `idx_dolistockreturn_source_invoice` (`fk_source_invoice`),
  KEY `idx_dolistockreturn_entrepot` (`fk_entrepot`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
