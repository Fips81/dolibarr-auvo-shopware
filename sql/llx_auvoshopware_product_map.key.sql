-- Copyright (C) 2026 AuVo Design
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

ALTER TABLE llx_auvoshopware_product_map ADD UNIQUE INDEX uk_auvoshopware_product_map_product (fk_product, entity);
ALTER TABLE llx_auvoshopware_product_map ADD INDEX idx_auvoshopware_product_map_sw_product_id (sw_product_id);
