<?php
// Routed by the authenticated SLiMS admin plugin container.
defined('INDEX_AUTH') || die('Direct access not allowed!');
require LIB . 'ip_based_access.inc.php';
do_checkIP('smc'); do_checkIP('smc-stocktake');
require SB . 'admin/default/session.inc.php';
require_once SB . 'admin/default/session_check.inc.php';
require_once __DIR__ . '/src/PhotoStorage.php';
require_once __DIR__ . '/src/ItemPhotos.php';
require_once __DIR__ . '/src/WatchRecurrence.php';
require_once __DIR__ . '/src/Supervision.php';
require_once __DIR__ . '/src/WatchView.php';
use SLiMS\Plugins\Inventory\Supervision;
use SLiMS\Plugins\Inventory\WatchView;

$canRead=utility::havePrivilege('stock_take','r');
$canWrite=utility::havePrivilege('stock_take','w');
function watch_log(string $action,string $message): void {
    try { writeLog('staff',(string)($_SESSION['uid']??0),'Pengawasan & Pemeliharaan',$message,'stock_take',$action); }
    catch (Throwable $e) { error_log('Supervision audit: '.$e->getMessage()); }
}
$isPost=$_SERVER['REQUEST_METHOD']==='POST';
if (!$canRead || ($isPost && !$canWrite)) {
    http_response_code(403); watch_log('Denied','Akses pengawasan ditolak.');
    if ($isPost) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'message'=>'Anda tidak memiliki hak akses pengawasan.']); }
    else echo '<div class="alert alert-danger">Anda tidak memiliki hak baca pengawasan.</div>';
    return;
}
if (empty($_SESSION['inventory_watch_csrf'])) $_SESSION['inventory_watch_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['inventory_watch_csrf'];
$db=\SLiMS\DB::getInstance(); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
// Subdirectory inherits the existing web-server denial for inventory photos.
$storage=new \SLiMS\Plugins\Inventory\PhotoStorage(SB.'images/inventaris-barang/pengawasan');
$watch=new Supervision($db,$storage);
$base=AWB.'plugin_container.php?'.http_build_query(['mod'=>'stock_take','id'=>md5(realpath(__FILE__))]);
$tab=(string)($_GET['tab']??'dashboard');
try {
    if ($isPost) {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
        if (!is_string($_POST['csrf_token']??null) || !hash_equals($csrf,$_POST['csrf_token'])) {
            http_response_code(403); watch_log('Denied','Token CSRF pengawasan ditolak.'); throw new RuntimeException('Token formulir tidak valid. Muat ulang halaman.');
        }
        $action=(string)($_POST['watch_action']??'');
        $result=$watch->mutate($action,$_POST,$_FILES['photos']??[],(int)($_SESSION['uid']??0));
        watch_log('Update','Aksi '.$action.'; '.Supervision::json($result));
        $navigation=$result; unset($navigation['generated'],$navigation['more']);
        echo json_encode(['ok'=>true,'message'=>'Data pengawasan berhasil disimpan.','url'=>$base.'&'.http_build_query($navigation),'generated'=>$result['generated']??0,'more'=>$result['more']??false]);
        return;
    }
    if ($tab==='photo') {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        $bytes=$watch->photo((int)($_GET['inspection_id']??0),(int)($_GET['photo_id']??0));
        if ($bytes===null) throw new RuntimeException('Berkas foto tidak tersedia.');
        header('Content-Type: image/jpeg'); header('Content-Disposition: inline; filename="bukti-pengawasan.jpg"'); echo $bytes; return;
    }
    $filter=$watch->filter($_GET);
    if ($tab==='pdf') {
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        $requests=array_values(array_filter((array)($_SESSION['inventory_watch_pdf_requests']??[]),static fn($time)=>is_int($time)&&$time>time()-60));
        if (count($requests)>=10) { http_response_code(429); header('Retry-After: 60'); throw new RuntimeException('Terlalu banyak permintaan PDF. Coba lagi satu menit kemudian.'); }
        $requests[]=time(); $_SESSION['inventory_watch_pdf_requests']=$requests;
        session_write_close();
        $autoload=__DIR__.'/vendor/autoload.php'; if (is_file($autoload)) require_once $autoload;
        if (!class_exists(\Mpdf\Mpdf::class)) throw new RuntimeException('Dependensi mPDF belum tersedia. Jalankan composer install di direktori plugin.');
        require_once __DIR__.'/src/WatchPdf.php';
        $id=(int)($_GET['record']??0);
        if ($id) $html=\SLiMS\Plugins\Inventory\WatchPdf::detail($watch->document($id),fn($photo)=>$watch->photo($id,(int)$photo['id']));
        else {
            $rows=$watch->inspections($filter,1,501);
            if (count($rows)>500) { http_response_code(413); throw new RuntimeException('Laporan melebihi 500 pemeriksaan. Persempit periode atau pilih ruangan.'); }
            $html=\SLiMS\Plugins\Inventory\WatchPdf::summary($filter,$watch->summary($filter,true),$rows);
        }
        $temp=SB.FLS.DS.'cache';
        $pdf=new \Mpdf\Mpdf(['tempDir'=>$temp,'exposeVersion'=>false]);
        $pdf->SetTitle('Pengawasan dan Pemeliharaan Perpustakaan'); $pdf->WriteHTML($html);
        watch_log('Print','Laporan pengawasan '.($id?'#'.$id:Supervision::json($filter)));
        $pdf->Output('pengawasan-pemeliharaan.pdf','I'); return;
    }
    WatchView::render($watch,$base,$tab,$filter,$canWrite,$csrf,$_GET);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    $expected=$e instanceof RuntimeException && !($e instanceof PDOException);
    $schema=$e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[1146,1054],true);
    $message=$schema?'Struktur pengawasan belum tersedia. Jalankan migrasi plugin hingga versi 7 melalui System → Plugins.':($expected?$e->getMessage():'Operasi pengawasan gagal. Periksa log PHP.');
    if (!$expected) error_log('Supervision error: '.$e->getMessage());
    if ($isPost) echo json_encode(['ok'=>false,'message'=>$message]);
    else { if ($tab==='photo') http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<div class="alert alert-danger">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div>'; }
}
