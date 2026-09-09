<?php

class CreateInventoryItemPhotos extends \SLiMS\Migration\Migration
{
    public function up()
    {
        \SLiMS\DB::getInstance()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS inventory_item_photos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id INT UNSIGNED NOT NULL,
    filename VARCHAR(68) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY inventory_item_photos_item_index (item_id),
    CONSTRAINT inventory_item_photos_item_fk FOREIGN KEY (item_id)
        REFERENCES inventory_items (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        \SLiMS\DB::getInstance()->exec('DROP TABLE IF EXISTS inventory_item_photos');
    }
}
