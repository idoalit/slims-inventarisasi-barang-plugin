<?php

use SLiMS\DB;

class AllowSharedInventoryLocationCodes extends \SLiMS\Migration\Migration
{
    public function up()
    {
        // Each room has its own primary key; a card location code may be shared.
        DB::getInstance()->exec(
            'ALTER TABLE `inventory_locations`
             DROP INDEX `inventory_locations_code_unique`,
             ADD INDEX `inventory_locations_code_index` (`location_code`)'
        );
    }

    public function down()
    {
        // If shared codes exist, MySQL rejects this atomically without removing data.
        DB::getInstance()->exec(
            'ALTER TABLE `inventory_locations`
             DROP INDEX `inventory_locations_code_index`,
             ADD UNIQUE INDEX `inventory_locations_code_unique` (`location_code`)'
        );
    }
}
