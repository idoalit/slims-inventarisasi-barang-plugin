<?php

declare(strict_types=1);
require __DIR__ . '/../src/ItemPhotos.php';
require __DIR__ . '/../src/PhotoStorage.php';
use SLiMS\Plugins\Inventory\ItemPhotos;

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
    echo 'ok   ' . $message . PHP_EOL;
}
function rejects(callable $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException $e) { check(true, $message); return; }
    throw new RuntimeException('Tidak ditolak: ' . $message);
}
function fixture(string $type, int $width = 10, int $height = 10): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    $type($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return $bytes;
}

foreach (['imagejpeg', 'imagepng', 'imagewebp'] as $encoder) {
    $jpeg = ItemPhotos::normalize(fixture($encoder));
    check(getimagesizefromstring($jpeg)['mime'] === 'image/jpeg', $encoder . ' dikonversi ke JPEG');
}
$jpeg = ItemPhotos::normalize(fixture('imagejpeg') . '<?php echo "payload-marker"; ?>');
check(!str_contains($jpeg, 'payload-marker'), 'payload tambahan tidak disimpan');
$jpeg = ItemPhotos::normalize(fixture('imagepng', 2000, 1000));
check(getimagesizefromstring($jpeg)[0] === 1280, 'gambar besar diperkecil');
rejects(fn() => ItemPhotos::normalize('<?php echo "evil"; ?>'), 'skrip ditolak');
rejects(fn() => ItemPhotos::normalize('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'SVG ditolak');
rejects(fn() => ItemPhotos::normalize(fixture('imagegif')), 'GIF ditolak');
rejects(fn() => ItemPhotos::normalize(str_repeat('x', ItemPhotos::MAX_BYTES + 1)), 'berkas di atas 2 MB ditolak');
rejects(fn() => ItemPhotos::normalize(fixture('imagepng', 4097, 1)), 'dimensi di atas batas ditolak');
rejects(fn() => ItemPhotos::normalize(fixture('imagepng', 3000, 3000)), 'gambar di atas 8 megapiksel ditolak');
rejects(fn() => ItemPhotos::uploads(['error' => [UPLOAD_ERR_OK], 'tmp_name' => [__FILE__]]), 'path lokal bukan unggahan HTTP ditolak');
rejects(fn() => ItemPhotos::uploads(['error' => [UPLOAD_ERR_PARTIAL], 'tmp_name' => ['']]), 'unggahan parsial ditolak');
rejects(fn() => ItemPhotos::uploads(['error' => array_fill(0, 6, UPLOAD_ERR_OK), 'tmp_name' => []]), 'lebih dari 5 unggahan ditolak');
check(ItemPhotos::uploads([]) === [], 'barang boleh tanpa foto');

// Exercise binding, limits, and ownership with a PDO double; no DB driver needed.
class PhotoPDO extends PDO
{
    public array $existing = [10, 11, 12, 13, 14];
    public array $writes = [];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PhotoStatement($this, $query);
    }
}
class PhotoStatement extends PDOStatement
{
    public function __construct(private PhotoPDO $db, private string $sql) {}
    private array $params = [];
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (!str_starts_with($this->sql, 'SELECT')) $this->db->writes[] = [$this->sql, $params];
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        if (str_contains($this->sql, 'FROM inventory_items')) return ($this->params[0] ?? 0) === 7 ? 7 : false;
        if (($this->params[1] ?? 0) === 7 && in_array($this->params[0] ?? 0, $this->db->existing, true)) {
            return str_pad((string) $this->params[0], 64, '0', STR_PAD_LEFT) . '.jpg';
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return array_map(fn($id) => ['id' => $id, 'filename' => str_pad((string) $id, 64, '0', STR_PAD_LEFT) . '.jpg'], $this->db->existing); }
}
$directory = sys_get_temp_dir() . '/inventory-photos-test-' . bin2hex(random_bytes(8));
$storage = new \SLiMS\Plugins\Inventory\PhotoStorage($directory);
$created = [];
$removed = [];
$db = new PhotoPDO();
rejects(fn() => ItemPhotos::apply($db, 7, [], [999], $storage, $created, $removed), 'foto barang lain tidak dapat dihapus');
rejects(fn() => ItemPhotos::apply($db, 7, [$jpeg], [], $storage, $created, $removed), 'maksimal 5 foto termasuk foto lama');
check($db->writes === [], 'validasi gagal tidak mengubah foto');
ItemPhotos::apply($db, 7, [$jpeg], [10, 10], $storage, $created, $removed);
check(count($db->writes) === 2 && $db->writes[0][1] === [10, 7] && $db->writes[1][1] === [7, $created[0]], 'penggantian foto memakai ID barang dan parameter terikat');

check($storage->read($created[0]) === $jpeg, 'isi gambar disimpan ke folder');
check(!str_contains($db->writes[1][0], 'image_data'), 'database hanya menyimpan nama berkas');
check(count($removed) === 1, 'penghapusan fisik ditunda sampai commit');
check(is_file($directory . '/.htaccess'), 'folder foto dilindungi dari akses HTTP langsung');
rejects(fn() => $storage->read('../../etc/passwd'), 'path traversal ditolak');
rejects(fn() => $storage->delete('../index.php'), 'penghapusan di luar folder ditolak');
$linked = str_repeat('a', 64) . '.jpg';
symlink(__FILE__, $directory . '/' . $linked);
rejects(fn() => $storage->read($linked), 'pembacaan symbolic link ditolak');
rejects(fn() => $storage->delete($linked), 'penghapusan symbolic link ditolak');
unlink($directory . '/' . $linked);
$storage->cleanup($created);
check($storage->read($created[0]) === null, 'berkas baru dibersihkan ketika transaksi dibatalkan');
unlink($directory . '/.htaccess');
rmdir($directory);

$db = new PhotoPDO();
rejects(fn() => ItemPhotos::deleteOne($db, 7, 999), 'hapus langsung menolak foto yang bukan milik barang');
rejects(fn() => ItemPhotos::deleteOne($db, 8, 10), 'hapus langsung menolak barang yang tidak ditemukan');
check($db->writes === [], 'permintaan hapus tidak valid tidak menjalankan DELETE');
$filename = ItemPhotos::deleteOne($db, 7, 10);
check($filename === str_pad('10', 64, '0', STR_PAD_LEFT) . '.jpg' && count($db->writes) === 1 && $db->writes[0][1] === [10, 7], 'tombol hapus menargetkan tepat satu foto pada barangnya');
