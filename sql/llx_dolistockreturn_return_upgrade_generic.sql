ALTER TABLE `llx_dolistockreturn_return` ADD COLUMN IF NOT EXISTS `object_type` varchar(32) NOT NULL DEFAULT 'customer_credit_note' AFTER `entity`;
ALTER TABLE `llx_dolistockreturn_return` ADD COLUMN IF NOT EXISTS `direction` varchar(8) NOT NULL DEFAULT 'in' AFTER `object_type`;
ALTER TABLE `llx_dolistockreturn_return` DROP INDEX IF EXISTS `uk_dolistockreturn_credit_note`;
ALTER TABLE `llx_dolistockreturn_return` ADD UNIQUE KEY IF NOT EXISTS `uk_dolistockreturn_credit_note` (`object_type`, `fk_credit_note`, `entity`);
