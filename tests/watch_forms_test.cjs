const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/inventory.js'),'utf8');
class Data {
 constructor(form){this.values=new Map(Object.entries(form?.fields||{}));}
 set(k,v){this.values.set(k,String(v));} get(k){return this.values.get(k);} append(k,v){this.values.set(k,v);}
}
const providers={},stores={};let navigation='';
const context={window:{addEventListener(){}},document:{currentScript:{dataset:{alpine:'/alpine.js'}},addEventListener(){},createElement:()=>({setAttribute(){}}),body:{appendChild(){}}},Alpine:{data:(name,value)=>providers[name]=value,store:(name,value)=>value?(stores[name]=value):stores[name]},FormData:Data,URL:{revokeObjectURL(){}},location:{href:'http://inventory.test'},jQuery:()=>({simbioAJAX:url=>navigation=url}),fetch:async()=>({ok:true,json:async()=>({ok:true})}),console};
context.window.Alpine=context.Alpine;vm.createContext(context);vm.runInContext(source,context);
const UI=context.window.InventoryUI;
const form={action:'/inspection',fields:{watch_action:'inspection',csrf_token:'csrf',id:'7',version:'1'},elements:{namedItem:name=>({get value(){return form.fields[name]||''},set value(value){form.fields[name]=value}})},setAttribute(){},removeAttribute(){},querySelectorAll:()=>[],querySelector:()=>null};
function inspection(){return Object.assign(providers.inventoryInspection({id:7,version:1,results:{11:{outcome:'good'},12:{outcome:'good'}},evidence:{11:{photos:[],files:['photo1'],remove:[],previews:[]},12:{photos:[],files:['photo2'],remove:[],previews:[]}}}),{$el:form,form,$dispatch(){},$nextTick:fn=>fn()});}
(async()=>{
 let requests=[];let version=1;UI.confirm=async()=>true;
 UI.request=async(url,data)=>{requests.push({action:data.get('watch_action'),version:data.get('version'),mode:data.get('submit_mode'),id:data.get('result_id')});return {ok:true,url:'/final',document:{version:++version,status:'draft',photos:[]}};};
 let state=inspection();state.validateFinal=()=>true;
 await state.run('final');assert.deepEqual(requests.map(r=>r.action),['inspection','result_photos','result_photos','inspection']);assert.deepEqual(requests.map(r=>r.version),['1','2','3','4']);assert.equal(requests[3].mode,'final');assert.equal(navigation,'/final');assert.equal(state.version,5);assert.equal(state.evidence[11].files.length,0);
 state=inspection();requests=[];version=1;navigation='';
 UI.request=async(url,data)=>{requests.push(data.get('watch_action'));if(data.get('result_id')==='12')throw new Error('Unggahan butir kedua gagal');return {document:{version:++version,photos:[]}};};
 await state.run('draft');assert.equal(state.version,3);assert.equal(state.evidence[11].files.length,0);assert.equal(state.evidence[12].files.length,1);assert.match(state.error,/Draf pemeriksaan tersimpan/);assert.equal(navigation,'');assert.equal(state.busy,false);
 // Retry sends only the pending file and uses the updated version.
 requests=[];UI.request=async(url,data)=>{requests.push(data.get('result_id'));return {document:{version:++version,photos:[]}};};
 await state.run('draft');assert.deepEqual(requests,[undefined,'12']);assert.equal(state.evidence[12].files.length,0);
 state=inspection();let resolve;let count=0;
 UI.request=async()=>{count++;await new Promise(r=>resolve=r);return {document:{version:2,photos:[]}};};
 state.evidence[11].files=[];state.evidence[12].files=[];
 const pending=state.run('draft');await state.run('draft');assert.equal(count,1);resolve();await pending;
 state=inspection();UI.request=async()=>{throw new Error('Data berubah pada sesi lain. Muat ulang sebelum melanjutkan.');};await state.run('draft');assert.equal(state.version,1);assert.equal(state.evidence[11].files.length,1);assert.match(state.error,/Data berubah/);
 state=inspection();count=0;UI.request=async()=>{count++;throw new Error('Koneksi terputus.');};await state.run('draft');await state.run('draft');assert.equal(count,1,'lost response cannot blindly repeat uploads');assert.equal(state.uncertain,true);
 let root={dataset:{write:'0',csrf:'csrf'},isConnected:true};let page=Object.assign(providers.inventoryPage(),{$el:root});count=0;UI.request=async()=>{count++;return {generated:0,more:false}};await page.sync();assert.equal(count,0);
 root.dataset={write:'1',csrf:'csrf',base:'/plugin',list:'0'};UI.request=async(url,data)=>{assert.equal(data.get('csrf_token'),'csrf');count++;return {generated:count===1?50:2,more:count===1}};await page.sync();assert.equal(count,2);assert.equal(context.window.inventoryWatchSyncBusy,false);
 console.log('ok serial draft/photo/final, version propagation, partial failure/retry, duplicate guard, conflict preservation, uncertain response, read-only guard, batched sync');
})().catch(e=>{console.error(e);process.exitCode=1;});
