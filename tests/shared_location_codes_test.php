<?php

declare(strict_types=1);

namespace SLiMS {
    class DB
    {
        public static \PDO $connection;

        public static function getInstance(): \PDO
        {
            return self::$connection;
        }
    }
}

namespace {
    require_once dirname(__DIR__, 3) . '/lib/Migration/Migration.php';
    require_once __DIR__ . '/../migration/1_CreateInventoryTables.php';
    require_once __DIR__ . '/../migration/2_AddSlimsLocationToInventoryLocations.php';
    require_once __DIR__ . '/../migration/5_AllowSharedInventoryLocationCodes.php';

    if (!getenv('INVENTORY_TEST_DSN')) {
        fwrite(STDERR, "Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER and INVENTORY_TEST_PASSWORD for a MySQL test database.\n");
        exit(1);
    }

    // Temporary tables shadow permanent tables only within this connection.
    $db = new class(getenv('INVENTORY_TEST_DSN'), getenv('INVENTORY_TEST_USER'), getenv('INVENTORY_TEST_PASSWORD')) extends PDO {
        public function exec(string $statement): int|false
        {
            if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS `inventory_items`')) {
                return 0; // MySQL temporary tables do not support foreign keys.
            }
            return parent::exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $statement));
        }
    };
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \SLiMS\DB::$connection = $db;
    (new CreateInventoryTables())->up();
    (new AddSlimsLocationToInventoryLocations())->up();
    $insert = $db->prepare('INSERT INTO inventory_locations (slims_location_id, location_code, room_name, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())');
    $insert->execute(['001', 'KARTU-01', 'Ruang A']);
    $original = $db->query('SELECT * FROM inventory_locations')->fetch(PDO::FETCH_ASSOC);
    $duplicateRejected = false;
    try {
        $insert->execute(['001', 'KARTU-01', 'Ruang B']);
    } catch (PDOException $error) {
        if ((int) $error->errorInfo[1] !== 1062) throw $error;
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) throw new RuntimeException('Old schema did not reproduce duplicate-code failure.');
    (new AllowSharedInventoryLocationCodes())->up();
    if ($db->query('SELECT * FROM inventory_locations')->fetch(PDO::FETCH_ASSOC) !== $original) {
        throw new RuntimeException('Migration changed existing room data.');
    }
    $insert->execute(['001', 'KARTU-01', 'Ruang B']);
    $secondId = (int) $db->lastInsertId();
    $update = $db->prepare('UPDATE inventory_locations SET room_name = ? WHERE id = ?');
    $update->execute(['Ruang B diperbarui', $secondId]);
    $rooms = $db->query('SELECT room_name FROM inventory_locations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($rooms !== ['Ruang A', 'Ruang B diperbarui']) throw new RuntimeException('Rooms were not saved independently.');
    $insert->execute(['001', null, 'Ruang C']);
    $insert->execute(['001', null, 'Ruang D']);
    echo "ok   old duplicate-code failure reproduced\nok   migration preserved existing data\nok   shared location and card codes accepted\nok   rooms updated independently\nok   empty card codes accepted\n";
}
