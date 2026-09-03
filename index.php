<?php

defined('INDEX_AUTH') || die('Direct access not allowed!');

require LIB . 'ip_based_access.inc.php';
do_checkIP('smc');
do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';

$canRead = utility::havePrivilege('stock_take', 'r');
$canWrite = utility::havePrivilege('stock_take', 'w');
if (!$canRead) {
    die('<div class="errorBox">' . __('You are not authorized to view this section') . '</div>');
}

$db = \SLiMS\DB::getInstance();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function inventory_e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function inventory_url(array $params = []): string
{
    $base = [];
    foreach (['mod', 'id'] as $key) {
        if (isset($_GET[$key])) {
            $base[$key] = (string) $_GET[$key];
        }
    }
    return $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($base, $params));
}

function inventory_post(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $default));
}

function inventory_log(string $recordId, string $message, string $action): void
{
    try {
        $suffix = $recordId === '' ? '' : ' Rekaman #' . $recordId . '.';
        writeLog('staff', (string) ($_SESSION['uid'] ?? '0'), 'Inventaris Barang', $message . $suffix, 'stock_take', $action);
    } catch (Throwable $exception) {
        error_log('Inventory audit log error: ' . $exception->getMessage());
    }
}

if (empty($_SESSION['inventory_csrf'])) {
    $_SESSION['inventory_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['inventory_csrf'];
$message = '';
$messageType = 'success';
$locations = [];
$masterLocations = [];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$canWrite) {
            inventory_log('', 'Upaya perubahan inventaris ditolak: hak tulis tidak tersedia.', 'Denied');
            throw new RuntimeException('Anda tidak memiliki hak untuk mengubah data inventaris.');
        }
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            inventory_log('', 'Upaya perubahan inventaris ditolak: token CSRF tidak valid.', 'Denied');
            throw new RuntimeException('Token formulir tidak valid. Muat ulang halaman lalu coba lagi.');
        }

        $postAction = inventory_post('form_action');
        $now = date('Y-m-d H:i:s');
        $uid = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;

        if ($postAction === 'save_location') {
            $id = (int) ($_POST['record_id'] ?? 0);
            $roomName = inventory_post('room_name');
            if ($roomName === '') {
                throw new RuntimeException('Nama ruangan wajib diisi.');
            }
            $slimsLocationId = inventory_post('slims_location_id');
            if (strlen($slimsLocationId) > 3) {
                throw new RuntimeException('Kode lokasi master SLiMS tidak valid.');
            }
            if ($slimsLocationId !== '') {
                $masterLocationStatement = $db->prepare('SELECT 1 FROM mst_location WHERE location_id = ? LIMIT 1');
                $masterLocationStatement->execute([$slimsLocationId]);
                if (!$masterLocationStatement->fetchColumn()) {
                    throw new RuntimeException('Lokasi master SLiMS yang dipilih tidak ditemukan.');
                }
            }

            $values = [
                'slims_location_id' => $slimsLocationId === '' ? null : $slimsLocationId,
                'location_code' => inventory_post('location_code') ?: null,
                'room_name' => $roomName,
                'province' => inventory_post('province'),
                'regency_city' => inventory_post('regency_city'),
                'unit_name' => inventory_post('unit_name', 'PERPUSTAKAAN'),
                'work_unit' => inventory_post('work_unit'),
                'signature_city' => inventory_post('signature_city'),
                'knowing_title' => inventory_post('knowing_title'),
                'knowing_name' => inventory_post('knowing_name'),
                'knowing_identity' => inventory_post('knowing_identity'),
                'manager_title' => inventory_post('manager_title', 'Pengurus Barang Inventaris'),
                'manager_name' => inventory_post('manager_name'),
                'manager_identity' => inventory_post('manager_identity'),
                'updated_at' => $now,
            ];

            if ($id > 0) {
                $values['id'] = $id;
                $statement = $db->prepare(
                    'UPDATE inventory_locations SET slims_location_id=:slims_location_id, location_code=:location_code, room_name=:room_name, province=:province,
                     regency_city=:regency_city, unit_name=:unit_name, work_unit=:work_unit, signature_city=:signature_city,
                     knowing_title=:knowing_title, knowing_name=:knowing_name, knowing_identity=:knowing_identity,
                     manager_title=:manager_title, manager_name=:manager_name, manager_identity=:manager_identity,
                     updated_at=:updated_at WHERE id=:id'
                );
                $statement->execute($values);
                inventory_log((string) $id, 'Lokasi inventaris diperbarui.', 'Update');
                $message = 'Lokasi berhasil diperbarui.';
            } else {
                $values['created_by'] = $uid;
                $values['created_at'] = $now;
                $statement = $db->prepare(
                    'INSERT INTO inventory_locations
                     (slims_location_id, location_code, room_name, province, regency_city, unit_name, work_unit, signature_city,
                      knowing_title, knowing_name, knowing_identity, manager_title, manager_name, manager_identity,
                      created_by, created_at, updated_at)
                     VALUES (:slims_location_id, :location_code, :room_name, :province, :regency_city, :unit_name, :work_unit, :signature_city,
                      :knowing_title, :knowing_name, :knowing_identity, :manager_title, :manager_name, :manager_identity,
                      :created_by, :created_at, :updated_at)'
                );
                $statement->execute($values);
                $id = (int) $db->lastInsertId();
                inventory_log((string) $id, 'Lokasi inventaris ditambahkan.', 'Create');
                $message = 'Lokasi berhasil ditambahkan.';
            }
        } elseif ($postAction === 'save_item') {
            $id = (int) ($_POST['record_id'] ?? 0);
            $locationId = (int) ($_POST['location_id'] ?? 0);
            $itemName = inventory_post('item_name');
            if ($locationId < 1 || $itemName === '') {
                throw new RuntimeException('Lokasi dan nama barang wajib diisi.');
            }
            $condition = inventory_post('item_condition', 'B');
            if (!in_array($condition, ['B', 'KB', 'RB'], true)) {
                throw new RuntimeException('Kondisi barang tidak valid.');
            }
            $yearText = inventory_post('acquisition_year');
            $year = $yearText === '' ? null : (int) $yearText;
            if ($year !== null && ($year < 1000 || $year > ((int) date('Y') + 1))) {
                throw new RuntimeException('Tahun pembuatan/pembelian tidak valid.');
            }
            $priceText = inventory_post('acquisition_price', '0');
            if (!is_numeric($priceText) || (float) $priceText < 0 || (float) $priceText > 9999999999999999.99) {
                throw new RuntimeException('Harga perolehan tidak valid.');
            }
            if (strlen(inventory_post('notes')) > 5000) {
                throw new RuntimeException('Keterangan maksimal 5.000 karakter.');
            }

            $values = [
                'location_id' => $locationId,
                'item_name' => $itemName,
                'brand_model' => inventory_post('brand_model'),
                'serial_number' => inventory_post('serial_number'),
                'item_size' => inventory_post('item_size'),
                'material' => inventory_post('material'),
                'acquisition_year' => $year,
                'item_code' => inventory_post('item_code'),
                'quantity_register' => inventory_post('quantity_register'),
                'acquisition_price' => (float) $priceText,
                'item_condition' => $condition,
                'notes' => inventory_post('notes'),
                'updated_at' => $now,
            ];

            if ($id > 0) {
                $values['id'] = $id;
                $statement = $db->prepare(
                    'UPDATE inventory_items SET location_id=:location_id, item_name=:item_name, brand_model=:brand_model,
                     serial_number=:serial_number, item_size=:item_size, material=:material,
                     acquisition_year=:acquisition_year, item_code=:item_code, quantity_register=:quantity_register,
                     acquisition_price=:acquisition_price, item_condition=:item_condition, notes=:notes,
                     updated_at=:updated_at WHERE id=:id'
                );
                $statement->execute($values);
                inventory_log((string) $id, 'Barang inventaris diperbarui.', 'Update');
                $message = 'Barang inventaris berhasil diperbarui.';
            } else {
                $values['created_by'] = $uid;
                $values['created_at'] = $now;
                $statement = $db->prepare(
                    'INSERT INTO inventory_items
                     (location_id, item_name, brand_model, serial_number, item_size, material, acquisition_year,
                      item_code, quantity_register, acquisition_price, item_condition, notes, created_by, created_at, updated_at)
                     VALUES (:location_id, :item_name, :brand_model, :serial_number, :item_size, :material, :acquisition_year,
                      :item_code, :quantity_register, :acquisition_price, :item_condition, :notes, :created_by, :created_at, :updated_at)'
                );
                $statement->execute($values);
                $id = (int) $db->lastInsertId();
                inventory_log((string) $id, 'Barang inventaris ditambahkan pada lokasi #' . $locationId . '.', 'Create');
                $message = 'Barang inventaris berhasil ditambahkan.';
            }
        } elseif ($postAction === 'delete_item') {
            $id = (int) ($_POST['record_id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Barang inventaris tidak valid.');
            }
            $statement = $db->prepare('DELETE FROM inventory_items WHERE id = ?');
            $statement->execute([$id]);
            inventory_log((string) $id, 'Barang inventaris dihapus.', 'Delete');
            $message = 'Barang inventaris berhasil dihapus.';
        } elseif ($postAction === 'delete_location') {
            $id = (int) ($_POST['record_id'] ?? 0);
            if ($id < 1) {
                throw new RuntimeException('Lokasi inventaris tidak valid.');
            }
            $statement = $db->prepare('DELETE FROM inventory_locations WHERE id = ?');
            $statement->execute([$id]);
            inventory_log((string) $id, 'Lokasi inventaris beserta barang terkait dihapus.', 'Delete');
            $message = 'Lokasi dan seluruh barang di dalamnya berhasil dihapus.';
        } else {
            inventory_log('', 'Upaya perubahan inventaris ditolak: aksi tidak dikenal.', 'Denied');
            throw new RuntimeException('Aksi formulir tidak valid.');
        }
    }

    $masterLocations = $db->query(
        'SELECT location_id, location_name FROM mst_location ORDER BY location_name, location_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $locations = $db->query(
        'SELECT l.*,
                (SELECT ml.location_name FROM mst_location ml WHERE ml.location_id = l.slims_location_id LIMIT 1) AS slims_location_name,
                COUNT(i.id) AS item_count
         FROM inventory_locations l LEFT JOIN inventory_items i ON i.location_id=l.id
         GROUP BY l.id ORDER BY l.room_name, l.location_code'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Inventory database error: ' . $exception->getMessage());
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $message = str_contains(strtolower($exception->getMessage()), 'doesn\'t exist')
        ? 'Tabel inventaris belum tersedia. Aktifkan plugin Inventaris Barang dari menu System → Plugins.'
        : 'Operasi database gagal. Pastikan kode lokasi tidak duplikat dan data yang dimasukkan valid.';
} catch (RuntimeException $exception) {
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $message = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Inventory application error: ' . $exception->getMessage());
    $locations = [];
    $masterLocations = [];
    $messageType = 'danger';
    $message = 'Terjadi kesalahan internal. Silakan coba lagi atau hubungi administrator.';
}

