-- Copyright (C) 2026 AuVo Design
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- Files prefixed "update" (unlike "dolibarr_allversions.sql") are the ones _load_tables()
-- actually picks up and runs on every module activation, tolerating "already exists" errors.

ALTER TABLE llx_auvoshopware_product_map ADD COLUMN sw_images text DEFAULT NULL;
