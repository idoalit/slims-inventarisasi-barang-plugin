<?php

class MoveInventoryPhotosToFiles extends \SLiMS\Migration\Migration
{
    public function up()
    {
        require_once dirname(__DIR__) . '/src/ItemPhotos.php';
        require_once dirname(__DIR__) . '/src/PhotoStorage.php';
        $db = \SLiMS\DB::getInstance();
        $columns = $db->query('SHOW COLUMNS FROM inventory_item_photos')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('image_data', $columns, true)) return;
        if (!in_array('filename', $columns, true)) {
            $db->exec('ALTER TABLE inventory_item_photos ADD filename VARCHAR(68) NULL');
        }
        $storage = new \SLiMS\Plugins\Inventory\PhotoStorage();
        // Resume safely if an earlier migration attempt stopped partway through.
        $lastId = 0;
        while (true) {
            $db->beginTransaction();
            $filename = null;
            try {
                $row = $db->query('SELECT id, image_data FROM inventory_item_photos WHERE filename IS NULL AND id > ' . (int) $lastId . ' ORDER BY id LIMIT 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
                if (!$row) { $db->commit(); break; }
                $lastId = (int) $row['id'];
                try {
                    $jpeg = \SLiMS\Plugins\Inventory\ItemPhotos::normalize($row['image_data']);
                } catch (RuntimeException $error) {
                    // Preserve unreadable legacy bytes for recovery; do not block new uploads.
                    error_log('Inventory legacy photo #' . $lastId . ' retained: ' . $error->getMessage());
                    $db->commit();
                    continue;
                }
                $filename = $storage->write($jpeg);
                $update = $db->prepare('UPDATE inventory_item_photos SET filename = ? WHERE id = ?');
                $update->execute([$filename, $row['id']]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                if ($filename) $storage->cleanup([$filename]);
                throw $error;
            }
        }
        $unconverted = (int) $db->query('SELECT COUNT(*) FROM inventory_item_photos WHERE filename IS NULL')->fetchColumn();
        if ($unconverted > 0) {
            $db->exec('ALTER TABLE inventory_item_photos MODIFY image_data MEDIUMBLOB NULL');
        } else {
            $db->exec('ALTER TABLE inventory_item_photos MODIFY filename VARCHAR(68) NOT NULL, DROP COLUMN image_data');
        }
    }

    public function down()
    {
        throw new RuntimeException('Pemindahan foto ke berkas tidak dibalik otomatis. Pulihkan database dan folder foto dari cadangan yang sama.');
    }
}
