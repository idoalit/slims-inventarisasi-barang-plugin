<?php

use SLiMS\DB;

class CreateInventoryItemCodes extends \SLiMS\Migration\Migration
{
    public function up()
    {
        $db = DB::getInstance();
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_item_code_sequences (
            prefix VARCHAR(3) NOT NULL PRIMARY KEY,
            last_number VARCHAR(150) NOT NULL DEFAULT '0'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Empty prefix is reserved for the common allocator/save mutex.
        $db->exec("INSERT IGNORE INTO inventory_item_code_sequences (prefix) VALUES ('')");
        $db->exec("CREATE TABLE IF NOT EXISTS inventory_item_code_reservations (
            code VARCHAR(150) NOT NULL PRIMARY KEY,
            owner_hash CHAR(64) NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down()
    {
        throw new \RuntimeException('Reservasi kode harus dipertahankan agar nomor tidak digunakan ulang. Rollback versi 6 tidak tersedia.');
    }
}
