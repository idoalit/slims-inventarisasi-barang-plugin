<?php
namespace SLiMS\Plugins\Inventory;

/** Session-scoped replay protection. PHP's session lock serializes duplicate requests. */
final class WorkspaceRequests
{
    public static function cached(string $scope): ?array
    {
        $id=(string)($_POST['request_id']??'');
        if($id==='')return null;
        if(!preg_match('/\A[a-f0-9-]{36}\z/',$id))throw new \RuntimeException('Identitas permintaan tidak valid.');
        return $_SESSION['inventory_workspace_requests'][$scope.':'.$id]??null;
    }
    public static function remember(string $scope,array $reply): array
    {
        $id=(string)($_POST['request_id']??'');
        if($id!==''&&($reply['ok']??false)){
            $_SESSION['inventory_workspace_requests'][$scope.':'.$id]=$reply;
            if(count($_SESSION['inventory_workspace_requests'])>500)array_shift($_SESSION['inventory_workspace_requests']);
        }
        return $reply;
    }
    public static function errors(string $message): array
    {
        $map=['Kode barang'=>'item_code','kode barang'=>'item_code','Tahun'=>'acquisition_year','Harga'=>'acquisition_price','Kondisi'=>'item_condition','Nama ruangan'=>'room_name','Tanggal pelaksanaan'=>'performed_date','Biaya'=>'cost','Uraian'=>'description','Alasan'=>'notes','alasan'=>'notes'];
        foreach($map as $needle=>$field)if(str_contains($message,$needle))return [$field=>$message];
        return ['_form'=>$message];
    }
}
