-- Copyright (C) 2026 AuVo Design
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

-- Tracks which Shopware orders have already been imported as Dolibarr sales orders (Commande),
-- so fetchNewOrders() doesn't import the same order twice.
CREATE TABLE llx_auvoshopware_order_map (
	rowid				integer AUTO_INCREMENT PRIMARY KEY,
	sw_order_id			varchar(32) NOT NULL,
	fk_commande			integer NOT NULL,
	sw_order_number		varchar(32) DEFAULT NULL,
	last_sync_date		datetime DEFAULT NULL,
	last_sync_status	varchar(10) DEFAULT NULL,
	last_sync_error		text DEFAULT NULL,
	entity				integer DEFAULT 1 NOT NULL
) ENGINE=innodb;
