/* Optional browser regression test; all HTTP responses are isolated fixtures.
 * 1. Create a temporary fixture directory and set INVENTORY_VIEW_OUTPUT.
 * 2. Run watch_integration_test.php with the usual test DB environment.
 * 3. Install Playwright outside the plugin, then run this script with
 *    INVENTORY_PLAYWRIGHT_MODULE pointing to its package directory if needed.
 *    INVENTORY_CHROMIUM optionally selects a test Chromium executable.
 *    INVENTORY_SCREENSHOT_OUTPUT optionally exports desktop/mobile screenshots.
 * The small sidebar and AJAX adapter simulate the SLiMS host; they do not
 * replace an authenticated smoke test in the deployed admin application.
 */
const {chromium}=require(process.env.INVENTORY_PLAYWRIGHT_MODULE || 'playwright');
const fs=require('fs'),path=require('path'),assert=require('node:assert/strict');
const base=path.resolve(__dirname,'..');
const views=process.env.INVENTORY_VIEW_OUTPUT;
if(!views)throw new Error('Set INVENTORY_VIEW_OUTPUT to fixtures emitted by watch_integration_test.php.');
const screenshots=process.env.INVENTORY_SCREENSHOT_OUTPUT;
if(screenshots)fs.mkdirSync(screenshots,{recursive:true});
let runningBrowser;
(async()=>{
 const browser=runningBrowser=await chromium.launch({headless:true,...(process.env.INVENTORY_CHROMIUM?{executablePath:process.env.INVENTORY_CHROMIUM}:{})});
 const page=await browser.newPage({viewport:{width:1366,height:1000}});let errors=[];let requests=[];let savedPhotos=[];
 page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://inventory.test/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname.includes('/assets/'))return route.fulfill({path:path.join(base,'assets',path.basename(url.pathname)),contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript'});
  if(route.request().method()==='POST'){
    const body=route.request().postData()||''; const field=name=>(body.match(new RegExp('name="'+name+'"\\r\\n\\r\\n([^\\r]*)'))||[])[1];
    requests.push({action:field('watch_action'),body});
    if(field('watch_action')==='sync')return route.fulfill({json:{ok:true,generated:0,more:false}});
    if(field('watch_action')==='result_photos')savedPhotos.push({id:91,result_id:field('result_id'),url:'http://inventory.test/evidence.png'});
    return route.fulfill({json:{ok:true,url:'/saved',document:{version:Number(field('version'))+1,status:'draft',photos:savedPhotos}}});
  }
  if(url.pathname==='/evidence.png')return route.fulfill({contentType:'image/png',body:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOe8AAAAASUVORK5CYII=','base64')});
  const name=url.pathname.slice(1)||'setup';const file=path.join(views,name+'.html');
  if(!fs.existsSync(file))return route.fulfill({status:404,body:'missing fixture'});
  return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;font-family:Arial,sans-serif;background:#f4f6f8}#mainContent{margin-left:220px}.test-sidebar{position:fixed;left:0;top:0;width:220px;padding:24px;box-sizing:border-box;color:#546478} @media(max-width:800px){#mainContent{margin-left:0}.test-sidebar{display:none}}</style></head><body><aside class="test-sidebar">SLiMS<br><br>Inventaris Barang<br><br>Checklist & Jadwal<br><br>Pemeriksaan<br><br>Temuan & Tindak Lanjut<br><br>Laporan</aside><main id="mainContent">'+fs.readFileSync(file,'utf8')+'</main><script>window.jQuery=()=>({simbioAJAX:url=>window.lastNavigation=url});</script></body></html>'});
 });
 for(const name of ['setup','template','schedule','inspections','findings','reports','new','inspection']){
  await page.goto('http://inventory.test/'+name);await page.waitForFunction(()=>window.Alpine && window.InventoryUI?.registered);await page.waitForTimeout(150);
  assert.deepEqual(errors,[],name+' Alpine initialization');
  if(name==='setup'){assert(await page.getByRole('heading',{name:'Jadwal pemeriksaan',exact:true}).isVisible());await page.getByRole('tab',{name:'Template checklist',exact:true}).click();assert(await page.getByRole('heading',{name:'Template checklist',exact:true}).isVisible());}
  if(name==='template'){const rows=page.locator('.inv-checklist-row');const count=await rows.count();await page.getByRole('button',{name:'+ Tambah butir',exact:true}).click();assert.equal(await rows.count(),count+1);await rows.last().getByRole('button',{name:'Hapus butir'}).click();await page.getByRole('button',{name:'Lanjutkan',exact:true}).click();assert.equal(await rows.count(),count);}
  if(name==='schedule'){await page.getByRole('button',{name:'Lanjutkan',exact:true}).click();await page.getByText('Hubungkan setiap butir').waitFor({state:'visible',timeout:3000});await page.getByRole('button',{name:'Lanjutkan',exact:true}).click();await page.getByLabel('Tanggal mulai',{exact:true}).fill('2026-09-10');await page.getByRole('button',{name:'Sebelumnya'}).click();await page.getByRole('button',{name:'Lanjutkan',exact:true}).click();assert.equal(await page.getByLabel('Tanggal mulai',{exact:true}).inputValue(),'2026-09-10');}
  if(name==='inspection'){await page.getByLabel(/^Hasil pemeriksaan/).first().selectOption('action');await page.getByRole('heading',{name:'Penugasan tindak lanjut'}).first().waitFor({state:'visible'});assert.match(await page.locator('.inv-progress').innerText(),/1 \/ 4/);
    requests=[];
    await page.getByLabel('Tambah foto',{exact:true}).first().setInputFiles({name:'proof.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aOe8AAAAASUVORK5CYII=','base64')});
    await page.getByRole('button',{name:'Simpan foto butir',exact:true}).first().click();
    await page.getByText('Foto butir tersimpan.',{exact:true}).first().waitFor({state:'visible',timeout:5000});
    assert.deepEqual(requests.map(r=>r.action),['inspection','result_photos']);
    assert.equal(await page.getByLabel(/^Hasil pemeriksaan/).first().inputValue(),'action');
    assert.equal(await page.locator('.inv-evidence img[alt="Foto bukti pemeriksaan"]').count(),1);
    assert.deepEqual(errors,[]);
}
  if(screenshots)await page.screenshot({path:path.join(screenshots,name+'-desktop.png'),fullPage:true});
  await page.setViewportSize({width:390,height:844});
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),name+' mobile overflow');
  if(screenshots)await page.screenshot({path:path.join(screenshots,name+'-mobile.png'),fullPage:true});
  await page.setViewportSize({width:1366,height:1000});console.log('ok '+name+' desktop, mobile, Alpine');
 }
 const fixture=fs.readFileSync(path.join(views,'setup.html'),'utf8');
 for(let n=0;n<3;n++){
   await page.evaluate(html=>document.getElementById('mainContent').innerHTML=html,fixture);
   await page.getByRole('tab',{name:'Template checklist',exact:true}).click();
   await page.getByRole('heading',{name:'Template checklist',exact:true}).waitFor({state:'visible'});
 }
 assert.equal(await page.locator('script[src*="alpine-3"]').count(),1);assert.equal(await page.locator('dialog').count(),1);
 assert.deepEqual(errors,[]);console.log('ok serial photo save and repeated AJAX fragment initialization');await browser.close();
})().catch(async e=>{console.error(e);if(runningBrowser)await runningBrowser.close();process.exitCode=1;});
