<?php
declare(strict_types=1);
require __DIR__.'/../src/InventoryUi.php';
use SLiMS\Plugins\Inventory\InventoryUi;
define('AWB','/admin/');
$cases=['setup'=>'checklist-and-schedule.php','template'=>'checklist-and-schedule.php','schedule'=>'checklist-and-schedule.php','inspections'=>'inspection.php','inspection'=>'inspection.php','photo'=>'inspection.php','new'=>'inspection.php','findings'=>'findings-and-follow-up.php','finding'=>'findings-and-follow-up.php','reports'=>'report.php','pdf'=>'report.php','dashboard'=>'report.php','inventory'=>'index.php'];
foreach ($cases as $tab=>$file) {
    $url=InventoryUi::url(['tab'=>$tab,'record'=>7,'room'=>12,'from'=>'2026-09-01','id'=>'obsolete','mod'=>'wrong']);
    parse_str(parse_url($url,PHP_URL_QUERY),$query);
    if ($query['id']!==md5(realpath(__DIR__.'/../'.$file)) || $query['mod']!=='stock_take' || $query['room']!=='12' || $query['record']!=='7') throw new RuntimeException('Bad route: '.$tab);
    if ($query['tab']!==($tab==='dashboard'?'reports':$tab)) throw new RuntimeException('Bad tab: '.$tab);
}
echo "ok all page, detail, photo, PDF and legacy dashboard routes use registered menus and preserve context\n";
