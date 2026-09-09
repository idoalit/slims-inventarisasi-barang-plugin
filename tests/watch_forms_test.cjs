const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../src/WatchView.php'),'utf8');
const script=source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?=json_encode[\s\S]*?\?>/g,'{}');
class Data{constructor(form){this.values=new Map(Object.entries(form?.fields||{}));}set(k,v){this.values.set(k,v);}get(k){return this.values.get(k);}[Symbol.iterator](){return this.values[Symbol.iterator]();}}
const makeRoot=(write='0')=>({dataset:{write,base:'/plugin',csrf:'csrf'},isConnected:true,querySelector:()=>({textContent:'',append(){}})});
const event=(name='submit_mode',value='final')=>({preventDefault(){},stopImmediatePropagation(){},submitter:{name,value}});
(async()=>{
 let requests=0;let navigation='';let root=makeRoot();let resolve;
 const context={window:{},document:{getElementById:()=>root,createElement:()=>({})},FormData:Data,URLSearchParams,Error,SyntaxError,confirm:()=>true,jQuery:()=>({simbioAJAX:url=>navigation=url}),fetch:async()=>{requests++;return{json:async()=>({ok:true,generated:0,more:false})}}};
 vm.createContext(context);vm.runInContext(script,context);await new Promise(r=>setImmediate(r));assert.equal(requests,0,'read-only page never posts sync');
 const error={hidden:true,textContent:''},button={disabled:false};const form={dataset:{},fields:{watch_action:'inspection',csrf_token:'csrf'},action:'/plugin',querySelector:()=>error,querySelectorAll:()=>[button]};
 context.fetch=async(url,request)=>{requests++;assert.equal(request.body.get('submit_mode'),'final');assert.equal(request.body.get('csrf_token'),'csrf');await new Promise(r=>resolve=r);return{json:async()=>({ok:true,url:'/inspection/1'})};};
 assert.equal(context.window.inventoryWatchSave(event(),form),false);assert.equal(button.disabled,true);context.window.inventoryWatchSave(event(),form);assert.equal(requests,1,'duplicate submit prevented');resolve();await new Promise(r=>setImmediate(r));assert.equal(navigation,'/inspection/1');assert.equal(button.disabled,false);
 context.fetch=async()=>({json:async()=>({ok:false,message:'Tanggal wajib diisi'})});context.window.inventoryWatchSave(event(),form);await new Promise(r=>setImmediate(r));assert.equal(error.hidden,false);assert.equal(error.textContent,'Tanggal wajib diisi');assert.equal(button.disabled,false);
 context.fetch=async()=>({json:async()=>{throw new SyntaxError('login')}});context.window.inventoryWatchSave(event(),form);await new Promise(r=>setImmediate(r));assert.match(error.textContent,/sesi/);
 root=makeRoot('1');requests=0;context.fetch=async(url,request)=>{assert.equal(request.body.get('watch_action'),'sync');assert.equal(request.body.get('csrf_token'),'csrf');requests++;return{json:async()=>({ok:true,generated:requests===1?50:2,more:requests===1})};};vm.runInContext(script,context);await new Promise(r=>setImmediate(r));await new Promise(r=>setImmediate(r));assert.equal(requests,2,'writer drains bounded catch-up batches');assert.equal(context.window.inventoryWatchSyncBusy,false);
 console.log('ok   read-only sync guard, CSRF payload, submit intent, duplicate suppression, error preservation, login response and batched synchronization');
})().catch(e=>{console.error(e);process.exitCode=1;});
