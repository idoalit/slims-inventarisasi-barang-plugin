<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../index.php');
$print = (string) file_get_contents(__DIR__ . '/../print.php');
$migration = (string) file_get_contents(__DIR__ . '/../migration/2_AddSlimsLocationToInventoryLocations.php');
$failures = [];

$checks = [
    'migrasi menambahkan referensi master lokasi' => str_contains($migration, '`slims_location_id` VARCHAR(3) NULL'),
    'form membaca mst_location' => str_contains($source, 'SELECT location_id, location_name FROM mst_location'),
    'lokasi pilihan divalidasi di server' => str_contains($source, 'SELECT 1 FROM mst_location WHERE location_id = ? LIMIT 1'),
    'form lokasi menyimpan referensi master' => str_contains($source, 'name="slims_location_id"'),
    'daftar dapat difilter dengan lokasi master' => str_contains($source, 'l.slims_location_id = :slims_location_id'),
    'daftar menampilkan nama lokasi master' => str_contains($source, 'slims_location_name'),
    'PDF mengambil nama lokasi master' => str_contains($print, 'LEFT JOIN mst_location ml'),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}

exit($failures ? 1 : 0);