$action = (string) ($_GET['action'] ?? 'list');
$locationForm = [];
$itemForm = [];
if ($action === 'edit_location' && isset($_GET['record_id'])) {
    $statement = $db->prepare('SELECT * FROM inventory_locations WHERE id=?');
    $statement->execute([(int) $_GET['record_id']]);
    $locationForm = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
if ($action === 'edit_item' && isset($_GET['record_id'])) {
    $statement = $db->prepare('SELECT * FROM inventory_items WHERE id=?');
    $statement->execute([(int) $_GET['record_id']]);
    $itemForm = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}
if ($action === 'add_item' && isset($_GET['location_id'])) {
    $itemForm['location_id'] = (int) $_GET['location_id'];
}

$printBase = rtrim(SWB, '/') . '/plugins/inventaris-barang/print.php';
?>
<style>
    .inventory-toolbar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}.inventory-card{border:1px solid #ddd;border-radius:.35rem;margin:1rem 0;background:#fff}.inventory-card-header{padding:.75rem 1rem;border-bottom:1px solid #ddd;font-weight:700;background:#f7f7f7}.inventory-card-body{padding:1rem}.inventory-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}.inventory-grid .wide{grid-column:1/-1}.inventory-actions{display:flex;gap:.35rem;flex-wrap:wrap}.inventory-empty{padding:2rem;text-align:center;color:#777}@media(max-width:800px){.inventory-grid{grid-template-columns:1fr}}
</style>

<div class="menuBox">
    <div class="menuBoxInner printIcon">
        <div class="per_title"><h2>Inventaris Barang Perpustakaan</h2></div>
        <div class="infoBox">Catat barang berdasarkan lokasi/ruangan dan cetak Kartu Inventaris Ruangan dalam PDF.</div>
        <div class="sub_section inventory-toolbar">
            <a class="btn btn-default" href="<?= inventory_e(inventory_url()) ?>">Daftar Inventaris</a>
            <?php if ($canWrite): ?>
                <a class="btn btn-primary" href="<?= inventory_e(inventory_url(['action' => 'add_location'])) ?>">Tambah Lokasi</a>
                <?php if ($locations): ?><a class="btn btn-success" href="<?= inventory_e(inventory_url(['action' => 'add_item'])) ?>">Tambah Barang</a><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($message !== ''): ?><div class="alert alert-<?= inventory_e($messageType) ?> m-3"><?= inventory_e($message) ?></div><?php endif; ?>

<?php if (in_array($action, ['add_location', 'edit_location'], true) && $canWrite): ?>
    <?php
    $defaults = array_merge([
        'id' => 0, 'slims_location_id' => '', 'location_code' => '', 'room_name' => '', 'province' => 'JAWA TENGAH',
        'regency_city' => 'SEMARANG', 'unit_name' => 'PERPUSTAKAAN', 'work_unit' => '',
        'signature_city' => 'Semarang', 'knowing_title' => '', 'knowing_name' => '', 'knowing_identity' => '',
        'manager_title' => 'Pengurus Barang Inventaris', 'manager_name' => '', 'manager_identity' => '',
    ], $locationForm);
    ?>
    <div class="inventory-card"><div class="inventory-card-header"><?= $defaults['id'] ? 'Ubah Lokasi' : 'Tambah Lokasi' ?></div><div class="inventory-card-body">
        <form class="submitViaAJAX" method="post" action="<?= inventory_e(inventory_url()) ?>">
            <input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="save_location"><input type="hidden" name="record_id" value="<?= (int) $defaults['id'] ?>">
            <div class="inventory-grid">
                <div class="form-group"><label>Lokasi Master SLiMS</label><select class="form-control" name="slims_location_id"><option value="">Tidak ditentukan</option><?php foreach ($masterLocations as $masterLocation): ?><option value="<?= inventory_e($masterLocation['location_id']) ?>" <?= (string) $defaults['slims_location_id'] === (string) $masterLocation['location_id'] ? 'selected' : '' ?>><?= inventory_e($masterLocation['location_name'] . ' (' . $masterLocation['location_id'] . ')') ?></option><?php endforeach; ?></select><small class="form-text text-muted">Berasal dari master <code>mst_location</code> SLiMS.</small></div>
                <div class="form-group"><label>No. Kode Lokasi Kartu</label><input class="form-control" name="location_code" maxlength="100" value="<?= inventory_e($defaults['location_code']) ?>"></div>
                <div class="form-group"><label>Ruangan <span class="text-danger">*</span></label><input class="form-control" required name="room_name" maxlength="255" value="<?= inventory_e($defaults['room_name']) ?>"></div>
                <div class="form-group"><label>Provinsi</label><input class="form-control" name="province" maxlength="150" value="<?= inventory_e($defaults['province']) ?>"></div>
                <div class="form-group"><label>Kabupaten/Kota</label><input class="form-control" name="regency_city" maxlength="150" value="<?= inventory_e($defaults['regency_city']) ?>"></div>
                <div class="form-group"><label>Unit</label><input class="form-control" name="unit_name" maxlength="255" value="<?= inventory_e($defaults['unit_name']) ?>"></div>
                <div class="form-group"><label>Satuan Kerja</label><input class="form-control" name="work_unit" maxlength="255" value="<?= inventory_e($defaults['work_unit']) ?>"></div>
                <div class="form-group"><label>Kota Penandatanganan</label><input class="form-control" name="signature_city" maxlength="150" value="<?= inventory_e($defaults['signature_city']) ?>"></div>
                <div></div>
                <div class="form-group"><label>Jabatan Mengetahui</label><input class="form-control" name="knowing_title" maxlength="255" value="<?= inventory_e($defaults['knowing_title']) ?>"></div>
                <div class="form-group"><label>Jabatan Pengurus</label><input class="form-control" name="manager_title" maxlength="255" value="<?= inventory_e($defaults['manager_title']) ?>"></div>
                <div class="form-group"><label>Nama Pejabat Mengetahui</label><input class="form-control" name="knowing_name" maxlength="255" value="<?= inventory_e($defaults['knowing_name']) ?>"></div>
                <div class="form-group"><label>Nama Pengurus</label><input class="form-control" name="manager_name" maxlength="255" value="<?= inventory_e($defaults['manager_name']) ?>"></div>
                <div class="form-group"><label>NIP/Identitas Pejabat</label><input class="form-control" name="knowing_identity" maxlength="100" value="<?= inventory_e($defaults['knowing_identity']) ?>"></div>
                <div class="form-group"><label>NIP/Identitas Pengurus</label><input class="form-control" name="manager_identity" maxlength="100" value="<?= inventory_e($defaults['manager_identity']) ?>"></div>
            </div>
            <button class="btn btn-primary" type="submit">Simpan Lokasi</button> <a class="btn btn-default" href="<?= inventory_e(inventory_url()) ?>">Batal</a>
        </form>
    </div></div>

<?php elseif (in_array($action, ['add_item', 'edit_item'], true) && $canWrite): ?>
    <?php
    $defaults = array_merge([
        'id' => 0, 'location_id' => '', 'item_name' => '', 'brand_model' => '', 'serial_number' => '',
        'item_size' => '', 'material' => '', 'acquisition_year' => '', 'item_code' => '', 'quantity_register' => '',
        'acquisition_price' => '', 'item_condition' => 'B', 'notes' => '',
    ], $itemForm);
    ?>
    <div class="inventory-card"><div class="inventory-card-header"><?= $defaults['id'] ? 'Ubah Barang' : 'Tambah Barang' ?></div><div class="inventory-card-body">
        <?php if (!$locations): ?><div class="alert alert-warning">Tambahkan lokasi terlebih dahulu sebelum mencatat barang.</div><?php else: ?>
        <form class="submitViaAJAX" method="post" action="<?= inventory_e(inventory_url()) ?>">
            <input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="save_item"><input type="hidden" name="record_id" value="<?= (int) $defaults['id'] ?>">
            <div class="inventory-grid">
                <div class="form-group"><label>Lokasi/Ruangan <span class="text-danger">*</span></label><select class="form-control" name="location_id" required><option value="">Pilih lokasi</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= (int) $defaults['location_id'] === (int) $location['id'] ? 'selected' : '' ?>><?= inventory_e(($location['location_code'] ? $location['location_code'] . ' — ' : '') . $location['room_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Jenis/Nama Barang <span class="text-danger">*</span></label><input class="form-control" required name="item_name" maxlength="255" value="<?= inventory_e($defaults['item_name']) ?>"></div>
                <div class="form-group"><label>Merk/Model</label><input class="form-control" name="brand_model" maxlength="255" value="<?= inventory_e($defaults['brand_model']) ?>"></div>
                <div class="form-group"><label>No. Seri Pabrik</label><input class="form-control" name="serial_number" maxlength="255" value="<?= inventory_e($defaults['serial_number']) ?>"></div>
                <div class="form-group"><label>Ukuran</label><input class="form-control" name="item_size" maxlength="150" value="<?= inventory_e($defaults['item_size']) ?>"></div>
                <div class="form-group"><label>Bahan</label><input class="form-control" name="material" maxlength="150" value="<?= inventory_e($defaults['material']) ?>"></div>
                <div class="form-group"><label>Tahun Pembuatan/Pembelian</label><input class="form-control" type="number" min="1000" max="<?= (int) date('Y') + 1 ?>" name="acquisition_year" value="<?= inventory_e($defaults['acquisition_year']) ?>"></div>
                <div class="form-group"><label>No. Kode Barang</label><input class="form-control" name="item_code" maxlength="150" value="<?= inventory_e($defaults['item_code']) ?>"></div>
                <div class="form-group"><label>Jumlah Barang/Register</label><input class="form-control" name="quantity_register" maxlength="150" value="<?= inventory_e($defaults['quantity_register']) ?>" placeholder="Contoh: 1 / 001"></div>
                <div class="form-group"><label>Harga Beli/Perolehan (Rp)</label><input class="form-control" type="number" min="0" step="0.01" name="acquisition_price" value="<?= inventory_e($defaults['acquisition_price']) ?>"></div>
                <div class="form-group"><label>Keadaan Barang</label><select class="form-control" name="item_condition"><option value="B" <?= $defaults['item_condition'] === 'B' ? 'selected' : '' ?>>Baik (B)</option><option value="KB" <?= $defaults['item_condition'] === 'KB' ? 'selected' : '' ?>>Kurang Baik (KB)</option><option value="RB" <?= $defaults['item_condition'] === 'RB' ? 'selected' : '' ?>>Rusak Berat (RB)</option></select></div>
                <div class="form-group wide"><label>Keterangan</label><textarea class="form-control" name="notes" rows="3"><?= inventory_e($defaults['notes']) ?></textarea></div>
            </div>
            <button class="btn btn-primary" type="submit">Simpan Barang</button> <a class="btn btn-default" href="<?= inventory_e(inventory_url()) ?>">Batal</a>
        </form>
        <?php endif; ?>
    </div></div>

<?php else: ?>
    <?php
    $selectedLocation = isset($_GET['location_id']) ? (int) $_GET['location_id'] : 0;
    $selectedSlimsLocation = trim((string) ($_GET['slims_location_id'] ?? ''));
    $availableMasterLocationIds = array_map('strval', array_column($masterLocations, 'location_id'));
    if ($selectedSlimsLocation !== '' && !in_array($selectedSlimsLocation, $availableMasterLocationIds, true)) {
        $selectedSlimsLocation = '';
    }
    $keyword = trim((string) ($_GET['keywords'] ?? ''));
    $selectedLocationData = null;
    foreach ($locations as $location) {
        if ((int) $location['id'] === $selectedLocation) {
            $selectedLocationData = $location;
            break;
        }
    }
    $isLocationView = $action === 'view_location' && $selectedLocationData !== null;
    if ($action === 'view_location' && $selectedLocationData === null) {
        $selectedLocation = 0;
    }
    $displayLocations = $selectedSlimsLocation === ''
        ? $locations
        : array_values(array_filter(
            $locations,
            static fn (array $location): bool => (string) ($location['slims_location_id'] ?? '') === $selectedSlimsLocation
        ));
    $criteria = [];
    $params = [];
    if ($selectedLocation > 0) { $criteria[] = 'i.location_id = :location_id'; $params['location_id'] = $selectedLocation; }
    if ($selectedSlimsLocation !== '') { $criteria[] = 'l.slims_location_id = :slims_location_id'; $params['slims_location_id'] = $selectedSlimsLocation; }
    if ($keyword !== '') { $criteria[] = '(i.item_name LIKE :keyword OR i.item_code LIKE :keyword OR i.brand_model LIKE :keyword)'; $params['keyword'] = '%' . $keyword . '%'; }
    $sql = 'SELECT i.*, l.room_name, l.location_code, l.slims_location_id, ml.location_name AS slims_location_name
            FROM inventory_items i JOIN inventory_locations l ON l.id=i.location_id
            LEFT JOIN mst_location ml ON ml.location_id=l.slims_location_id';
    if ($criteria) { $sql .= ' WHERE ' . implode(' AND ', $criteria); }
    $sql .= ' ORDER BY l.room_name, i.item_name, i.id LIMIT 500';
    try { $statement = $db->prepare($sql); $statement->execute($params); $items = $statement->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $ignored) { $items = []; }
    ?>
    <?php if ($isLocationView): ?>
    <div class="inventory-card"><div class="inventory-card-header">Detail Lokasi/Ruangan</div><div class="inventory-card-body">
        <div class="inventory-grid">
            <div><strong>Kode Lokasi</strong><br><?= inventory_e($selectedLocationData['location_code'] ?: '-') ?></div>
            <div><strong>Ruangan</strong><br><?= inventory_e($selectedLocationData['room_name']) ?></div>
            <div><strong>Lokasi Master SLiMS</strong><br><?= inventory_e($selectedLocationData['slims_location_name'] ?: '-') ?><?= $selectedLocationData['slims_location_id'] ? ' (' . inventory_e($selectedLocationData['slims_location_id']) . ')' : '' ?></div>
            <div><strong>Wilayah</strong><br><?= inventory_e(trim($selectedLocationData['regency_city'] . ', ' . $selectedLocationData['province'], ', ')) ?></div>
            <div><strong>Unit/Satuan Kerja</strong><br><?= inventory_e($selectedLocationData['unit_name']) ?> — <?= inventory_e($selectedLocationData['work_unit']) ?></div>
        </div>
        <div class="inventory-actions mt-3">
            <a class="btn btn-default" href="<?= inventory_e(inventory_url()) ?>">Kembali ke Daftar Lokasi</a>
            <a class="btn btn-success notAJAX" target="_blank" href="<?= inventory_e($printBase . '?' . http_build_query(['location_id' => $selectedLocation])) ?>">Cetak PDF</a>
            <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= inventory_e(inventory_url(['action' => 'add_item', 'location_id' => $selectedLocation])) ?>">Tambah Barang</a><?php endif; ?>
        </div>
    </div></div>
    <?php else: ?>
    <?php if ($action === 'view_location'): ?><div class="alert alert-warning m-3">Lokasi yang dipilih tidak ditemukan.</div><?php endif; ?>
    <div class="inventory-card"><div class="inventory-card-header">Lokasi/Ruangan</div><div class="inventory-card-body">
        <?php if (!$displayLocations): ?><div class="inventory-empty">Belum ada lokasi inventaris yang sesuai filter.</div><?php else: ?>
        <div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr><th>Lokasi SLiMS</th><th>Kode Kartu</th><th>Ruangan</th><th>Wilayah</th><th>Unit/Satuan Kerja</th><th>Barang</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($displayLocations as $location): ?><tr><td><?= inventory_e($location['slims_location_name'] ?: '-') ?><?php if ($location['slims_location_id']): ?><br><small><?= inventory_e($location['slims_location_id']) ?></small><?php endif; ?></td><td><?= inventory_e($location['location_code']) ?></td><td><?= inventory_e($location['room_name']) ?></td><td><?= inventory_e(trim($location['regency_city'] . ', ' . $location['province'], ', ')) ?></td><td><?= inventory_e($location['unit_name']) ?><br><small><?= inventory_e($location['work_unit']) ?></small></td><td class="text-center"><?= (int) $location['item_count'] ?></td><td><div class="inventory-actions">
            <a class="btn btn-sm btn-default" href="<?= inventory_e(inventory_url(['action' => 'view_location', 'location_id' => $location['id']])) ?>">Lihat Barang</a>
            <a class="btn btn-sm btn-success notAJAX" target="_blank" href="<?= inventory_e($printBase . '?' . http_build_query(['location_id' => $location['id']])) ?>">Cetak PDF</a>
            <?php if ($canWrite): ?><a class="btn btn-sm btn-primary" href="<?= inventory_e(inventory_url(['action' => 'edit_location', 'record_id' => $location['id']])) ?>">Ubah</a><a class="btn btn-sm btn-info" href="<?= inventory_e(inventory_url(['action' => 'add_item', 'location_id' => $location['id']])) ?>">Tambah Barang</a>
            <form class="submitViaAJAX" method="post" action="<?= inventory_e(inventory_url()) ?>" onsubmit="return confirm('Hapus lokasi beserta seluruh barang di dalamnya?')"><input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="delete_location"><input type="hidden" name="record_id" value="<?= (int) $location['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?>
        </div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="inventory-card"><div class="inventory-card-header"><?= $isLocationView ? 'Barang di ' . inventory_e($selectedLocationData['room_name']) : 'Daftar Barang' ?></div><div class="inventory-card-body">
        <form class="form-inline mb-3 submitViaAJAX" method="get" action="<?= inventory_e($_SERVER['PHP_SELF']) ?>"><?php foreach (['mod', 'id'] as $key): if (isset($_GET[$key])): ?><input type="hidden" name="<?= $key ?>" value="<?= inventory_e($_GET[$key]) ?>"><?php endif; endforeach; ?>
            <?php if ($isLocationView): ?><input type="hidden" name="action" value="view_location"><input type="hidden" name="location_id" value="<?= $selectedLocation ?>"><?php else: ?><select class="form-control mr-2" name="slims_location_id" title="Lokasi master SLiMS"><option value="">Semua lokasi master SLiMS</option><?php foreach ($masterLocations as $masterLocation): ?><option value="<?= inventory_e($masterLocation['location_id']) ?>" <?= $selectedSlimsLocation === (string) $masterLocation['location_id'] ? 'selected' : '' ?>><?= inventory_e($masterLocation['location_name'] . ' (' . $masterLocation['location_id'] . ')') ?></option><?php endforeach; ?></select><select class="form-control mr-2" name="location_id" title="Ruangan inventaris"><option value="0">Semua ruangan inventaris</option><?php foreach ($locations as $location): ?><option value="<?= (int) $location['id'] ?>" <?= $selectedLocation === (int) $location['id'] ? 'selected' : '' ?>><?= inventory_e($location['room_name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <input class="form-control mr-2" name="keywords" value="<?= inventory_e($keyword) ?>" placeholder="Nama, kode, atau merk"><button class="btn btn-default" type="submit">Filter</button>
            <?php if ($selectedLocation > 0 && !$isLocationView): ?><a class="btn btn-success ml-2 notAJAX" target="_blank" href="<?= inventory_e($printBase . '?' . http_build_query(['location_id' => $selectedLocation])) ?>">Cetak Lokasi Ini</a><?php endif; ?>
        </form>
        <?php if (!$items): ?><div class="inventory-empty">Belum ada barang yang sesuai filter.</div><?php else: ?><div class="table-responsive"><table class="table table-striped table-bordered"><thead><tr><th>Lokasi</th><th>Nama Barang</th><th>Merk/Model</th><th>Kode</th><th>Register</th><th>Harga</th><th>Kondisi</th><th>Aksi</th></tr></thead><tbody>
        <?php foreach ($items as $item): ?><tr><td><?= inventory_e($item['room_name']) ?><?php if ($item['slims_location_name']): ?><br><small><?= inventory_e($item['slims_location_name']) ?></small><?php endif; ?></td><td><?= inventory_e($item['item_name']) ?></td><td><?= inventory_e($item['brand_model']) ?></td><td><?= inventory_e($item['item_code']) ?></td><td><?= inventory_e($item['quantity_register']) ?></td><td class="text-right"><?= $item['acquisition_price'] > 0 ? 'Rp ' . inventory_e(number_format((float) $item['acquisition_price'], 0, ',', '.')) : '' ?></td><td><?= inventory_e($item['item_condition']) ?></td><td><div class="inventory-actions"><?php if ($canWrite): ?><a class="btn btn-sm btn-primary" href="<?= inventory_e(inventory_url(['action' => 'edit_item', 'record_id' => $item['id']])) ?>">Ubah</a><form class="submitViaAJAX" method="post" action="<?= inventory_e(inventory_url(['slims_location_id' => $selectedSlimsLocation, 'location_id' => $selectedLocation, 'keywords' => $keyword])) ?>" onsubmit="return confirm('Hapus barang ini?')"><input type="hidden" name="csrf_token" value="<?= inventory_e($csrf) ?>"><input type="hidden" name="form_action" value="delete_item"><input type="hidden" name="record_id" value="<?= (int) $item['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Hapus</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
<?php endif; ?>
