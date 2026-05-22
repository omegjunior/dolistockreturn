CREATE TABLE IF NOT EXISTS `llx_dolistockreturn_returndet` (
  `rowid` int(11) NOT NULL AUTO_INCREMENT,
  `fk_return` int(11) NOT NULL,
  `fk_credit_note_line` int(11) NOT NULL,
  `fk_source_invoice_line` int(11) DEFAULT NULL,
  `fk_product` int(11) NOT NULL,
  `fk_entrepot` int(11) NOT NULL,
  `qty` double(24,8) NOT NULL,
  `fk_stock_mouvement` int(11) DEFAULT NULL,
  `batch` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`rowid`),
  KEY `idx_dolistockreturn_returndet_return` (`fk_return`),
  KEY `idx_dolistockreturn_returndet_product` (`fk_product`),
  KEY `idx_dolistockreturn_returndet_movement` (`fk_stock_mouvement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
