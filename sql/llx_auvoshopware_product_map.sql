-- Copyright (C) 2026 AuVo Design
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

-- Mapping between a Dolibarr product and its counterpart in Shopware,
-- plus the status of the last synchronization attempt.
CREATE TABLE llx_auvoshopware_product_map (
	rowid				integer AUTO_INCREMENT PRIMARY KEY,
	fk_product			integer NOT NULL,
	sw_product_id		varchar(32) DEFAULT NULL,
	content_hash		varchar(64) DEFAULT NULL,
	last_sync_date		datetime DEFAULT NULL,
	last_sync_status	varchar(10) DEFAULT NULL,
	last_sync_error		text DEFAULT NULL,
	entity				integer DEFAULT 1 NOT NULL
) ENGINE=innodb;
