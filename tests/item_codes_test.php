<?php
// Uses uniquely named disposable tables; never reads or modifies application data.
declare(strict_types=1);
namespace SLiMS {
    class DB {
        public static \PDO $connection;
        public static function getInstance(): \PDO { return self::$connection; }
    }
}
namespace {
    use SLiMS\Plugins\Inventory\ItemCodes;
    require __DIR__ . '/../src/ItemCodes.php';
    require dirname(__DIR__, 3) . '/lib/Migration/Migration.php';
    require __DIR__ . '/../migration/6_CreateInventoryItemCodes.php';
    function check(bool $ok, string $label): void {
        if (!$ok) throw new RuntimeException($label);
        echo "ok   $label\n";
    }
    check(ItemCodes::increment('999999') === '1000000', 'six-digit rollover');
    check(ItemCodes::increment('99999999999999999999') === '100000000000000000000', 'numbers beyond machine integer');
    if (!getenv('INVENTORY_TEST_DSN')) {
        fwrite(STDERR, "Set INVENTORY_TEST_DSN, INVENTORY_TEST_USER and INVENTORY_TEST_PASSWORD for MySQL integration checks.\n");
        exit(1);
    }
    $prefix = getenv('INVENTORY_CODE_TEST_PREFIX') ?: 'ic_test_' . bin2hex(random_bytes(6)) . '_';
    if (!preg_match('/\Aic_test_[a-f0-9]{12}_\z/', $prefix)) throw new RuntimeException('Invalid test prefix');
    $tables = ['inventory_item_code_sequences', 'inventory_item_code_reservations', 'inventory_locations', 'inventory_items', 'mst_location'];
    class TestConnection extends PDO {
        public string $prefix;
        public array $tables;
        private function sql(string $sql): string {
            foreach ($this->tables as $name) $sql = preg_replace('/\b' . $name . '\b/', $this->prefix . $name, $sql);
            return $sql;
        }
        public function exec(string $statement): int|false { return parent::exec($this->sql($statement)); }
        public function prepare(string $query, array $options = []): \PDOStatement|false { return parent::prepare($this->sql($query), $options); }
        public function query(string $query, ?int $fetchMode = null, mixed ...$args): \PDOStatement|false {
            return $fetchMode === null ? parent::query($this->sql($query)) : parent::query($this->sql($query), $fetchMode, ...$args);
        }
    }
    $db = new TestConnection(getenv('INVENTORY_TEST_DSN'), getenv('INVENTORY_TEST_USER'), getenv('INVENTORY_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->prefix = $prefix;
    $db->tables = $tables;
    \SLiMS\DB::$connection = $db;
    $owner = ItemCodes::owner(str_repeat('a', 64), 'session-a');
    $other = ItemCodes::owner(str_repeat('b', 64), 'session-b');
    $reserve = function(int $room, string $who) use ($db): string {
        $db->beginTransaction();
        try { $code = ItemCodes::reserve($db, $room, $who); $db->commit(); return $code; }
        catch (Throwable $error) { $db->rollBack(); throw $error; }
    };
    if (in_array('--worker', $argv, true)) {
        file_put_contents(sys_get_temp_dir() . '/' . $prefix . 'ready', 'ready');
        echo $reserve(1, $other);
        exit;
    }
    $reject = function(callable $operation, string $label) use ($db): void {
        $db->beginTransaction();
        ItemCodes::lock($db);
        try { $operation(); } catch (RuntimeException $e) { $db->rollBack(); check(true, $label); return; }
        $db->rollBack();
        throw new RuntimeException('Expected rejection: ' . $label);
    };
    try {
        $db->exec('CREATE TABLE mst_location (location_id VARCHAR(3) PRIMARY KEY) ENGINE=InnoDB');
        $db->exec('CREATE TABLE inventory_locations (id INT PRIMARY KEY, slims_location_id VARCHAR(3), location_code VARCHAR(100)) ENGINE=InnoDB');
        $db->exec("CREATE TABLE inventory_items (id INT PRIMARY KEY AUTO_INCREMENT, item_code VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("INSERT INTO mst_location VALUES ('P01'), ('P02')");
        $db->exec("INSERT INTO inventory_locations VALUES (1,'P01','CARD'),(2,'P01','OTHER'),(3,'P02','CARD'),(4,NULL,'CARD'),(5,'BAD','CARD')");
        $db->exec("INSERT INTO inventory_items (item_code) VALUES ('P01-INV-000009'),('LEGACY'),('LEGACY'),('')");
        (new CreateInventoryItemCodes())->up();
        check($db->query('SELECT COUNT(*) FROM inventory_items')->fetchColumn() == 4, 'migration preserves old items');
        $code = $reserve(1, $owner);
        check($code === 'P01-INV-000010', 'master prefix and continuation of old codes');
        check($reserve(2, $owner) === 'P01-INV-000011', 'rooms share sequence; abandoned number retained');
        check($reserve(3, $owner) === 'P02-INV-000001', 'independent library sequence');
        foreach ([4, 5, 999] as $room) $reject(fn() => ItemCodes::reserve($db, $room, $owner), 'missing or invalid master rejected: ' . $room);
        $reject(fn() => ItemCodes::validateAndConsume($db, $code, null, $other), 'other form cannot consume reservation');
        $reject(fn() => ItemCodes::validateAndConsume($db, 'LEGACY', null, $owner), 'manual duplicate rejected');
        $db->beginTransaction(); ItemCodes::lock($db);
        ItemCodes::validateAndConsume($db, 'LEGACY', 'LEGACY', $owner);
        ItemCodes::validateAndConsume($db, '', null, $owner);
        ItemCodes::validateAndConsume($db, 'MANUAL', null, $owner);
        ItemCodes::validateAndConsume($db, $code, null, $owner);
        $db->rollBack();
        check($db->query("SELECT used_at FROM inventory_item_code_reservations WHERE code = '$code'")->fetchColumn() === null, 'failed save rolls consumption back');
        $db->beginTransaction(); ItemCodes::lock($db);
        ItemCodes::validateAndConsume($db, $code, null, $owner);
        $db->prepare('INSERT INTO inventory_items (item_code) VALUES (?)')->execute([$code]);
        $db->commit();
        $db->beginTransaction(); ItemCodes::lock($db);
        ItemCodes::validateAndConsume($db, $code, $code, $other);
        $db->commit();
        check(true, 'retry saves exact reserved code; later edits preserve it');
        $db->prepare('DELETE FROM inventory_items WHERE item_code = ?')->execute([$code]);
        $reject(fn() => ItemCodes::validateAndConsume($db, $code, null, $owner), 'deleted issued number cannot be reused');
        $db->exec("INSERT INTO inventory_items (item_code) VALUES ('P01-INV-000012')");
        check($reserve(1, $owner) === 'P01-INV-000013', 'allocator skips manual collision');
        $db->exec("UPDATE inventory_item_code_sequences SET last_number = '999999' WHERE prefix = 'P02'");
        check($reserve(3, $owner) === 'P02-INV-1000000', 'stored sequence grows beyond six digits');
        // Separate PHP process/connection must wait on the same database mutex.
        putenv('INVENTORY_CODE_TEST_PREFIX=' . $prefix);
        $db->beginTransaction(); ItemCodes::lock($db);
        $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Worker unavailable');
        fclose($pipes[0]);
        $ready = sys_get_temp_dir() . '/' . $prefix . 'ready';
        $deadline = microtime(true) + 10;
        while (!file_exists($ready) && microtime(true) < $deadline) usleep(10000);
        check(file_exists($ready), 'second connection ready');
        usleep(200000);
        check(proc_get_status($process)['running'], 'second allocator blocks behind transaction');
        $first = ItemCodes::reserve($db, 1, $owner);
        $db->commit();
        $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $status = proc_close($process);
        check($status === 0 && $errors === '' && $first === 'P01-INV-000014' && str_ends_with($output, 'P01-INV-000015'), 'concurrent allocations issue distinct consecutive codes');
        unlink($ready);
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($tables as $table) $db->exec('DROP TABLE IF EXISTS ' . $table);
    }
}
