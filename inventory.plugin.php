<?php
/**
 * Plugin Name: Inventaris Barang Perpustakaan
 * Plugin URI: https://github.com/idoalit/slims-inventarisasi-barang-plugin
 * Description: Pencatatan inventaris barang per lokasi/ruangan dan pencetakan Kartu Inventaris Ruangan dalam format PDF.
 * Version: 1.2.0
 * Author: Waris Agung Widodo
 * Author URI: https://github.com/idoalit
 */

defined('INDEX_AUTH') || die('Direct access not allowed!');

$plugin = \SLiMS\Plugins::getInstance();
$plugin->registerMenu(
    'stock_take',
    'Inventaris Barang',
    __DIR__ . '/index.php',
    'Kelola inventaris barang perpustakaan dan cetak kartu inventaris per lokasi.'
);
