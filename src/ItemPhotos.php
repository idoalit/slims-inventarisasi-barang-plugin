<?php

namespace SLiMS\Plugins\Inventory;

final class ItemPhotos
{
    public const MAX_PHOTOS = 5;
    public const MAX_BYTES = 2097152;

    public static function uploads(array $files): array
    {
        if (!$files) {
            return [];
        }
        if (!isset($files['error'], $files['tmp_name']) || !is_array($files['error']) || !is_array($files['tmp_name']) || count($files['error']) > self::MAX_PHOTOS) {
            throw new \RuntimeException('Maksimal 5 foto per barang.');
        }
        $photos = [];
        foreach ($files['error'] as $key => $error) {
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $path = $files['tmp_name'][$key] ?? null;
            if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
                throw new \RuntimeException('Unggah foto gagal. Periksa ukuran berkas dan batas unggah server.');
            }
            if (filesize($path) > self::MAX_BYTES) {
                throw new \RuntimeException('Ukuran setiap foto maksimal 2 MB.');
            }
            $photos[] = self::normalize((string) file_get_contents($path));
        }
        return $photos;
    }

    public static function normalize(string $bytes): string
    {
        if (strlen($bytes) > self::MAX_BYTES || $bytes === '') {
            throw new \RuntimeException('Ukuran setiap foto maksimal 2 MB.');
        }
        $info = @getimagesizefromstring($bytes);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ($info['mime'] ?? '') !== $mime) {
            throw new \RuntimeException('Foto harus berupa gambar JPEG, PNG, atau WebP yang valid.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 8000000) {
            throw new \RuntimeException('Resolusi foto maksimal 8 megapiksel dan 4096 piksel per sisi.');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) {
            throw new \RuntimeException('Isi gambar tidak dapat dibaca.');
        }
        $target = null;
        try {
            $scale = min(1, 1280 / max($info[0], $info[1]));
            $width = max(1, (int) ($info[0] * $scale));
            $height = max(1, (int) ($info[1] * $scale));
            $target = imagecreatetruecolor($width, $height);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            ob_start();
            try {
                if (!imagejpeg($target, null, 80)) {
                    throw new \RuntimeException('Foto tidak dapat diproses.');
                }
                $jpeg = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if (strlen($jpeg) > self::MAX_BYTES) {
                throw new \RuntimeException('Hasil pemrosesan foto terlalu besar.');
            }
            return $jpeg;
        } finally {
            imagedestroy($source);
            if ($target) {
                imagedestroy($target);
            }
        }
    }

    public static function deleteOne(\PDO $db, int $itemId, int $photoId): ?string
    {
        // Same parent lock as upload: serialize photo changes for this item.
        $lock = $db->prepare('SELECT id FROM inventory_items WHERE id = ? FOR UPDATE');
        $lock->execute([$itemId]);
        if (!$lock->fetchColumn()) throw new \RuntimeException('Barang tidak ditemukan.');
        $query = $db->prepare('SELECT filename FROM inventory_item_photos WHERE id = ? AND item_id = ? FOR UPDATE');
        $query->execute([$photoId, $itemId]);
        $filename = $query->fetchColumn();
        if ($filename === false) throw new \RuntimeException('Foto tidak ditemukan pada barang ini atau sudah dihapus.');
        $delete = $db->prepare('DELETE FROM inventory_item_photos WHERE id = ? AND item_id = ?');
        $delete->execute([$photoId, $itemId]);
        return $filename;
    }

    public static function apply(\PDO $db, int $itemId, array $photos, array $remove, PhotoStorage $storage, array &$created, array &$removed): void
    {
        // The caller holds the item's row lock until the save transaction commits.
        $query = $db->prepare('SELECT id, filename FROM inventory_item_photos WHERE item_id = ?');
        $query->execute([$itemId]);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $existing = array_map('intval', array_column($rows, 'id'));
        $filenames = array_column($rows, 'filename', 'id');
        foreach ($remove as $id) {
            if (!is_scalar($id) || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !in_array((int) $id, $existing, true)) {
                throw new \RuntimeException('Foto yang akan dihapus tidak sesuai dengan barang ini.');
            }
        }
        $remove = array_unique(array_map('intval', $remove));
        if (count($existing) - count($remove) + count($photos) > self::MAX_PHOTOS) {
            throw new \RuntimeException('Maksimal 5 foto per barang. Hapus salah satu foto lama sebelum menambahkan foto baru.');
        }
        $delete = $db->prepare('DELETE FROM inventory_item_photos WHERE id = ? AND item_id = ?');
        foreach ($remove as $id) {
            $delete->execute([$id, $itemId]);
            $removed[] = $filenames[$id];
        }
        $insert = $db->prepare('INSERT INTO inventory_item_photos (item_id, filename, created_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
        foreach ($photos as $photo) {
            $filename = $storage->write($photo);
            $created[] = $filename;
            $insert->execute([$itemId, $filename]);
        }
    }
}
