<?php

namespace SLiMS\Plugins\Inventory;

use PDO;
use RuntimeException;

final class ItemCodes
{
    public static function owner(string $token, string $session): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token) || $session === '') {
            throw new RuntimeException('Token form kode tidak valid. Muat ulang form.');
        }
        return hash('sha256', $session . ':' . $token);
    }

    public static function lock(PDO $db): void
    {
        if (!$db->inTransaction()) throw new RuntimeException('Transaksi kode belum dimulai.');
        if ($db->query("SELECT prefix FROM inventory_item_code_sequences WHERE prefix = '' FOR UPDATE")->fetchColumn() === false) {
            throw new RuntimeException('Jalankan migrasi plugin hingga versi 6 melalui System → Plugins.');
        }
    }

    public static function increment(string $number): string
    {
        for ($i = strlen($number) - 1; $i >= 0; --$i) {
            if ($number[$i] !== '9') {
                $number[$i] = (string) ((int) $number[$i] + 1);
                return $number;
            }
            $number[$i] = '0';
        }
        return '1' . $number;
    }

    // Caller owns the transaction and takes the common lock before any item lock.
    public static function reserve(PDO $db, int $locationId, string $owner): string
    {
        self::lock($db);
        $query = $db->prepare('SELECT ml.location_id FROM inventory_locations l JOIN mst_location ml ON ml.location_id = l.slims_location_id WHERE l.id = ?');
        $query->execute([$locationId]);
        $prefix = $query->fetchColumn();
        if ($prefix === false || trim($prefix) === '') {
            throw new RuntimeException('Pilih ruangan yang terhubung ke master Lokasi Perpustakaan sebelum membuat kode.');
        }
        $query = $db->prepare('SELECT last_number FROM inventory_item_code_sequences WHERE prefix = ?');
        $query->execute([$prefix]);
        $number = $query->fetchColumn();
        if ($number === false) {
            $number = '0';
            $stem = $prefix . '-INV-';
            $existing = $db->prepare('SELECT item_code FROM inventory_items WHERE LEFT(item_code, CHAR_LENGTH(?)) = ?');
            $existing->execute([$stem, $stem]);
            while (($code = $existing->fetchColumn()) !== false) {
                if (preg_match('/\A' . preg_quote($stem, '/') . '([0-9]{6,})\z/i', $code, $match)) {
                    $candidate = ltrim($match[1], '0') ?: '0';
                    if (strlen($candidate) > strlen($number) || (strlen($candidate) === strlen($number) && strcmp($candidate, $number) > 0)) $number = $candidate;
                }
            }
        }
        $exists = $db->prepare('SELECT item_code FROM inventory_items WHERE item_code = ? UNION ALL SELECT code FROM inventory_item_code_reservations WHERE code = ? LIMIT 1');
        do {
            $number = self::increment($number);
            $code = $prefix . '-INV-' . str_pad($number, 6, '0', STR_PAD_LEFT);
            if (mb_strlen($code) > 150) throw new RuntimeException('Panjang kode melebihi kapasitas kolom kode barang.');
            $exists->execute([$code, $code]);
        } while ($exists->fetchColumn() !== false);
        $db->prepare('INSERT INTO inventory_item_code_sequences (prefix, last_number) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_number = VALUES(last_number)')->execute([$prefix, $number]);
        $db->prepare('INSERT INTO inventory_item_code_reservations (code, owner_hash, created_at) VALUES (?, ?, NOW())')->execute([$code, $owner]);
        return $code;
    }

    public static function validateAndConsume(PDO $db, string $code, ?string $oldCode, string $owner): void
    {
        if (mb_strlen($code) > 150) throw new RuntimeException('Kode barang maksimal 150 karakter.');
        if ($code === '' || ($oldCode !== null && $code === $oldCode)) return;
        $query = $db->prepare('SELECT 1 FROM inventory_items WHERE item_code = ? LIMIT 1');
        $query->execute([$code]);
        if ($query->fetchColumn()) throw new RuntimeException('Kode barang sudah digunakan barang lain.');
        $query = $db->prepare('SELECT owner_hash, used_at FROM inventory_item_code_reservations WHERE code = ?');
        $query->execute([$code]);
        $reservation = $query->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) return;
        if ($reservation['used_at'] !== null || !hash_equals($reservation['owner_hash'], $owner)) {
            throw new RuntimeException('Kode sudah dipesan form lain atau pernah digunakan. Klik Buat Kode untuk nomor baru.');
        }
        $db->prepare('UPDATE inventory_item_code_reservations SET used_at = NOW() WHERE code = ?')->execute([$code]);
    }
}
