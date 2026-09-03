<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

$root = dirname(__DIR__, 3);
$pluginRoot = dirname(__DIR__);
$pluginAutoload = $pluginRoot . '/vendor/autoload.php';
$rootAutoload = $root . '/vendor/autoload.php';
if (is_file($rootAutoload)) {
    require $rootAutoload;
    class_exists(\Psr\Log\LoggerAwareInterface::class);
}
if (is_file($pluginAutoload)) {
    require $pluginAutoload;
}

$reflection = new ReflectionClass(\Mpdf\Mpdf::class);
$classFile = (string) $reflection->getFileName();
if (!str_starts_with($classFile, $root . '/vendor/mpdf/mpdf/')
    && !str_starts_with($classFile, $pluginRoot . '/vendor/mpdf/mpdf/')) {
    echo 'FAIL plugin tidak menggunakan mPDF yang dikelola Composer' . PHP_EOL;
    exit(1);
}

$pdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir(), 'exposeVersion' => false]);
$pdf->setLogger(new \Psr\Log\NullLogger());
$pdf->WriteHTML('<p>Uji runtime mPDF</p>');
$contents = $pdf->Output('', 'S');

if (!str_starts_with($contents, '%PDF-')) {
    echo 'FAIL mPDF tidak menghasilkan dokumen PDF' . PHP_EOL;
    exit(1);
}

echo 'ok   mPDF Composer dapat memproses logger dan menghasilkan PDF' . PHP_EOL;
