<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/PdfTemplate.php';

use SLiMS\Plugins\Inventory\PdfTemplate;

$location = [
    'location_code' => 'SMG-01',
    'room_name' => 'Ruang & Referensi',
    'province' => 'JAWA TENGAH',
    'regency_city' => 'SEMARANG',
    'unit_name' => 'PERPUSTAKAAN',
    'work_unit' => 'POLTEKKES KEMENKES SEMARANG',
    'signature_city' => 'Semarang',
    'knowing_title' => 'Direktur',
    'knowing_name' => 'Nama Direktur',
    'knowing_identity' => 'NIP. 1',
    'manager_title' => 'Pengurus Barang Inventaris',
    'manager_name' => 'Nama Pengurus',
    'manager_identity' => 'NIP. 2',
];
$items = [[
    'item_name' => '<Meja baca>',
    'brand_model' => 'Contoh',
    'serial_number' => 'SN-1',
    'item_size' => '120 cm',
    'material' => 'Kayu',
    'acquisition_year' => 2026,
    'item_code' => 'INV-001',
    'quantity_register' => '1 / 001',
    'acquisition_price' => 1500000,
    'item_condition' => 'KB',
    'notes' => 'Perlu perawatan',
]];

$html = PdfTemplate::render($location, $items, new DateTimeImmutable('2026-02-12'));
$checks = [
    'judul kartu' => str_contains($html, 'KARTU INVENTARIS RUANGAN'),
    'lokasi di-escape' => str_contains($html, 'Ruang &amp; Referensi'),
    'nama barang di-escape' => str_contains($html, '&lt;Meja baca&gt;'),
    'tanggal Indonesia' => str_contains($html, '12 Februari 2026'),
    'harga Indonesia' => str_contains($html, '1.500.000'),
    'kolom kondisi gabungan' => str_contains($html, 'colspan="3">KEADAAN BARANG'),
    'kondisi KB ditandai' => str_contains($html, '<td class="center condition"></td><td class="center condition">X</td>'),
    'minimal 13 baris' => substr_count($html, '<td class="center">') >= 13,
];

$failed = false;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    $failed = $failed || !$passed;
}
exit($failed ? 1 : 0);
