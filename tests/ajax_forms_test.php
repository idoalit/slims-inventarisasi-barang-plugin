<?php

declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../index.php');
preg_match_all('/<form\b[^>]*>/i', $source, $matches);
$forms = $matches[0] ?? [];
$failures = [];

if (count($forms) !== 5) {
    $failures[] = 'jumlah form berubah: ditemukan ' . count($forms) . ', diharapkan 5';
}
foreach ($forms as $index => $form) {
    if (!preg_match('/class="[^"]*\bsubmitViaAJAX\b[^"]*"/i', $form)) {
        $failures[] = 'form #' . ($index + 1) . ' tidak menggunakan submitViaAJAX';
    }
    if (preg_match('/\btarget\s*=/i', $form)) {
        $failures[] = 'form #' . ($index + 1) . ' memiliki target sehingga melewati handler AJAX SLiMS';
    }
}

if (!str_contains((string) file_get_contents(dirname(__DIR__, 3) . '/js/gui.js'), '.submitViaAJAX')) {
    $failures[] = 'handler submitViaAJAX tidak ditemukan pada js/gui.js SLiMS';
}
if (!str_contains($source, "'action' => 'view_location', 'location_id' => \$location['id']")) {
    $failures[] = 'tautan Lihat Barang tidak mengirim action dan location_id';
}
if (!str_contains($source, "\$isLocationView = \$action === 'view_location'")) {
    $failures[] = 'route view_location tidak memiliki tampilan detail';
}

if ($failures) {
    foreach ($failures as $failure) {
        echo 'FAIL ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo 'ok   seluruh form inventaris menggunakan handler AJAX resmi SLiMS' . PHP_EOL;
