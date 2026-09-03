<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('LIB', dirname(__DIR__, 3) . '/lib/');
$pluginAutoload = __DIR__ . '/../vendor/autoload.php';
$rootAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
require is_file($pluginAutoload) ? $pluginAutoload : $rootAutoload;
require LIB . 'autoload.php';
require_once __DIR__ . '/../src/PdfTemplate.php';

use Mpdf\Mpdf;
use SLiMS\Plugins\Inventory\PdfTemplate;

$location = [
    'location_code' => 'SMG-01', 'slims_location_id' => 'SL', 'slims_location_name' => 'Perpustakaan Utama',
    'room_name' => 'Ruang Referensi', 'province' => 'JAWA TENGAH',
    'regency_city' => 'SEMARANG', 'unit_name' => 'PERPUSTAKAAN',
    'work_unit' => 'POLTEKKES KEMENKES SEMARANG', 'signature_city' => 'Semarang',
    'knowing_title' => 'Direktur Poltekkes Kemenkes Semarang', 'knowing_name' => 'Dr. Contoh',
    'knowing_identity' => 'NIP. 001', 'manager_title' => 'Pengurus Barang Inventaris',
    'manager_name' => 'Petugas Contoh', 'manager_identity' => 'NIP. 002',
];
$items = [];
$itemCount = max(1, (int) ($argv[2] ?? 6));
for ($number = 1; $number <= $itemCount; $number++) {
    $items[] = [
        'item_name' => 'Meja Baca ' . $number, 'brand_model' => 'Model A', 'serial_number' => 'SN-' . $number,
        'item_size' => '120 x 60 cm', 'material' => 'Kayu', 'acquisition_year' => 2026,
        'item_code' => 'INV-' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
        'quantity_register' => '1 / ' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
        'acquisition_price' => 1500000, 'item_condition' => $number % 3 === 0 ? 'KB' : 'B', 'notes' => '',
    ];
}

$output = $argv[1] ?? sys_get_temp_dir() . '/inventory-sample.pdf';
$pdf = new Mpdf([
    'mode' => 'utf-8', 'format' => [330, 216], 'margin_left' => 10, 'margin_right' => 10,
    'margin_top' => 4, 'margin_bottom' => 7, 'tempDir' => sys_get_temp_dir(), 'exposeVersion' => false,
]);
$pdf->WriteHTML(PdfTemplate::render($location, $items, new DateTimeImmutable('2026-02-12')));
$pdf->Output($output, 'F');
echo $output . PHP_EOL;
