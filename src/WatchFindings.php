<?php
namespace SLiMS\Plugins\Inventory;

trait WatchFindings
{
    private static function findings(Supervision $watch,array $filter,int $page): void {
        $page=max(1,$page);[$where,$args]=$watch->where($filter);
        if (!empty($filter['finding_status'])) { $where.=' AND f.status=?'; $args[]=$filter['finding_status']; }
        $rows=$watch->query("SELECT f.*,r.snapshot,r.notes FROM inventory_watch_findings f JOIN inventory_watch_results r ON r.id=f.result_id JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE $where ORDER BY f.deadline,f.id LIMIT 20 OFFSET ".(($page-1)*20),$args)->fetchAll(\PDO::FETCH_ASSOC);
        echo '<div class="watch-card"><h3>Tindak lanjut</h3><div class="table-wrap"><table><thead><tr><th>Temuan</th><th>Penanggung jawab</th><th>Prioritas / tenggat</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach($rows as $r) { $s=Supervision::decode($r['snapshot']);echo '<tr><td>'.self::e($s['object']).'<br>'.self::e($r['notes']).'</td><td>'.self::e($r['assignee_name']).'</td><td>'.self::e(Supervision::PRIORITIES[$r['priority']]).'<br>'.self::e($r['deadline']).($r['status']!=='closed'&&$r['deadline']<date('Y-m-d')?' <strong class="text-danger">Lewat tenggat</strong>':'').'</td><td>'.self::badge($r['status']).'</td><td>';self::link('Buka',['tab'=>'finding','record'=>$r['id']]);echo '</td></tr>'; }
        if(!$rows)echo '<tr><td colspan="5">Belum ada temuan dalam filter ini. Temuan dibuat dari pemeriksaan yang difinalisasi dengan hasil Perlu tindakan.</td></tr>';
        echo '</tbody></table></div>';self::pagination('findings',$filter,$page,count($rows));echo '</div>';
    }
    private static function finding(Supervision $watch,int $id,bool $write): void {
        $f=$watch->row('findings',$id);$r=$watch->row('results',(int)$f['result_id']);$s=Supervision::decode($r['snapshot']);$d=$watch->document((int)$f['inspection_id']);
        echo '<div class="watch-card"><h3>Temuan #'.$id.' — '.self::e($s['object']).'</h3><p>'.self::e($d['snapshot']['room_name']).' · '.self::badge($f['status']).'</p><p>'.nl2br(self::e($r['notes'])).'</p><p>Penanggung jawab: '.self::e($f['assignee_name']).' · Prioritas '.self::e(Supervision::PRIORITIES[$f['priority']]).' · Tenggat '.self::e($f['deadline']).'</p>';
        self::link('Pemeriksaan asal',['tab'=>'inspection','record'=>$f['inspection_id']]);
        echo '<h4>Bukti temuan</h4>'; self::photos(array_values(array_filter($d['photos'],fn($photo)=>(int)$photo['result_id']===(int)$f['result_id'])));
        $actions=array_values(array_filter($d['actions'],fn($a)=>(int)$a['finding_id']===$id));$draft=null;
        $kinds=['repair'=>'Perbaikan','maintenance'=>'Pemeliharaan','none'=>'Tanpa pekerjaan'];
        foreach ($actions as $a) {
            if($a['submitted_at']===null) {$draft=$a;continue;}
            echo '<section class="watch-card"><h4>'.self::e($kinds[$a['kind']]).' · '.self::e($a['performed_date']).'</h4><p>Pelaksana: '.self::e($a['actor_name']).' · Biaya: '.self::e($a['cost']??'—').'</p><p>'.nl2br(self::e($a['description'])).'</p>';
            self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['action_id']===(int)$a['id'])));echo '</section>';
        }
        if($write&&$f['status']==='open') { self::form('finding',['id'=>$id,'version'=>$f['version'],'mode'=>'start']);self::end('Mulai dikerjakan'); }
        if($write&&$f['status']==='working') {
            self::form('finding',['id'=>$id,'version'=>$f['version']]);echo '<h4>Catatan pekerjaan</h4>';self::select('Jenis tindakan','kind',$kinds,$draft['kind']??'repair',true);self::textarea('Uraian pekerjaan / alasan tanpa pekerjaan','description',$draft['description']??'',true);self::input('Tanggal pekerjaan','performed_date',$draft['performed_date']??date('Y-m-d'),'date',true);self::input('Biaya (opsional, Rp)','cost',$draft['cost']??'','number');
            self::photos(array_values(array_filter($d['photos'],fn($p)=>$draft&&(int)$p['action_id']===(int)$draft['id'])),true);self::upload();
            echo '<p>Pelaksana dicatat sebagai pengguna yang menyimpan. Perbaikan/pemeliharaan wajib memiliki foto hasil sebelum diajukan. Bukti yang diajukan tidak dapat dihapus.</p><button class="btn btn-default" :disabled="busy" type="submit" name="mode" value="draft">Simpan draf tindakan</button><button class="btn btn-primary" :disabled="busy" type="submit" name="mode" value="submit">Ajukan verifikasi</button></form>';
        } elseif($draft) { echo '<h4>Draf tindakan</h4><p>'.nl2br(self::e($draft['description'])).'</p>';self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['action_id']===(int)$draft['id']))); }
        if($write&&$f['status']==='review') {
            self::form('finding',['id'=>$id,'version'=>$f['version']]);self::textarea('Catatan pemeriksaan hasil / alasan penolakan','notes','',true);
            echo '<p>Verifikator dicatat otomatis. Verifikasi oleh pelaksana yang sama diperbolehkan.</p><button class="btn btn-primary" :disabled="busy" type="submit" name="mode" value="verify">Verifikasi selesai</button><button class="btn btn-default" :disabled="busy" type="submit" name="mode" value="reject">Kembalikan untuk perbaikan</button></form>';
        }
        self::events(array_values(array_filter($d['events'],fn($e)=>(int)$e['finding_id']===$id)));echo '</div>';
    }
}

