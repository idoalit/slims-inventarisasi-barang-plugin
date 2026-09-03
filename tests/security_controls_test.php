<?php

declare(strict_types=1);

$index = (string) file_get_contents(__DIR__ . '/../index.php');
$print = (string) file_get_contents(__DIR__ . '/../print.php');
$failures = [];

$checks = [
    'otorisasi menggunakan stock_take' => str_contains($index, "havePrivilege('stock_take', 'r')")
        && str_contains($index, "havePrivilege('stock_take', 'w')")
        && str_contains($print, "havePrivilege('stock_take', 'r')"),
    'cakupan IP menggunakan stocktake' => str_contains($index, "do_checkIP('smc-stocktake')")
        && str_contains($print, "do_checkIP('smc-stocktake')"),
    'mutasi dilindungi CSRF' => str_contains($index, 'hash_equals($csrf'),
    'aktivitas inventaris diaudit' => str_contains($index, "writeLog('staff'")
        && str_contains($print, "'Print'"),
    'PDF tidak disimpan cache' => str_contains($print, 'private, no-store'),
    'PDF tidak mengekspos versi generator' => str_contains($print, "'exposeVersion' => false"),
    'PDF diberi rate limit' => str_contains($print, 'count($pdfRequests) >= 10'),
    'PDF dibatasi 500 barang' => str_contains($print, 'LIMIT 501')
        && str_contains($print, 'count($items) > 500'),
    'pesan exception internal tidak dikirim ke UI' => str_contains(
        $index,
        '$message = \'Terjadi kesalahan internal. Silakan coba lagi atau hubungi administrator.\''
    ),
    'runtime mPDF dikelola Composer plugin' => str_contains($print, "__DIR__ . '/vendor/autoload.php'")
        && str_contains($print, 'class_exists(\\Mpdf\\Mpdf::class)'),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) {
        $failures[] = $label;
    }
}

exit($failures ? 1 : 0);
