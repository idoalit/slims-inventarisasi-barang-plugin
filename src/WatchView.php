<?php
namespace SLiMS\Plugins\Inventory;

final class WatchView
{
    private static string $base, $csrf;
    private static array $users;
    public static function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
    private static function url(array $params=[]): string { return self::$base.($params?'&'.http_build_query($params):''); }
    private static function link(string $label,array $params=[],bool $pdf=false): void { echo '<a class="btn btn-sm btn-default '.($pdf?'notAJAX':'').'" '.($pdf?'target="_blank" rel="noopener"':'').' href="'.self::e(self::url($params)).'">'.self::e($label).'</a> '; }
    private static function hidden(string $name,$value): void { echo '<input type="hidden" name="'.self::e($name).'" value="'.self::e($value).'">'; }
    private static function form(string $action,array $hidden=[]): void {
        echo '<form class="watch-form" method="post" enctype="multipart/form-data" action="'.self::e(self::$base).'" onsubmit="return inventoryWatchSave(event,this)">';
        self::hidden('csrf_token',self::$csrf); self::hidden('watch_action',$action);
        foreach ($hidden as $name=>$value) self::hidden($name,$value);
        echo '<div class="alert alert-danger watch-error" role="alert" hidden></div>';
    }
    private static function end(string $button='Simpan'): void { echo '<button class="btn btn-primary" type="submit">'.self::e($button).'</button></form>'; }
    private static function input(string $label,string $name,$value='',string $type='text',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<input class="form-control" type="'.self::e($type).'" name="'.self::e($name).'" value="'.self::e($value).'" '.($required?'required':'').' '.($type==='number'?'min="0" step="0.01"':'').'></label>';
    }
    private static function textarea(string $label,string $name,$value='',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<textarea class="form-control" rows="2" name="'.self::e($name).'" '.($required?'required':'').'>'.self::e($value).'</textarea></label>';
    }
    private static function select(string $label,string $name,array $options,$selected='',bool $required=false): void {
        echo '<label class="watch-field">'.self::e($label).'<select class="form-control" name="'.self::e($name).'" '.($required?'required':'').'>';
        foreach ($options as $key=>$text) echo '<option value="'.self::e($key).'" '.((string)$key===(string)$selected?'selected':'').'>'.self::e($text).'</option>';
        echo '</select></label>';
    }
    private static function actorOptions(): array { return [''=>'Pilih petugas']+array_column(self::$users,'realname','user_id'); }
    private static function status(string $value): string { return Supervision::STATUSES[$value]??$value; }
    private static function photos(array $rows,bool $editable=false): void {
        echo '<div class="watch-photos">';
        foreach ($rows as $photo) {
            $url=self::url(['tab'=>'photo','inspection_id'=>$photo['inspection_id'],'photo_id'=>$photo['id']]);
            echo '<div><a class="notAJAX" href="'.self::e($url).'" target="_blank" rel="noopener"><img loading="lazy" alt="Bukti pengawasan" src="'.self::e($url).'"></a>';
            if ($editable) echo '<label><input type="checkbox" name="remove[]" value="'.(int)$photo['id'].'"> Hapus foto</label>';
            echo '</div>';
        }
        echo '</div>';
    }
    private static function upload(): void { echo '<label class="watch-field">Foto bukti<input type="file" class="form-control" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label><p class="text-muted">Maksimal 5 foto per hasil/catatan, masing-masing 2 MB. JPEG, PNG, atau WebP.</p>'; }
    public static function render(Supervision $watch,string $base,string $tab,array $filter,bool $write,string $csrf,array $get): void {
        self::$base=$base; self::$csrf=$csrf;
        self::$users=$watch->query('SELECT user_id,realname FROM user ORDER BY realname')->fetchAll(\PDO::FETCH_ASSOC);
        $rooms=$watch->query('SELECT id,room_name,slims_location_id FROM inventory_locations ORDER BY room_name')->fetchAll(\PDO::FETCH_ASSOC);
        $libraries=$watch->query('SELECT location_id,location_name FROM mst_location ORDER BY location_name')->fetchAll(\PDO::FETCH_KEY_PAIR);
        $templates=$watch->query('SELECT * FROM inventory_watch_templates ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        ?>
<style>
.watch{padding:1rem;max-width:1400px}.watch nav{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}.watch-card{border:1px solid #ddd;border-radius:6px;padding:1rem;margin:1rem 0;background:#fff}.watch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:1rem}.watch-field{display:block;margin-bottom:.8rem}.watch table{width:100%;border-collapse:collapse}.watch th,.watch td{padding:.5rem;border-bottom:1px solid #ddd;vertical-align:top}.watch .table-wrap{overflow-x:auto}.watch h3{font-size:1.15rem;margin:1rem 0}.watch h4{font-size:1rem}.watch-photos{display:flex;gap:.7rem;flex-wrap:wrap;margin:.5rem 0}.watch-photos img{width:120px;height:100px;object-fit:contain}.watch-photos label{display:block}.watch-metric{border:1px solid #ddd;padding:.8rem;border-radius:5px}.watch-metric strong{display:block;font-size:1.5rem}.watch-form{margin-bottom:1rem}.watch-filter{padding:.7rem;background:#f7f7f7}.watch details{margin:.7rem 0}.watch button{margin-right:.4rem}
</style>
<div class="watch" id="inventory-watch" data-base="<?=self::e($base)?>" data-csrf="<?=self::e($csrf)?>" data-write="<?=$write?'1':'0'?>">
<h2>Pengawasan &amp; Pemeliharaan</h2>
<nav><?php foreach (['dashboard'=>'Ringkasan','setup'=>'Checklist & Jadwal','inspections'=>'Pemeriksaan','findings'=>'Tindak Lanjut','reports'=>'Laporan'] as $key=>$name) self::link($name,array_merge($filter,['tab'=>$key])); ?></nav>
<div class="watch-sync-status text-muted" role="status"></div>
<form class="watch-filter watch-grid" method="get" onsubmit="event.preventDefault();inventoryWatchFilter(this)">
<?php self::hidden('tab',in_array($tab,['dashboard','setup','inspections','findings','reports'],true)?$tab:'dashboard'); self::select('Perpustakaan','library',[''=>'Semua perpustakaan']+$libraries,$filter['library']); self::select('Ruangan','room',[''=>'Semua ruangan']+array_column($rooms,'room_name','id'),$filter['room']); self::input('Dari','from',$filter['from'],'date',true); self::input('Sampai','to',$filter['to'],'date',true); ?>
<div><button class="btn btn-default" type="submit">Terapkan filter</button></div></form>
<?php
        if ($tab==='setup') self::setup($watch,$rooms,$templates,$write,$get,$filter);
        elseif ($tab==='inspection') self::inspection($watch,(int)($get['record']??0),$write);
        elseif ($tab==='finding') self::finding($watch,(int)($get['record']??0),$write);
        elseif ($tab==='new' && $write) self::newInspection($watch,$rooms,$templates,$get);
        elseif ($tab==='inspections') self::listing($watch,$filter,$write,(int)($get['page']??1));
        elseif ($tab==='findings') self::findings($watch,$filter,(int)($get['page']??1));
        else {
            $summary=$watch->summary($filter); self::metrics($summary);
            if ($tab==='reports') {
                echo '<div class="watch-card"><h3>Laporan periode</h3><p>Periode berdasarkan tanggal jadwal (atau tanggal pencatatan insidental). Tidak ada penilaian a–d otomatis.</p>';
                self::link('Cetak PDF periode',array_merge($filter,['tab'=>'pdf']),true); echo '</div>';
                self::listing($watch,$filter,false,(int)($get['page']??1));
            }
        }
        ?>
</div>
<script>
window.inventoryWatchFilter=function(form){const query=new URLSearchParams(new FormData(form));jQuery('#mainContent').simbioAJAX(document.getElementById('inventory-watch').dataset.base+'&'+query.toString());};
window.inventoryWatchSave=function(event,form){
 event.preventDefault();event.stopImmediatePropagation();if(form.dataset.busy==='1')return false;
 if(form.dataset.confirm && !confirm(form.dataset.confirm))return false;
 const data=new FormData(form);if(event.submitter && event.submitter.name)data.set(event.submitter.name,event.submitter.value);
 const error=form.querySelector('.watch-error');error.hidden=true;form.dataset.busy='1';
 const controls=Array.from(form.querySelectorAll('button'));controls.forEach(b=>b.disabled=true);
 fetch(form.action,{method:'POST',body:data,credentials:'same-origin'}).then(r=>r.json()).then(r=>{if(!r.ok)throw new Error(r.message);jQuery('#mainContent').simbioAJAX(r.url);}).catch(e=>{error.textContent=e instanceof SyntaxError?'Respons tidak dapat dibaca. Periksa sesi dan batas unggah server.':e.message;error.hidden=false;}).finally(()=>{delete form.dataset.busy;controls.forEach(b=>b.disabled=false);});return false;
};
window.inventoryWatchAddRow=function(button){const body=button.closest('form').querySelector('tbody');const row=body.lastElementChild.cloneNode(true);const index=body.children.length;row.querySelectorAll('[name]').forEach(field=>{field.name=field.name.replace(/items\[\d+\]/,'items['+index+']');if(field.tagName==='SELECT')field.selectedIndex=0;else field.value='';});body.append(row);};
// No mutation on GET. The authorized writer explicitly posts the bounded catch-up job.
(async function(){const root=document.getElementById('inventory-watch');if(!root||root.dataset.write!=='1'||window.inventoryWatchSyncBusy)return;window.inventoryWatchSyncBusy=true;const status=root.querySelector('.watch-sync-status');let generated=0;
try{let more=true;while(more&&root.isConnected){const data=new FormData();data.set('watch_action','sync');data.set('csrf_token',root.dataset.csrf);const result=await fetch(root.dataset.base,{method:'POST',body:data,credentials:'same-origin'}).then(r=>r.json());if(!result.ok)throw new Error(result.message);generated+=result.generated;more=result.more;status.textContent=generated+' pemeriksaan jatuh tempo dibentuk.';}if(generated&&root.isConnected){const a=document.createElement('a');a.href='#';a.textContent=' Perbarui tampilan';a.onclick=e=>{e.preventDefault();jQuery('#mainContent').simbioAJAX(root.dataset.base+'&'+new URLSearchParams(<?=json_encode(array_merge($filter,['tab'=>$tab]),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>).toString());};status.append(a);}}
catch(e){status.textContent='Jadwal belum disinkronkan: '+(e instanceof SyntaxError?'periksa sesi login.':e.message);}finally{window.inventoryWatchSyncBusy=false;}})();
</script>
<?php
    }
    public static function metrics(array $summary): void {
        $c=$summary['counts'];$f=$summary['findings'];
        echo '<div class="watch-card"><h3>Jadwal dan tindak lanjut</h3><div class="watch-grid">';
        foreach (['Rencana rutin dalam periode'=>$summary['planned'],'Rutin difinalisasi'=>$c['routine_final'],'Insidental tercatat'=>$c['incidental'],'Pemeriksaan difinalisasi'=>$c['finalized'],'Pemeriksaan terlambat'=>(int)$c['late']+$summary['unformed_late'],'Jatuh tempo belum dibentuk'=>$summary['unformed'],'Temuan belum selesai'=>$f['open'],'Temuan lewat tenggat'=>$f['late'],'Selesai terverifikasi'=>$f['closed']] as $label=>$value) echo '<div class="watch-metric">'.self::e($label).'<strong>'.self::e($value).'</strong></div>';
        echo '</div><p class="text-muted">Temuan mengikuti periode pemeriksaan asal; status tindak lanjut adalah status saat ini.</p></div>';
        echo '<div class="watch-card"><h3>Cakupan pemeriksaan rutin</h3><p>Ruangan diperiksa: <strong>'.$summary['room_examined'].' / '.$summary['room_total'].'</strong>. Butir diperiksa: <strong>'.$summary['item_examined'].' / '.$summary['item_applicable'].'</strong>. Tidak berlaku: <strong>'.$summary['item_na'].'</strong>.</p><p class="text-muted">Butir dihitung dari pemeriksaan terbentuk dan jadwal yang sudah jatuh tempo. Hanya hasil final dihitung diperiksa; Tidak diperiksa tetap masuk penyebut. Pemeriksaan insidental dilaporkan terpisah.</p><h4>Ruangan tanpa jadwal dalam periode</h4>';
        if (!$summary['missing_rooms']) echo '<p>Tidak ada.</p>';
        foreach ($summary['missing_rooms'] as $room) echo '<p>'.self::e($room['room_name']).' — '.self::e($room['location_name']??'Tidak ditentukan').'</p>';
        echo '</div>';
    }
    private static function setup(Supervision $watch,array $rooms,array $templates,bool $write,array $get,array $filter): void {
        echo '<div class="watch-card"><h3>Template checklist</h3><p>Setiap perubahan disimpan sebagai versi baru. Jadwal yang sudah ada memakai checklist versi sebelumnya; gunakan Ganti jadwal untuk menerapkan versi baru.</p>';
        foreach ($templates as $template) { echo '<p>#'.(int)$template['id'].' '.self::e($template['name']).' '; if($write)self::link('Salin / revisi',['tab'=>'setup','template_id'=>$template['id']]); echo '</p>'; }
        if ($write) {
            $selected=!empty($get['template_id'])?$watch->row('templates',(int)$get['template_id']):null;
            $items=$selected?Supervision::decode($selected['items']):[
                ['group'=>'Sarana','object'=>'Meja, kursi, dan rak','instruction'=>'Periksa kestabilan, kelengkapan, dan kerusakan.'],
                ['group'=>'Prasarana','object'=>'Lantai, atap, dan pintu','instruction'=>'Periksa kebocoran, kerusakan, dan hambatan akses.'],
                ['group'=>'Lingkungan Fisik','object'=>'Kebersihan dan kenyamanan','instruction'=>'Periksa kebersihan, pencahayaan, ventilasi, kelembapan, kebisingan, dan jalur keluar.']];
            echo '<details '.($selected?'open':'').'><summary>Buat template / salin contoh</summary><p>Contoh dapat disesuaikan; bukan standar penilaian resmi. Pisahkan objek menjadi butir sesuai cakupan yang perlu diperiksa.</p>';
            self::form('template',['source_id'=>$selected['id']??0]); self::input('Nama template','name',$selected?$selected['name'].' (revisi)':'Checklist pemeriksaan ruangan','text',true);
            echo '<div class="table-wrap"><table><thead><tr><th>Kelompok</th><th>Objek</th><th>Petunjuk</th></tr></thead><tbody>';
            $items[]=['group'=>'Sarana','object'=>'','instruction'=>''];
            foreach ($items as $i=>$item) { echo '<tr><td>';self::select('','items['.$i.'][group]',array_combine(['Sarana','Prasarana','Lingkungan Fisik'],['Sarana','Prasarana','Lingkungan Fisik']),$item['group']);echo '</td><td>';self::input('','items['.$i.'][object]',$item['object']);echo '</td><td>';self::textarea('','items['.$i.'][instruction]',$item['instruction']);echo '</td></tr>'; }
            echo '</tbody></table></div><button type="button" class="btn btn-default" onclick="inventoryWatchAddRow(this)">Tambah butir</button>';self::end('Simpan versi template');echo '</details>';
        }
        echo '</div><div class="watch-card"><h3>Jadwal pemeriksaan</h3>';
        if ($write) self::scopeForm($watch,$rooms,$templates,$get,false);
        $schedules=$watch->query('SELECT * FROM inventory_watch_schedules ORDER BY id DESC')->fetchAll(\PDO::FETCH_ASSOC);
        echo '<div class="table-wrap"><table><thead><tr><th>Ruangan / template</th><th>Frekuensi / petugas</th><th>Periode / status</th><th>Tindakan</th></tr></thead><tbody>';
        foreach ($schedules as $schedule) {
            $s=Supervision::decode($schedule['snapshot']);
            if (($filter['library']!=='' && $s['library_code']!==$filter['library'])||($filter['room'] && (int)$s['room_id']!==$filter['room'])) continue;
            if ($schedule['start_date']>$filter['to'] || ($schedule['end_date'] && $schedule['end_date']<$filter['from'])) continue;
            $active=$schedule['active'] && $schedule['location_id']!==null && (!$schedule['end_date']||$schedule['end_date']>=date('Y-m-d'));
            echo '<tr><td>#'.(int)$schedule['id'].' '.self::e($s['room_name']).'<br>'.self::e($s['template_name']).'</td><td>'.self::e(WatchRecurrence::FREQUENCIES[$schedule['frequency']]).'<br>'.self::e($schedule['assignee_name']).'</td><td>'.self::e($schedule['start_date']).' — '.self::e($schedule['end_date']?:'berlanjut').'<br>'.($active?'Aktif':'Tidak aktif').'</td><td>';
            if ($write && $active) {
                self::link('Ganti jadwal',['tab'=>'setup','replaces_id'=>$schedule['id'],'location_id'=>$schedule['location_id'],'template_id'=>$schedule['template_id']]);
                echo '<details><summary>Hentikan</summary>';self::form('stop',['id'=>$schedule['id'],'version'=>$schedule['version']]);self::input('Tidak dijadwalkan mulai','effective',date('Y-m-d',strtotime('+1 day')),'date',true);self::end('Hentikan jadwal');echo '</details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
    private static function scopeForm(Supervision $watch,array $rooms,array $templates,array $get,bool $incidental): void {
        echo '<h4>'.($incidental?'Pemeriksaan insidental / ulang':'Buat / ganti jadwal').'</h4>';
        if (!$rooms || !$templates) { echo '<p>Tambahkan ruangan dan template checklist terlebih dahulu.</p>';return; }
        $location=(int)($get['location_id']??$rooms[0]['id']);$template=(int)($get['template_id']??$templates[0]['id']);
        echo '<form method="get" class="watch-grid" onsubmit="event.preventDefault();inventoryWatchFilter(this)">'; self::hidden('tab',$incidental?'new':'setup');self::hidden('replaces_id',$get['replaces_id']??0);self::hidden('parent_id',$get['parent_id']??0);
        self::select('Ruangan','location_id',array_column($rooms,'room_name','id'),$location,true); self::select('Template','template_id',array_column($templates,'name','id'),$template,true);echo '<div><button class="btn btn-default" type="submit">Pilih cakupan</button></div></form>';
        $chosen=$watch->row('templates',$template);$items=Supervision::decode($chosen['items']);
        $assets=$watch->query('SELECT id,CONCAT(item_name,\' [\',item_code,\']\') AS label FROM inventory_items WHERE location_id=? ORDER BY item_name',[$location])->fetchAll(\PDO::FETCH_KEY_PAIR);
        $old=!empty($get['replaces_id'])?$watch->row('schedules',(int)$get['replaces_id']):null;
        $mapping=$old?Supervision::decode($old['snapshot'])['items']:[];
        self::form($incidental?'incidental':'schedule',['location_id'=>$location,'template_id'=>$template,'replaces_id'=>$old['id']??0,'version'=>$old['version']??0,'parent_id'=>$get['parent_id']??0]);
        echo '<div class="watch-grid">';
        if ($incidental) self::textarea('Alasan pemeriksaan','reason','',true);
        else {
            self::select('Frekuensi','frequency',WatchRecurrence::FREQUENCIES,$old['frequency']??'monthly',true);
            self::input($old?'Mulai versi baru (tanggal efektif)':'Tanggal mulai','start_date',$old?date('Y-m-d',strtotime('+1 day')):date('Y-m-d'),'date',true);
            self::input('Tanggal akhir (opsional)','end_date','','date');
            self::select('Penanggung jawab','assignee_id',self::actorOptions(),$old['assignee_id']??($_SESSION['uid']??''),true);
        }
        echo '</div><p>Hubungkan setiap butir dengan barang, atau pilih Aspek ruangan. Cakupan disimpan sebagai bagian dari versi jadwal.</p>';
        foreach ($items as $i=>$item) self::select($item['group'].' — '.$item['object'],'mapping['.$i.']',[''=>'Aspek ruangan']+$assets,($old && (int)$old['template_id']===$template)?($mapping[$i]['item_id']??''):'');
        self::end($incidental?'Buat pemeriksaan':'Simpan jadwal');
    }
    private static function newInspection(Supervision $watch,array $rooms,array $templates,array $get): void { echo '<div class="watch-card">';self::scopeForm($watch,$rooms,$templates,$get,true);echo '</div>'; }
    private static function listing(Supervision $watch,array $filter,bool $write,int $page): void {
        $page=max(1,$page);$rows=$watch->inspections($filter,$page,20);
        echo '<div class="watch-card"><h3>Pemeriksaan</h3>';if($write)self::link('Pemeriksaan insidental',['tab'=>'new']);
        echo '<div class="table-wrap"><table><thead><tr><th>Tanggal jadwal</th><th>Ruangan</th><th>Jenis / status</th><th>Pelaksanaan</th><th></th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $s=Supervision::decode($row['snapshot']);
            echo '<tr><td>'.self::e($row['due_date']).'</td><td>'.self::e($s['library_name'].' / '.$s['room_name']).'</td><td>'.($row['kind']==='routine'?'Terjadwal':'Insidental').' / '.self::e(self::status($row['status'])).($row['status']!=='final'&&$row['due_date']<date('Y-m-d')?' <strong class="text-danger">Terlambat</strong>':'').'</td><td>'.self::e($row['performed_date']??'—').'<br>'.self::e($row['examiner_name']??'').'</td><td>';self::link('Buka',['tab'=>'inspection','record'=>$row['id']]);self::link('PDF',['tab'=>'pdf','record'=>$row['id']],true);echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5">Belum ada pemeriksaan dalam filter ini.</td></tr>';
        echo '</tbody></table></div>'; self::pagination('inspections',$filter,$page,count($rows));echo '</div>';
    }
    private static function pagination(string $tab,array $filter,int $page,int $count): void {
        if($page>1)self::link('Sebelumnya',array_merge($filter,['tab'=>$tab,'page'=>$page-1]));
        if($count===20)self::link('Berikutnya',array_merge($filter,['tab'=>$tab,'page'=>$page+1]));
    }
    private static function inspection(Supervision $watch,int $id,bool $write): void {
        $d=$watch->document($id);$i=$d['inspection'];$s=$d['snapshot'];$editable=$write&&$i['status']!=='final';
        echo '<div class="watch-card"><h3>Pemeriksaan #'.$id.' — '.self::e($s['room_name']).'</h3><p>'.self::e($s['library_name'].' / '.$s['template_name']).'</p><p>'.self::e(self::status($i['status'])).' · Jadwal '.self::e($i['due_date']).' · '.($i['kind']==='routine'?'Terjadwal':'Insidental').'</p>';
        if ($i['reason']) echo '<p>Alasan: '.self::e($i['reason']).'</p>';
        if($i['parent_id'])self::link('Pemeriksaan asal',['tab'=>'inspection','record'=>$i['parent_id']]);
        self::link('Cetak detail PDF',['tab'=>'pdf','record'=>$id],true);
        if($write&&$i['status']==='final'&&$i['location_id'])self::link('Pemeriksaan ulang',['tab'=>'new','parent_id'=>$id,'location_id'=>$i['location_id'],'template_id'=>$s['template_id']]);
        if($editable) {
            self::form('inspection',['id'=>$id,'version'=>$i['version']]);self::input('Tanggal pelaksanaan sebenarnya','performed_date',$i['performed_date']??date('Y-m-d'),'date');self::textarea('Catatan pemeriksaan','notes',$i['notes']);
        } else echo '<p>Pelaksanaan: '.self::e($i['performed_date']??'—').' · Pemeriksa: '.self::e($i['examiner_name']??'—').'</p><p>'.nl2br(self::e($i['notes'])).'</p>';
        foreach ($d['results'] as $r) {
            $item=Supervision::decode($r['snapshot']);
            echo '<section class="watch-card"><h4>'.self::e($item['group'].' — '.$item['object']).'</h4><p>'.self::e($item['instruction']).'</p><p>'.self::e($item['item_id']?$item['item_name'].' ['.$item['item_code'].']':'Aspek ruangan').'</p>';
            if($editable) {
                $p='results['.$r['id'].']';self::select('Hasil',$p.'[outcome]',[''=>'Belum diisi']+Supervision::OUTCOMES,$r['outcome']);self::textarea('Catatan / alasan',$p.'[notes]',$r['notes']);
                echo '<details '.($r['outcome']==='action'?'open':'').'><summary>Penugasan temuan (wajib jika Perlu tindakan)</summary><div class="watch-grid">';
                self::select('Penanggung jawab',$p.'[assignee_id]',self::actorOptions(),$r['assignee_id']??'');self::select('Prioritas',$p.'[priority]',[''=>'Pilih prioritas']+Supervision::PRIORITIES,$r['priority']??'');self::input('Tenggat',$p.'[deadline]',$r['deadline']??'','date');echo '</div></details>';
            } else echo '<p><strong>'.self::e(Supervision::OUTCOMES[$r['outcome']]??'Belum diisi').'</strong></p><p>'.nl2br(self::e($r['notes'])).'</p>';
            self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['result_id']===(int)$r['id'])));
            echo '</section>';
        }
        if($editable) echo '<button class="btn btn-default" type="submit" name="submit_mode" value="draft">Simpan draf</button><button class="btn btn-primary" type="submit" name="submit_mode" value="final" onclick="return confirm(\'Finalisasi mengunci hasil dan bukti. Lanjutkan?\')">Finalisasi pemeriksaan</button></form>';
        if($editable) {
            echo '<details><summary>Kelola foto bukti per butir</summary><p>Simpan draf isian terlebih dahulu sebelum mengelola foto. Setiap penyimpanan foto memuat ulang dokumen.</p>';
            foreach ($d['results'] as $r) {
                $item=Supervision::decode($r['snapshot']);echo '<h4>'.self::e($item['object']).'</h4>';
                self::form('result_photos',['inspection_id'=>$id,'result_id'=>$r['id'],'version'=>$i['version']]);
                self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['result_id']===(int)$r['id'])),true);self::upload();self::end('Simpan foto butir');
            }
            echo '</details>';
        }
        foreach ($d['findings'] as $f) { echo '<p>Temuan #'.(int)$f['id'].' — '.self::e(self::status($f['status'])).' ';self::link('Tindak lanjut',['tab'=>'finding','record'=>$f['id']]);echo '</p>'; }
        if($write&&$i['status']==='final') { echo '<details><summary>Tambahkan catatan koreksi</summary>';self::form('correction',['id'=>$id]);self::textarea('Catatan koreksi (hasil asli tetap disimpan)','notes','',true);self::end('Tambahkan koreksi');echo '</details>'; }
        self::events($d['events']); echo '</div>';
    }
    private static function events(array $events): void {
        echo '<h4>Riwayat kegiatan</h4>';
        $names=['start'=>'Mulai pekerjaan','save_draft'=>'Simpan draf','finalize'=>'Finalisasi','correction'=>'Koreksi','save_action'=>'Simpan tindakan','submit'=>'Ajukan verifikasi','verify'=>'Verifikasi diterima','reject'=>'Verifikasi ditolak'];
        foreach ($events as $event) echo '<p><strong>'.self::e($names[$event['event']]??$event['event']).'</strong> — '.self::e($event['actor_name']).' · '.self::e($event['created_at']).'<br>'.nl2br(self::e($event['notes'])).'</p>';
    }
    private static function findings(Supervision $watch,array $filter,int $page): void {
        $page=max(1,$page);[$where,$args]=$watch->where($filter);
        $rows=$watch->query("SELECT f.*,r.snapshot,r.notes FROM inventory_watch_findings f JOIN inventory_watch_results r ON r.id=f.result_id JOIN inventory_watch_inspections i ON i.id=f.inspection_id WHERE $where ORDER BY f.deadline,f.id LIMIT 20 OFFSET ".(($page-1)*20),$args)->fetchAll(\PDO::FETCH_ASSOC);
        echo '<div class="watch-card"><h3>Tindak lanjut</h3><div class="table-wrap"><table><thead><tr><th>Temuan</th><th>Penanggung jawab</th><th>Prioritas / tenggat</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach($rows as $r) { $s=Supervision::decode($r['snapshot']);echo '<tr><td>'.self::e($s['object']).'<br>'.self::e($r['notes']).'</td><td>'.self::e($r['assignee_name']).'</td><td>'.self::e(Supervision::PRIORITIES[$r['priority']]).'<br>'.self::e($r['deadline']).($r['status']!=='closed'&&$r['deadline']<date('Y-m-d')?' <strong class="text-danger">Lewat tenggat</strong>':'').'</td><td>'.self::e(self::status($r['status'])).'</td><td>';self::link('Buka',['tab'=>'finding','record'=>$r['id']]);echo '</td></tr>'; }
        if(!$rows)echo '<tr><td colspan="5">Belum ada temuan.</td></tr>';
        echo '</tbody></table></div>';self::pagination('findings',$filter,$page,count($rows));echo '</div>';
    }
    private static function finding(Supervision $watch,int $id,bool $write): void {
        $f=$watch->row('findings',$id);$r=$watch->row('results',(int)$f['result_id']);$s=Supervision::decode($r['snapshot']);$d=$watch->document((int)$f['inspection_id']);
        echo '<div class="watch-card"><h3>Temuan #'.$id.' — '.self::e($s['object']).'</h3><p>'.self::e($d['snapshot']['room_name']).' · '.self::e(self::status($f['status'])).'</p><p>'.nl2br(self::e($r['notes'])).'</p><p>Penanggung jawab: '.self::e($f['assignee_name']).' · Prioritas '.self::e(Supervision::PRIORITIES[$f['priority']]).' · Tenggat '.self::e($f['deadline']).'</p>';
        self::link('Pemeriksaan asal',['tab'=>'inspection','record'=>$f['inspection_id']]);
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
            echo '<p>Pelaksana dicatat sebagai pengguna yang menyimpan. Perbaikan/pemeliharaan wajib memiliki foto hasil sebelum diajukan. Bukti yang diajukan tidak dapat dihapus.</p><button class="btn btn-default" type="submit" name="mode" value="draft">Simpan draf tindakan</button><button class="btn btn-primary" type="submit" name="mode" value="submit">Ajukan verifikasi</button></form>';
        } elseif($draft) { echo '<h4>Draf tindakan</h4><p>'.nl2br(self::e($draft['description'])).'</p>';self::photos(array_values(array_filter($d['photos'],fn($p)=>(int)$p['action_id']===(int)$draft['id']))); }
        if($write&&$f['status']==='review') {
            self::form('finding',['id'=>$id,'version'=>$f['version']]);self::textarea('Catatan pemeriksaan hasil / alasan penolakan','notes','',true);
            echo '<p>Verifikator dicatat otomatis. Verifikasi oleh pelaksana yang sama diperbolehkan.</p><button class="btn btn-primary" type="submit" name="mode" value="verify">Verifikasi selesai</button><button class="btn btn-default" type="submit" name="mode" value="reject">Kembalikan untuk perbaikan</button></form>';
        }
        self::events(array_values(array_filter($d['events'],fn($e)=>(int)$e['finding_id']===$id)));echo '</div>';
    }
}
