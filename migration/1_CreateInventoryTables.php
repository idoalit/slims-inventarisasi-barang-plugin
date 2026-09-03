<?php

use SLiMS\DB;

class CreateInventoryTables extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `inventory_locations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_code` VARCHAR(100) NULL,
  `room_name` VARCHAR(255) NOT NULL,
  `province` VARCHAR(150) NOT NULL DEFAULT '',
  `regency_city` VARCHAR(150) NOT NULL DEFAULT '',
  `unit_name` VARCHAR(255) NOT NULL DEFAULT 'PERPUSTAKAAN',
  `work_unit` VARCHAR(255) NOT NULL DEFAULT '',
  `signature_city` VARCHAR(150) NOT NULL DEFAULT '',
  `knowing_title` VARCHAR(255) NOT NULL DEFAULT '',
  `knowing_name` VARCHAR(255) NOT NULL DEFAULT '',
  `knowing_identity` VARCHAR(100) NOT NULL DEFAULT '',
  `manager_title` VARCHAR(255) NOT NULL DEFAULT 'Pengurus Barang Inventaris',
  `manager_name` VARCHAR(255) NOT NULL DEFAULT '',
  `manager_identity` VARCHAR(100) NOT NULL DEFAULT '',
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_locations_code_unique` (`location_code`),
  KEY `inventory_locations_room_index` (`room_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `inventory_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `location_id` INT UNSIGNED NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `brand_model` VARCHAR(255) NOT NULL DEFAULT '',
  `serial_number` VARCHAR(255) NOT NULL DEFAULT '',
  `item_size` VARCHAR(150) NOT NULL DEFAULT '',
  `material` VARCHAR(150) NOT NULL DEFAULT '',
  `acquisition_year` SMALLINT UNSIGNED NULL,
  `item_code` VARCHAR(150) NOT NULL DEFAULT '',
  `quantity_register` VARCHAR(150) NOT NULL DEFAULT '',
  `acquisition_price` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `item_condition` ENUM('B','KB','RB') NOT NULL DEFAULT 'B',
  `notes` TEXT NULL,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_items_location_index` (`location_id`),
  KEY `inventory_items_code_index` (`item_code`),
  KEY `inventory_items_name_index` (`item_name`),
  CONSTRAINT `inventory_items_location_fk`
    FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down()
    {
        $db = DB::getInstance();
        $db->exec('DROP TABLE IF EXISTS `inventory_items`');
        $db->exec('DROP TABLE IF EXISTS `inventory_locations`');
    }
}
